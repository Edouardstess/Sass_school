<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly timetable. Three kinds of clash must be impossible:
 * a teacher in two places, a room double-booked, and a class with two
 * simultaneous lessons. Overlap detection lives in
 * `TimetableConflictDetector`; the unique indexes here catch the exact-slot
 * case even under concurrent writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignUuid('school_class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignUuid('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignUuid('teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
            $table->foreignUuid('room_id')->nullable()->constrained('rooms')->nullOnDelete();

            // ISO-8601 day number: 1 = Monday … 7 = Sunday.
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');

            $table->timestamps();

            $table->index(['school_id', 'academic_year_id', 'day_of_week']);
            $table->index(['teacher_id', 'day_of_week']);
            $table->index(['room_id', 'day_of_week']);

            $table->unique(['school_class_id', 'day_of_week', 'starts_at'], 'timetable_class_slot_unique');
        });

        DB::statement('ALTER TABLE timetable_entries ADD CONSTRAINT timetable_entries_time_order CHECK (ends_at > starts_at)');
        DB::statement('ALTER TABLE timetable_entries ADD CONSTRAINT timetable_entries_day_range CHECK (day_of_week BETWEEN 1 AND 7)');
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_entries');
    }
};
