<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS commercial layer: plans, the features/limits they carry, tenant
 * subscriptions, measured usage and feature flags.
 *
 * Limits are rows in `plan_features`, never constants in code, so a plan can
 * be re-priced or an enterprise tenant given a bespoke ceiling without a
 * deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();       // starter | standard | professional | enterprise
            $table->string('name');
            $table->text('description')->nullable();

            // Price in minor units; never a float.
            $table->bigInteger('price_monthly_minor')->default(0);
            $table->bigInteger('price_yearly_minor')->default(0);
            $table->char('currency', 3)->default('USD');

            $table->unsignedSmallInteger('trial_days')->default(14);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            // Enterprise plans are quoted, not self-served.
            $table->boolean('is_public')->default(true);

            $table->timestamps();
        });

        Schema::create('plan_features', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('plans')->cascadeOnDelete();

            $table->string('key', 80);                  // max_students | sms | advanced_reports ...
            // limit  -> numeric ceiling in `limit_value` (null = unlimited)
            // boolean-> availability in `enabled`
            $table->string('type', 16)->default('limit');
            $table->integer('limit_value')->nullable();
            $table->boolean('enabled')->default(true);

            $table->timestamps();

            $table->unique(['plan_id', 'key']);
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained('plans')->restrictOnDelete();

            // trialing | active | past_due | suspended | cancelled | expired
            $table->string('status', 24)->default('trialing');
            $table->string('billing_cycle', 16)->default('monthly'); // monthly | yearly

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Per-tenant overrides on top of the plan's features, for the
            // Enterprise "custom limits" case. { "max_students": 5000 }
            $table->jsonb('feature_overrides')->nullable();

            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index('current_period_end');
        });

        // A tenant may hold several historical subscriptions but only one live
        // one. Enforced in the database, not merely in a service.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX subscriptions_one_live_per_school
            ON subscriptions (school_id)
            WHERE status IN ('trialing', 'active', 'past_due')
        SQL);

        Schema::create('subscription_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('key', 80);                  // base | extra_students | sms_bundle
            $table->string('label');
            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('unit_price_minor')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->timestamps();

            $table->unique(['subscription_id', 'key']);
        });

        // Point-in-time measurement of a metered feature, written by the
        // scheduler. Kept as history so usage graphs are real, not estimated.
        Schema::create('usage_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('metric', 80);               // students | users | sms_sent | api_calls
            $table->bigInteger('value');
            $table->date('recorded_on');
            $table->timestamps();

            $table->unique(['school_id', 'metric', 'recorded_on']);
            $table->index(['metric', 'recorded_on']);
        });

        // Kill switches. A NULL school_id row is the platform-wide default; a
        // row with a school_id overrides it for that tenant.
        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->nullable()->constrained('schools')->cascadeOnDelete();
            $table->string('key', 80);
            $table->boolean('enabled')->default(false);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('usage_records');
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plans');
    }
};
