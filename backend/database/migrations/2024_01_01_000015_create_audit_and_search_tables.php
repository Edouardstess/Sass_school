<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail, plus the trigram indexes that power global search.
 *
 * `audit_logs` has no `updated_at` and no soft delete on purpose: entries are
 * written once and never edited. A database rule additionally rejects UPDATE
 * and DELETE from the application role, so even a compromised application
 * account cannot rewrite history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();

            // LOGIN | CREATE | UPDATE | DELETE | PAYMENT | REFUND |
            // GRADE_UPDATE | ROLE_CHANGE | DOCUMENT_DOWNLOAD | …
            $table->string('action', 60);
            $table->string('resource_type', 120)->nullable();
            $table->uuid('resource_id')->nullable();
            $table->string('description', 500)->nullable();

            // Field-level before/after, with sensitive keys already redacted
            // by AuditLogger — a password change records *that* it happened,
            // never the value.
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->jsonb('metadata')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', 64)->nullable();
            // True when a platform super admin acted inside a tenant.
            $table->boolean('acting_as_platform_admin')->default(false);

            $table->timestamp('created_at')->useCurrent();

            $table->index(['school_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['resource_type', 'resource_id']);
            $table->index(['action', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE RULE audit_logs_no_update AS
                ON UPDATE TO audit_logs DO INSTEAD NOTHING;
            CREATE OR REPLACE RULE audit_logs_no_delete AS
                ON DELETE TO audit_logs DO INSTEAD NOTHING;
        SQL);

        // Trigram indexes for the global search. `gin_trgm_ops` makes
        // `ILIKE '%term%'` index-assisted instead of a sequential scan, which
        // is what keeps search usable at a few thousand students per tenant.
        DB::statement("CREATE INDEX students_name_trgm ON students USING gin ((first_name || ' ' || last_name) gin_trgm_ops)");
        DB::statement('CREATE INDEX students_matricule_trgm ON students USING gin (matricule gin_trgm_ops)');
        DB::statement("CREATE INDEX teachers_name_trgm ON teachers USING gin ((first_name || ' ' || last_name) gin_trgm_ops)");
        DB::statement("CREATE INDEX guardians_name_trgm ON guardians USING gin ((first_name || ' ' || last_name) gin_trgm_ops)");
        DB::statement('CREATE INDEX invoices_number_trgm ON invoices USING gin (number gin_trgm_ops)');
        DB::statement('CREATE INDEX payments_reference_trgm ON payments USING gin (reference gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payments_reference_trgm');
        DB::statement('DROP INDEX IF EXISTS invoices_number_trgm');
        DB::statement('DROP INDEX IF EXISTS guardians_name_trgm');
        DB::statement('DROP INDEX IF EXISTS teachers_name_trgm');
        DB::statement('DROP INDEX IF EXISTS students_matricule_trgm');
        DB::statement('DROP INDEX IF EXISTS students_name_trgm');
        Schema::dropIfExists('audit_logs');
    }
};
