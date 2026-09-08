<?php

declare(strict_types=1);

namespace App\Domain\Academic\Services;

use App\Domain\Academic\Models\TimetableEntry;
use App\Domain\Shared\Exceptions\DomainException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Prevents the three impossible timetable states: a teacher in two rooms at
 * once, a room hosting two classes, and a class sitting two lessons.
 *
 * Overlap is tested as `existing.start < new.end AND existing.end > new.start`,
 * which is the correct half-open interval comparison: a lesson ending at 09:30
 * does not conflict with one starting at 09:30, but any genuine overlap —
 * partial, containing or contained — is caught.
 *
 * The unique index on (class, day, start) catches the exact-slot case even
 * under concurrent writes; this catches the partial overlaps a unique index
 * cannot express.
 */
final class TimetableConflictDetector
{
    /**
     * @param  string|null  $ignoreEntryId  the entry being edited, so it does
     *                                      not conflict with itself
     * @return list<array{type: string, entry_id: string, detail: string}>
     */
    public function detect(
        string $academicYearId,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
        string $schoolClassId,
        ?string $teacherId = null,
        ?string $roomId = null,
        ?string $ignoreEntryId = null,
    ): array {
        if ($endsAt <= $startsAt) {
            throw new DomainException(__('timetable.end_before_start'));
        }

        $overlapping = TimetableEntry::query()
            ->where('academic_year_id', $academicYearId)
            ->where('day_of_week', $dayOfWeek)
            ->when($ignoreEntryId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreEntryId))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->where(function (Builder $q) use ($schoolClassId, $teacherId, $roomId): void {
                $q->where('school_class_id', $schoolClassId);

                if ($teacherId !== null) {
                    $q->orWhere('teacher_id', $teacherId);
                }

                if ($roomId !== null) {
                    $q->orWhere('room_id', $roomId);
                }
            })
            ->with(['schoolClass:id,name', 'teacher:id,first_name,last_name', 'room:id,name', 'subject:id,name'])
            ->get();

        $conflicts = [];

        foreach ($overlapping as $entry) {
            $window = sprintf('%s–%s', substr((string) $entry->starts_at, 0, 5), substr((string) $entry->ends_at, 0, 5));

            if ($entry->school_class_id === $schoolClassId) {
                $conflicts[] = [
                    'type' => 'class',
                    'entry_id' => $entry->id,
                    'detail' => __('timetable.conflict_class', [
                        'class' => (string) $entry->schoolClass?->name,
                        'subject' => (string) $entry->subject?->name,
                        'window' => $window,
                    ]),
                ];
            }

            if ($teacherId !== null && $entry->teacher_id === $teacherId) {
                $conflicts[] = [
                    'type' => 'teacher',
                    'entry_id' => $entry->id,
                    'detail' => __('timetable.conflict_teacher', [
                        'teacher' => (string) $entry->teacher?->full_name,
                        'class' => (string) $entry->schoolClass?->name,
                        'window' => $window,
                    ]),
                ];
            }

            if ($roomId !== null && $entry->room_id === $roomId) {
                $conflicts[] = [
                    'type' => 'room',
                    'entry_id' => $entry->id,
                    'detail' => __('timetable.conflict_room', [
                        'room' => (string) $entry->room?->name,
                        'class' => (string) $entry->schoolClass?->name,
                        'window' => $window,
                    ]),
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Throw unless the slot is free.
     *
     * Every conflict is reported, not just the first: an administrator fixing
     * a timetable wants to know the teacher AND the room are taken, rather
     * than discovering the second problem after fixing the first.
     */
    public function assertFree(
        string $academicYearId,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
        string $schoolClassId,
        ?string $teacherId = null,
        ?string $roomId = null,
        ?string $ignoreEntryId = null,
    ): void {
        $conflicts = $this->detect(
            $academicYearId, $dayOfWeek, $startsAt, $endsAt,
            $schoolClassId, $teacherId, $roomId, $ignoreEntryId,
        );

        if ($conflicts !== []) {
            throw new DomainException(
                __('timetable.slot_unavailable'),
                ['conflicts' => $conflicts],
            );
        }
    }
}
