<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assessments and the marks recorded against them.
 *
 * `grades.score` is stored on the assessment's own `max_score` scale; the
 * calculator normalises to the year's grading scale before weighting, so a
 * quiz marked out of 20 and an exam marked out of 100 combine correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignUuid('grade_period_id')->constrained('grade_periods')->cascadeOnDelete();
            $table->foreignUuid('class_subject_id')->constrained('class_subjects')->cascadeOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title', 160);
            // homework | quiz | exam | project | participation
            $table->string('type', 24)->default('quiz');
            $table->decimal('max_score', 6, 2)->default(100);
            // Weight of this assessment inside its subject for the period.
            $table->decimal('weight', 5, 2)->default(1);
            $table->date('assessed_on');
            $table->text('description')->nullable();

            // draft | published | locked — locked freezes the marks.
            $table->string('status', 24)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->foreignUuid('locked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'grade_period_id']);
            $table->index(['class_subject_id', 'status']);
        });

        DB::statement('ALTER TABLE assessments ADD CONSTRAINT assessments_max_score_positive CHECK (max_score > 0)');
        DB::statement('ALTER TABLE assessments ADD CONSTRAINT assessments_weight_positive CHECK (weight > 0)');

        Schema::create('grades', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            // NULL score + is_absent marks a missed assessment, which is not
            // the same as a zero and must not drag the average down.
            $table->decimal('score', 6, 2)->nullable();
            $table->boolean('is_absent')->default(false);
            $table->boolean('is_excused')->default(false);
            $table->string('comment', 500)->nullable();

            $table->timestamps();

            $table->unique(['assessment_id', 'student_id']);
            $table->index(['school_id', 'student_id']);
        });

        DB::statement('ALTER TABLE grades ADD CONSTRAINT grades_score_non_negative CHECK (score IS NULL OR score >= 0)');

        // Immutable trail of every mark change, required by "modification
        // contrôlée" — who changed what, from what, to what, and why.
        Schema::create('grade_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('grade_id')->constrained('grades')->cascadeOnDelete();
            $table->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('old_score', 6, 2)->nullable();
            $table->decimal('new_score', 6, 2)->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('grade_id');
        });

        // Per-period, per-subject averages plus the overall average and rank.
        // Materialised because report cards, dashboards and rankings all read
        // it, and recomputing on every read would not survive 700 students.
        Schema::create('report_cards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignUuid('grade_period_id')->constrained('grade_periods')->cascadeOnDelete();
            $table->foreignUuid('school_class_id')->constrained('school_classes')->cascadeOnDelete();

            $table->decimal('average', 6, 2)->nullable();
            $table->unsignedInteger('rank')->nullable();
            $table->unsignedInteger('class_size')->nullable();
            $table->decimal('class_average', 6, 2)->nullable();
            $table->unsignedInteger('absences_count')->default(0);
            $table->unsignedInteger('late_count')->default(0);
            $table->text('remarks')->nullable();

            // draft | published — only a published card is visible to parents.
            $table->string('status', 24)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('computed_at')->nullable();
            // Points at the rendered PDF in `documents`, once generated.
            $table->foreignUuid('document_id')->nullable();

            $table->timestamps();

            $table->unique(['student_id', 'grade_period_id']);
            $table->index(['school_id', 'school_class_id', 'grade_period_id']);
        });

        // One line per subject on a report card.
        Schema::create('report_card_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('report_card_id')->constrained('report_cards')->cascadeOnDelete();
            $table->foreignUuid('subject_id')->constrained('subjects')->cascadeOnDelete();

            $table->string('subject_name', 120);
            $table->decimal('coefficient', 5, 2);
            $table->decimal('average', 6, 2)->nullable();
            $table->decimal('class_average', 6, 2)->nullable();
            $table->decimal('min_score', 6, 2)->nullable();
            $table->decimal('max_score', 6, 2)->nullable();
            $table->unsignedInteger('rank')->nullable();
            $table->string('appreciation', 200)->nullable();
            $table->string('teacher_name', 200)->nullable();

            $table->timestamps();

            $table->unique(['report_card_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_card_lines');
        Schema::dropIfExists('report_cards');
        Schema::dropIfExists('grade_revisions');
        Schema::dropIfExists('grades');
        Schema::dropIfExists('assessments');
    }
};
