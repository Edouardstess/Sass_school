<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Student\Models\Enrollment;
use App\Domain\Student\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Student
 */
class StudentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'matricule' => $this->matricule,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'middle_name' => $this->middle_name,
            'full_name' => $this->full_name,
            'gender' => $this->gender,
            'birth_date' => $this->birth_date?->toDateString(),
            'birth_place' => $this->birth_place,
            'nationality' => $this->nationality,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'photo_url' => $this->photo_path,
            'status' => $this->status,
            'enrolled_on' => $this->enrolled_on?->toDateString(),
            'left_on' => $this->left_on?->toDateString(),

            // Medical notes are sensitive and only surfaced to staff who can
            // act on them; a parent sees their own child's, nobody else's.
            'medical_notes' => $this->when(
                $request->user()?->hasPermission('students.update') === true,
                $this->medical_notes,
            ),
            'emergency_contact' => [
                'name' => $this->emergency_contact_name,
                'phone' => $this->emergency_contact_phone,
                'relation' => $this->emergency_contact_relation,
            ],

            'guardians' => GuardianResource::collection($this->whenLoaded('guardians')),
            'enrollments' => $this->whenLoaded(
                'enrollments',
                /** @return list<array<string, mixed>> */
                fn (): array => $this->enrollments->map(function (Enrollment $enrollment): array {
                    $class = $enrollment->schoolClass;

                    return [
                        'id' => $enrollment->id,
                        'status' => $enrollment->status->value,
                        'enrolled_on' => $enrollment->enrolled_on?->toDateString(),
                        'academic_year' => $enrollment->relationLoaded('academicYear') ? [
                            'id' => $enrollment->academicYear?->id,
                            'name' => $enrollment->academicYear?->name,
                        ] : null,
                        'class' => $class !== null && $enrollment->relationLoaded('schoolClass') ? [
                            'id' => $class->id,
                            'name' => $class->name,
                            'level' => $class->relationLoaded('level') ? $class->level?->name : null,
                        ] : null,
                    ];
                })->all(),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
