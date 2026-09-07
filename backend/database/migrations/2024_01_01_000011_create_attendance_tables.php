<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance is recorded per student, per day, and optionally per timetable
 * slot (so a school can run daily roll-call or per-period attendance without
 * a schema change).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignUuid('school_class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignUuid('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            // NULL for a whole-day record.
            $table->foreignUuid('timetable_entry_id')->nullable()->constrained('timetable_entries')->nullOnDelete();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->date('attendance_date');
            // present | absent | late | excused
            $table->string('status', 16);
            $table->unsignedSmallInteger('minutes_late')->nullable();
            $table->string('remark', 300)->nullable();

            // Flipped once a justification is approved.
            $table->boolean('is_justified')->default(false);
            $table->timestamp('parent_notified_at')->nullable();

            $table->timestamps();

            // One record per student, per day, per slot. COALESCE-free because
            // Postgres treats NULLs as distinct in unique indexes, so the
            // partial index below handles the whole-day case explicitly.
            $table->index(['school_id', 'attendance_date']);
            $table->index(['student_id', 'attendance_date']);
            $table->index(['school_class_id', 'attendance_date', 'status']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX attendance_daily_unique
            ON attendance_records (student_id, attendance_date)
            WHERE timetable_entry_id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX attendance_slot_unique
            ON attendance_records (student_id, attendance_date, timetable_entry_id)
            WHERE timetable_entry_id IS NOT NULL
        SQL);

        DB::statement("ALTER TABLE attendance_records ADD CONSTRAINT attendance_status_valid CHECK (status IN ('present','absent','late','excused'))");

        // A guardian submits a justification; an administrator approves it.
        Schema::create('attendance_justifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('attendance_record_id')->constrained('attendance_records')->cascadeOnDelete();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('reason', 500);
            // Optional scan of a medical note, stored privately.
            $table->foreignUuid('document_id')->nullable();

            // pending | approved | rejected
            $table->string('status', 24)->default('pending');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();

            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index('attendance_record_id');
        });

        // Audit of every correction to an attendance record.
        Schema::create('attendance_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('attendance_record_id')->constrained('attendance_records')->cascadeOnDelete();
            $table->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('old_status', 16)->nullable();
            $table->string('new_status', 16);
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('attendance_record_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_revisions');
        Schema::dropIfExists('attendance_justifications');
        Schema::dropIfExists('attendance_records');
    }
};
