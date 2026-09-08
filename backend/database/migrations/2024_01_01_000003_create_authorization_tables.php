<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC tables.
 *
 * Permissions are global verbs (`students.create`). Roles are templates that
 * bundle them. `user_permissions` grants or revokes a single permission for a
 * single user, which is how "manage permissions individually" is satisfied
 * without inventing one-off roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();   // e.g. students.create
            $table->string('group', 60);             // e.g. students
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index('group');
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // System roles (school_id NULL) ship with the product and are the
            // same everywhere. A school may additionally define its own roles.
            $table->foreignUuid('school_id')->nullable()->constrained('schools')->cascadeOnDelete();

            $table->string('name', 100);
            $table->string('label');
            $table->string('description')->nullable();

            // Platform-scope roles may act outside any tenant.
            $table->boolean('is_platform_role')->default(false);
            // System roles cannot be renamed or deleted by tenants.
            $table->boolean('is_system')->default(false);

            $table->timestamps();

            $table->unique(['school_id', 'name']);
        });

        Schema::create('role_permission', function (Blueprint $table): void {
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('user_role', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->foreignUuid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('user_permission', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained('permissions')->cascadeOnDelete();
            // true = grant on top of roles, false = revoke despite roles.
            $table->boolean('granted')->default(true);
            $table->foreignUuid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'permission_id']);
        });

        // Every authentication attempt, successful or not. Feeds the
        // brute-force lockout and the security section of the audit trail.
        Schema::create('login_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('school_id')->nullable()->constrained('schools')->cascadeOnDelete();
            $table->string('email');
            $table->string('ip_address', 45);
            $table->text('user_agent')->nullable();
            $table->boolean('successful');
            // invalid_credentials | account_disabled | school_suspended |
            // two_factor_required | two_factor_failed | throttled
            $table->string('failure_reason', 60)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['email', 'created_at']);
            $table->index(['ip_address', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('user_permission');
        Schema::dropIfExists('user_role');
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
