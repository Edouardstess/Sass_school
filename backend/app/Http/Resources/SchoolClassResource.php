<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Academic\Models\ClassSubject;
use App\Domain\Academic\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SchoolClass
 */
class SchoolClassResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'section' => $this->section,
            'capacity' => $this->capacity,
            'is_active' => $this->is_active,
            'students_count' => $this->whenCounted('students_count'),
            'remaining_seats' => $this->when(
                $this->capacity !== null && $this->offsetExists('students_count'),
                fn (): int => max(0, (int) $this->capacity - (int) $this->students_count),
            ),
            'level' => $this->whenLoaded('level', fn (): array => [
                'id' => $this->level?->id,
                'name' => $this->level?->name,
            ]),
            'homeroom_teacher' => $this->whenLoaded('homeroomTeacher', fn () => $this->homeroomTeacher === null ? null : [
                'id' => $this->homeroomTeacher->id,
                'full_name' => $this->homeroomTeacher->full_name,
            ]),
            'room' => $this->whenLoaded('room', fn () => $this->room === null ? null : [
                'id' => $this->room->id,
                'name' => $this->room->name,
            ]),
            'academic_year' => $this->whenLoaded('academicYear', fn (): array => [
                'id' => $this->academicYear?->id,
                'name' => $this->academicYear?->name,
            ]),
            'subjects' => $this->whenLoaded('classSubjects', fn () => $this->classSubjects
                ->map(fn (ClassSubject $cs): array => [
                    'id' => $cs->id,
                    'subject' => ['id' => $cs->subject?->id, 'name' => $cs->subject?->name],
                    'teacher' => $cs->teacher === null ? null : [
                        'id' => $cs->teacher->id, 'full_name' => $cs->teacher->full_name,
                    ],
                    'coefficient' => (float) $cs->coefficient,
                    'weekly_hours' => $cs->weekly_hours,
                    'is_active' => $cs->is_active,
                ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
