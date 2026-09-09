<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\School\Models\AcademicYear;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AcademicYear
 */
class AcademicYearResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'status' => $this->status,
            'is_active' => $this->isActive(),
            'grading_scale_max' => (float) $this->grading_scale_max,
            'passing_grade' => (float) $this->passing_grade,
            'classes_count' => $this->whenCounted('classes'),
            'enrollments_count' => $this->whenCounted('enrollments'),
            'grade_periods' => $this->whenLoaded('gradePeriods', fn () => $this->gradePeriods->map(fn ($period): array => [
                'id' => $period->id,
                'name' => $period->name,
                'sequence' => $period->sequence,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
                'is_locked' => $period->is_locked,
                'weight' => (float) $period->weight,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
