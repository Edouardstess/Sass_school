<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Finance\Models\Invoice;
use App\Domain\Notification\Services\NotificationDispatcher;
use App\Jobs\Concerns\RunsInTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One reminder for one invoice at one offset (J-7, J-3, J-1, J+1, J+7).
 *
 * The dedupe key embeds the offset, so the daily sweep can run as often as it
 * likes: a guardian receives each reminder exactly once, and a re-run after a
 * failure resumes rather than duplicating.
 */
class SendPaymentReminder implements ShouldQueue
{
    use Queueable, RunsInTenant;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly string $invoiceId,
        public readonly string $schoolId,
        /** Days relative to the due date; negative is before. */
        public readonly int $offsetDays,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(NotificationDispatcher $dispatcher): void
    {
        $this->inTenant($this->schoolId, function () use ($dispatcher): void {
            $invoice = Invoice::query()
                ->with(['student.guardians.user', 'guardian.user', 'school'])
                ->find($this->invoiceId);

            // Settled or cancelled between scheduling and running: say nothing.
            if ($invoice === null || ! $invoice->status->isOutstanding()) {
                return;
            }

            $student = $invoice->student;

            if ($student === null) {
                return;
            }

            $recipients = $student->guardians
                ->map(fn ($guardian) => $guardian->user)
                ->filter()
                ->unique('id');

            foreach ($recipients as $user) {
                $dispatcher->send(
                    recipient: $user,
                    key: $this->offsetDays > 0 ? 'payment_overdue' : 'payment_reminder',
                    variables: [
                        'student_name' => $student->full_name,
                        'invoice_number' => $invoice->number,
                        'amount' => $invoice->total()->format(),
                        'balance' => $invoice->balance()->format(),
                        'due_date' => $invoice->due_on?->format('d/m/Y') ?? '—',
                        'school_name' => (string) $invoice->school?->name,
                        'days' => (string) abs($this->offsetDays),
                    ],
                    subject: $invoice,
                    dedupeKey: "reminder:{$invoice->id}:{$this->offsetDays}:{$user->id}",
                    priority: $this->offsetDays > 0 ? 'high' : 'normal',
                );
            }
        });
    }
}
