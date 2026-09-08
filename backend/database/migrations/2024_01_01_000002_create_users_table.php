<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Users belong to exactly one school, except platform super admins whose
 * `school_id` is null. E-mail uniqueness is therefore scoped per tenant: the
 * same person may hold an account at two schools with the same address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->nullable()->constrained('schools')->cascadeOnDelete();

            $table->string('first_name', 120);
            $table->string('last_name', 120);
            $table->string('email');
            $table->string('phone', 40)->nullable();
            $table->string('avatar_path')->nullable();

            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();

            $table->string('locale', 8)->nullable();
            $table->string('timezone', 64)->nullable();

            // active | invited | disabled
            $table->string('status', 24)->default('active');

            // Two-factor authentication (optional, TOTP).
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            // Bumped whenever roles or direct permissions change; used as the
            // cache key suffix so a revocation is effective immediately.
            $table->unsignedInteger('permissions_version')->default(1);

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('password_changed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // A tenant may not have two live accounts with the same address.
            // Partial index so soft-deleted rows do not block re-creation.
            $table->index(['school_id', 'status']);
            $table->index('email');
        });

        // Case-insensitive, tenant-scoped uniqueness on non-deleted rows.
        // COALESCE lets the same expression cover platform admins (school_id NULL).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX users_school_email_unique
            ON users (COALESCE(school_id::text, 'platform'), lower(email))
            WHERE deleted_at IS NULL
        SQL);

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
