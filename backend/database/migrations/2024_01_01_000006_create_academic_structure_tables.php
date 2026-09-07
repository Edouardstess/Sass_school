<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The academic skeleton: levels → classes → subjects, plus physical rooms.
 *
 *   AcademicYear ──< Level ──< SchoolClass ──< ClassSubject >── Subject
 *                                                   │
 *                                                   └── Teacher
 */
return new class extends Migration
{
    public function up(): void
    {
        // A grade level ("6ème", "NS1"). Ordered so promotion can walk it.
        Schema::create('levels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('code', 20)->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'name']);
            $table->index(['school_id', 'sequence']);
        });

        Schema::create('rooms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('building', 80)->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'name']);
        });

        Schema::create('subjects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 20)->nullable();
            // Default weight; a class may override it in class_subjects.
            $table->decimal('default_coefficient', 5, 2)->default(1);
            $table->string('color', 9)->nullable();     // UI hint, e.g. #2563EB
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'name']);
        });

        Schema::create('school_classes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignUuid('level_id')->constrained('levels')->restrictOnDelete();

            $table->string('name', 80);                 // "6ème A"
            $table->string('section', 20)->nullable();  // "A"
            $table->unsignedSmallInteger('capacity')->nullable();

            $table->foreignUuid('homeroom_teacher_id')->nullable();
            $table->foreignUuid('room_id')->nullable()->constrained('rooms')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['academic_year_id', 'name']);
            $table->index(['school_id', 'academic_year_id']);
            $table->index(['school_id', 'level_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_classes');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('levels');
    }
};
