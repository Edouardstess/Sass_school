<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Academic\Models\ClassSubject;
use App\Domain\Teacher\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Teacher
 */
class TeacherResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_number' => $this->employee_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'gender' => $this->gender,
            'birth_date' => $this->birth_date?->toDateString(),
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'photo_url' => $this->photo_path,
            'specialty' => $this->specialty,
            'qualification' => $this->qualification,
            'hired_on' => $this->hired_on?->toDateString(),
            'status' => $this->status,
            'has_account' => $this->user_id !== null,
            'assignments_count' => $this->whenCounted('classSubjects'),
            'assignments' => $this->whenLoaded('classSubjects', fn (): array => $this->classSubjects
                ->map(fn (ClassSubject $cs): array => [
                    'id' => $cs->id,
                    'class' => ['id' => $cs->schoolClass?->id, 'name' => $cs->schoolClass?->name],
                    'subject' => ['id' => $cs->subject?->id, 'name' => $cs->subject?->name],
                    'coefficient' => (float) $cs->coefficient,
                ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
