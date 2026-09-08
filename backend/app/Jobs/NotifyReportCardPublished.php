<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Academic\Models\ReportCard;
use App\Domain\Notification\Services\NotificationDispatcher;
use App\Jobs\Concerns\RunsInTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Tells guardians a report card is available. */
class NotifyReportCardPublished implements ShouldQueue
{
    use Queueable, RunsInTenant;

    public int $tries = 3;

    public function __construct(
        public readonly string $reportCardId,
        public readonly string $schoolId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(NotificationDispatcher $dispatcher): void
    {
        $this->inTenant($this->schoolId, function () use ($dispatcher): void {
            $card = ReportCard::query()
                ->with(['student.guardians.user', 'gradePeriod', 'school'])
                ->find($this->reportCardId);

            if ($card === null || ! $card->isPublished()) {
                return;
            }

            $student = $card->student;

            if ($student === null) {
                return;
            }

            foreach ($student->guardians as $guardian) {
                $user = $guardian->user;

                if ($user === null) {
                    continue;
                }

                $dispatcher->send(
                    recipient: $user,
                    key: 'report_card_published',
                    variables: [
                        'student_name' => $student->full_name,
                        'period_name' => (string) $card->gradePeriod?->name,
                        'average' => $card->average === null ? '—' : (string) $card->average,
                        'rank' => $card->rank === null ? '—' : (string) $card->rank,
                        'class_size' => (string) $card->class_size,
                        'school_name' => (string) $card->school?->name,
                    ],
                    subject: $card,
                    dedupeKey: "report_card:{$card->id}:{$guardian->id}",
                );
            }
        });
    }
}
