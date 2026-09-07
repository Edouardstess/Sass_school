<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admissions pipeline.
 *
 *   submitted → under_review → accepted/rejected → enrolled
 *
 * An application is filled in from a public form (no account required) and is
 * converted into a Student + Enrollment once accepted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignUuid('level_id')->constrained('levels')->restrictOnDelete();

            // Shown to the applicant so they can follow up without an account.
            $table->string('reference', 40);

            $table->string('first_name', 120);
            $table->string('last_name', 120);
            $table->string('middle_name', 120)->nullable();
            $table->string('gender', 16)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('birth_place', 160)->nullable();
            $table->string('nationality', 80)->nullable();
            $table->string('address')->nullable();
            $table->string('previous_school', 160)->nullable();

            $table->string('guardian_first_name', 120);
            $table->string('guardian_last_name', 120);
            $table->string('guardian_relationship', 40)->default('parent');
            $table->string('guardian_email')->nullable();
            $table->string('guardian_phone', 40);

            // submitted | under_review | accepted | rejected | enrolled | withdrawn
            $table->string('status', 24)->default('submitted');
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_reason')->nullable();

            // Set when the application is converted; makes conversion idempotent.
            $table->foreignUuid('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignUuid('assigned_class_id')->nullable()->constrained('school_classes')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'reference']);
            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'academic_year_id']);
        });

        // Internal review trail; never shown to the applicant.
        Schema::create('admission_comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('application_id')->constrained('admission_applications')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index('application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_comments');
        Schema::dropIfExists('admission_applications');
    }
};
