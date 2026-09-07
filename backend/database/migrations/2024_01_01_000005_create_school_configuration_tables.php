<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant configuration and the academic calendar.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Namespaced key/value settings (academic.*, finance.*, notifications.*).
        // Typed so the API can validate and the UI can render the right control.
        Schema::create('school_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('group', 60);
            $table->string('key', 100);
            $table->jsonb('value')->nullable();
            $table->string('type', 24)->default('string'); // string|int|bool|json|decimal
            $table->timestamps();

            $table->unique(['school_id', 'group', 'key']);
        });

        Schema::create('academic_years', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();

            $table->string('name', 60);                 // "2025-2026"
            $table->date('starts_on');
            $table->date('ends_on');

            // draft | active | closed | archived
            $table->string('status', 24)->default('draft');
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();

            // Grading configuration, captured per year so changing the scale
            // next year cannot retro-actively rewrite past report cards.
            $table->decimal('grading_scale_max', 6, 2)->default(100);
            $table->decimal('passing_grade', 6, 2)->default(50);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'name']);
            $table->index(['school_id', 'status']);
        });

        // Exactly one active academic year per school, enforced by the database.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX academic_years_one_active_per_school
            ON academic_years (school_id)
            WHERE status = 'active' AND deleted_at IS NULL
        SQL);

        // Terms / trimesters. Grades and report cards are always scoped to one.
        Schema::create('grade_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('academic_year_id')->constrained('academic_years')->cascadeOnDelete();

            $table->string('name', 60);                 // "Trimestre 1"
            $table->unsignedSmallInteger('sequence');
            $table->date('starts_on');
            $table->date('ends_on');

            // Once locked, teachers can no longer submit or edit grades.
            $table->boolean('is_locked')->default(false);
            $table->timestamp('locked_at')->nullable();
            $table->foreignUuid('locked_by')->nullable()->constrained('users')->nullOnDelete();

            // Share of the yearly average, e.g. 33.33 for three equal terms.
            $table->decimal('weight', 6, 2)->default(1);

            $table->timestamps();

            $table->unique(['academic_year_id', 'sequence']);
            $table->index(['school_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_periods');
        Schema::dropIfExists('academic_years');
        Schema::dropIfExists('school_settings');
    }
};
