<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documents and verifiable certificates.
 *
 * Files live in private object storage; `path` is an object key, never a URL.
 * Downloads are always short-lived signed URLs minted after a policy check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            // student_photo | report_card | receipt | certificate | admin_document | …
            $table->string('collection', 60);
            $table->string('name');
            $table->string('path');
            $table->string('disk', 40)->default('s3');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            // SHA-256 of the stored bytes: integrity check and de-duplication.
            $table->string('checksum', 64)->nullable();

            // What this file is attached to.
            $table->string('documentable_type', 120)->nullable();
            $table->uuid('documentable_id')->nullable();

            // Whether the tenant's own users may see it, or only staff.
            $table->string('visibility', 24)->default('private');
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'collection']);
            $table->index(['documentable_type', 'documentable_id']);
            $table->index('expires_at');
        });

        Schema::create('certificates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignUuid('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->foreignUuid('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('document_id')->nullable()->constrained('documents')->nullOnDelete();

            // enrollment | attendance_attestation | completion | transcript
            $table->string('type', 40);
            $table->string('number', 40);
            // Random, non-sequential; the only thing the public route accepts.
            $table->string('verification_code', 32);

            $table->jsonb('payload')->nullable();   // frozen data as printed
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 300)->nullable();

            $table->timestamps();

            $table->unique('verification_code');
            $table->unique(['school_id', 'number']);
            $table->index(['school_id', 'student_id', 'type']);
        });

        // Bulk import runs: uploaded file, validation outcome, per-row errors.
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('entity', 40);       // students | teachers | guardians | classes
            $table->string('original_filename');
            $table->string('path');

            // uploaded | validating | validated | importing | completed | failed | cancelled
            $table->string('status', 24)->default('uploaded');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            // [{ "row": 12, "field": "email", "message": "..." }]
            $table->jsonb('errors')->nullable();
            $table->jsonb('preview')->nullable();

            $table->timestamp('validated_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['school_id', 'status']);
        });

        // Long-running exports, produced by a queue worker and downloaded later.
        Schema::create('export_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('document_id')->nullable()->constrained('documents')->nullOnDelete();

            $table->string('type', 60);         // students | financial_report | …
            $table->string('format', 10);       // csv | xlsx | pdf
            $table->jsonb('filters')->nullable();
            // queued | processing | completed | failed
            $table->string('status', 24)->default('queued');
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['school_id', 'user_id', 'status']);
        });

        // Deferred FKs, now that `documents` exists.
        Schema::table('report_cards', function (Blueprint $table): void {
            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();
        });
        Schema::table('receipts', function (Blueprint $table): void {
            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();
        });
        Schema::table('attendance_justifications', function (Blueprint $table): void {
            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_justifications', fn (Blueprint $t) => $t->dropForeign(['document_id']));
        Schema::table('receipts', fn (Blueprint $t) => $t->dropForeign(['document_id']));
        Schema::table('report_cards', fn (Blueprint $t) => $t->dropForeign(['document_id']));
        Schema::dropIfExists('export_jobs');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('documents');
    }
};
