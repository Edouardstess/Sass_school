<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One notification pipeline for every channel.
 *
 *   Template ──► Notification (the intent, per recipient)
 *                     └──► NotificationLog (one row per channel attempt)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // NULL school_id = platform default; a tenant row overrides it.
            $table->foreignUuid('school_id')->nullable()->constrained('schools')->cascadeOnDelete();

            $table->string('key', 80);          // payment_reminder | absence_alert | …
            $table->string('channel', 16);      // email | sms | whatsapp | in_app
            $table->string('locale', 8)->default('fr');

            $table->string('subject')->nullable();      // e-mail only
            $table->text('body');                       // {{placeholder}} syntax
            // Placeholders the template is allowed to use; the renderer rejects
            // anything else so a template cannot exfiltrate arbitrary fields.
            $table->jsonb('available_variables')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'key', 'channel', 'locale'], 'notification_templates_unique');
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('template_id')->nullable()->constrained('notification_templates')->nullOnDelete();

            $table->string('key', 80);
            $table->string('title');
            $table->text('body');
            $table->jsonb('data')->nullable();

            // Polymorphic pointer to the subject (invoice, attendance record…).
            $table->string('subject_type', 120)->nullable();
            $table->uuid('subject_id')->nullable();

            // low | normal | high
            $table->string('priority', 16)->default('normal');
            $table->timestamp('read_at')->nullable();

            // Natural key that makes fan-out jobs idempotent: re-running the
            // J-3 reminder sweep must not send a second copy.
            $table->string('dedupe_key', 190)->nullable();

            $table->timestamps();

            $table->index(['school_id', 'user_id', 'read_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->unique(['school_id', 'dedupe_key']);
        });

        Schema::create('notification_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('notification_id')->nullable()->constrained('notifications')->cascadeOnDelete();

            $table->string('channel', 16);
            $table->string('recipient', 190);          // address or phone number
            // queued | sent | delivered | failed | skipped
            $table->string('status', 24)->default('queued');
            $table->string('provider', 40)->nullable();
            $table->string('provider_message_id', 190)->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();

            $table->index(['school_id', 'channel', 'status']);
            $table->index('notification_id');
        });

        // Per-user, per-event channel opt-in/opt-out.
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('key', 80);
            $table->boolean('email')->default(true);
            $table->boolean('sms')->default(false);
            $table->boolean('whatsapp')->default(false);
            $table->boolean('in_app')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_templates');
    }
};
