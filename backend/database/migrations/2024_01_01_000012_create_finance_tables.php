<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance.
 *
 * Every monetary column is a BIGINT of *minor units* (centimes) paired with an
 * ISO-4217 currency. No float ever touches money in this system.
 *
 * Invoice lifecycle: draft → issued → partially_paid → paid
 *                                  ↘ overdue      ↘ cancelled
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();

            $table->string('name', 120);      // Frais de scolarité, Cantine, …
            $table->string('code', 40)->nullable();
            $table->text('description')->nullable();

            $table->bigInteger('default_amount_minor')->default(0);
            $table->char('currency', 3)->default('HTG');

            // one_time | monthly | termly | yearly — drives recurring billing.
            $table->string('recurrence', 24)->default('one_time');
            // Restrict the fee to one level (e.g. exam fee for final years).
            $table->foreignUuid('level_id')->nullable()->constrained('levels')->nullOnDelete();
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'name']);
            $table->index(['school_id', 'is_active']);
        });

        DB::statement('ALTER TABLE fee_types ADD CONSTRAINT fee_types_amount_non_negative CHECK (default_amount_minor >= 0)');

        Schema::create('discounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 40)->nullable();
            // percentage | fixed
            $table->string('type', 16)->default('percentage');
            // Basis points (2500 = 25.00 %) for percentage, minor units for fixed.
            $table->integer('value');
            $table->char('currency', 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'name']);
        });

        // A named, per-student award (sibling rebate, merit bursary…).
        Schema::create('scholarships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignUuid('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name', 120);
            $table->string('type', 16)->default('percentage');   // percentage | fixed
            $table->integer('value');                            // bp or minor units
            $table->char('currency', 3)->nullable();
            $table->text('reason')->nullable();

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['school_id', 'student_id', 'is_active']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignUuid('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            // The guardian legally responsible for settling this invoice.
            $table->foreignUuid('guardian_id')->nullable()->constrained('guardians')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Sequential per tenant, e.g. INV-2025-000042.
            $table->string('number', 40);

            $table->char('currency', 3)->default('HTG');
            $table->bigInteger('subtotal_minor')->default(0);   // Σ line totals
            $table->bigInteger('discount_minor')->default(0);   // invoice-level rebates
            $table->bigInteger('total_minor')->default(0);      // subtotal − discount
            $table->bigInteger('paid_minor')->default(0);       // Σ applied payments
            $table->bigInteger('balance_minor')->default(0);    // total − paid

            // draft | issued | partially_paid | paid | overdue | cancelled
            $table->string('status', 24)->default('draft');

            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 300)->nullable();

            $table->text('notes')->nullable();
            // Set when generated by the recurring-billing scheduler; makes the
            // job idempotent (one invoice per fee type per period).
            $table->string('generation_key', 120)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'number']);
            $table->unique(['school_id', 'generation_key']);
            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'due_on']);
            $table->index(['student_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE invoices ADD CONSTRAINT invoices_amounts_non_negative
            CHECK (subtotal_minor >= 0 AND discount_minor >= 0 AND total_minor >= 0 AND paid_minor >= 0)
        SQL);
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_balance_consistent CHECK (balance_minor = total_minor - paid_minor)');

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignUuid('fee_type_id')->nullable()->constrained('fee_types')->nullOnDelete();

            // Denormalised on purpose: renaming a fee type must not rewrite
            // invoices that were already issued.
            $table->string('description', 200);
            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->char('currency', 3)->default('HTG');

            $table->timestamps();

            $table->index('invoice_id');
        });

        DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_quantity_positive CHECK (quantity > 0)');
        DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_total_consistent CHECK (total_minor = (unit_price_minor * quantity) - discount_minor)');

        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignUuid('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('reference', 60);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('HTG');

            // cash | bank_transfer | moncash | natcash | stripe | cheque
            $table->string('method', 40);
            // pending | processing | succeeded | failed | cancelled | refunded
            $table->string('status', 24)->default('pending');

            $table->timestamp('paid_at')->nullable();
            $table->string('payer_name', 160)->nullable();
            $table->string('payer_phone', 40)->nullable();
            $table->string('external_reference', 160)->nullable();
            $table->string('notes', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'reference']);
            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'paid_at']);
            $table->index('invoice_id');
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (amount_minor > 0)');

        // The provider-facing half of a payment. Kept separate so the domain
        // never has to know a gateway exists, and so a failed provider attempt
        // does not pollute the accounting record.
        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained('payments')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();

            $table->string('provider', 40);
            $table->string('provider_reference', 160)->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);

            // initiated | pending | succeeded | failed | expired | cancelled
            $table->string('status', 24)->default('initiated');
            $table->text('checkout_url')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->jsonb('provider_payload')->nullable();
            $table->string('failure_reason', 300)->nullable();

            $table->timestamps();

            $table->unique(['provider', 'provider_reference']);
            $table->index(['school_id', 'status']);
        });

        // Raw inbound webhooks. The unique key makes replay attacks and
        // provider retries harmless.
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 40);
            $table->string('external_event_id', 190);
            $table->string('event_type', 80)->nullable();
            $table->jsonb('payload');
            $table->string('signature', 512)->nullable();
            $table->string('ip_address', 45)->nullable();

            // received | processed | ignored | failed
            $table->string('status', 24)->default('received');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->timestamps();

            $table->unique(['provider', 'external_event_id']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('students')->cascadeOnDelete();

            $table->string('number', 40);       // REC-2025-000042
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->timestamp('issued_at')->useCurrent();
            $table->foreignUuid('document_id')->nullable();   // rendered PDF

            $table->timestamps();

            $table->unique(['school_id', 'number']);
            // A payment yields exactly one receipt; the constraint is what
            // makes the issuing job safely re-runnable.
            $table->unique('payment_id');
        });

        Schema::create('refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('reference', 60);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reason', 500);
            // pending | approved | processed | rejected
            $table->string('status', 24)->default('pending');
            $table->timestamp('processed_at')->nullable();
            $table->string('external_reference', 160)->nullable();

            $table->timestamps();

            $table->unique(['school_id', 'reference']);
            $table->index(['school_id', 'status']);
        });

        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive CHECK (amount_minor > 0)');

        // Per-tenant, per-year counters backing invoice/receipt/matricule
        // numbering. Incremented under a row lock so numbers never collide.
        Schema::create('number_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('scope', 40);        // invoice | receipt | payment | refund | matricule
            $table->string('period', 20);       // "2025" or "all"
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();

            $table->unique(['school_id', 'scope', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('scholarships');
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('fee_types');
    }
};
