<?php

declare(strict_types=1);

namespace App\Domain\Academic\Models;

use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One subject row on a report card.
 *
 * Subject name, coefficient and teacher name are copied in rather than joined
 * at render time: a card issued in December must keep saying what it said in
 * December, even if the subject is renamed in March.

 *
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ReportCardLine extends BaseModel
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id', 'report_card_id', 'subject_id', 'subject_name', 'coefficient',
        'average', 'class_average', 'min_score', 'max_score', 'rank',
        'appreciation', 'teacher_name',
    ];

    protected function casts(): array
    {
        return [
            'coefficient' => 'decimal:2',
            'average' => 'decimal:2',
            'class_average' => 'decimal:2',
            'min_score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'rank' => 'integer',
        ];
    }

    /** @return BelongsTo<ReportCard, $this> */
    public function reportCard(): BelongsTo
    {
        return $this->belongsTo(ReportCard::class);
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
