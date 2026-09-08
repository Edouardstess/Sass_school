<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Finance\Models\Invoice;
use App\Domain\School\Models\School;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\SendPaymentReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\LazyCollection;

/**
 * Queues payment reminders at the configured offsets (J-7, J-3, J-1, J+1, J+7).
 *
 * Runs hourly but only acts during the school's own morning: reminders are
 * sent in the tenant's timezone, so a Port-au-Prince school does not wake
 * parents at 4am because the server runs on UTC.
 *
 * Safe to re-run. Each reminder carries a dedupe key of
 * (invoice, offset, recipient), so a repeated sweep produces no repeated
 * messages — which is what makes recovering from a failed run trivial.
 */
class SendPaymentReminders extends Command
{
    protected $signature = 'schoolflow:send-payment-reminders
                            {--school= : Limit to one school (by id or slug)}
                            {--force : Ignore the send-hour window}
                            {--dry-run : Report what would be queued without queueing it}';

    protected $description = 'Queue payment reminders for invoices approaching or past their due date';

    public function handle(TenantContext $tenant): int
    {
        $offsets = (array) config('schoolflow.reminders.offsets', [-7, -3, -1, 1, 7]);
        $sendHour = (int) config('schoolflow.reminders.send_hour', 9);
        $dryRun = (bool) $this->option('dry-run');

        $queued = 0;

        $this->schools()->each(function (School $school) use (
            $tenant, $offsets, $sendHour, $dryRun, &$queued
        ): void {
            $localNow = CarbonImmutable::now($school->timezone ?: 'UTC');

            if (! $this->option('force') && $localNow->hour !== $sendHour) {
                return;
            }

            $tenant->runFor($school, function () use ($school, $localNow, $offsets, $dryRun, &$queued): void {
                foreach ($offsets as $offset) {
                    // offset -7 → invoices due in 7 days; +7 → due 7 days ago.
                    $targetDate = $localNow->subDays((int) $offset)->toDateString();

                    Invoice::query()
                        ->outstanding()
                        ->whereDate('due_on', $targetDate)
                        ->select(['id'])
                        ->chunkById(200, function ($invoices) use ($school, $offset, $dryRun, &$queued): void {
                            foreach ($invoices as $invoice) {
                                if (! $dryRun) {
                                    SendPaymentReminder::dispatch($invoice->id, $school->id, (int) $offset);
                                }

                                $queued++;
                            }
                        });
                }
            });
        });

        $this->info(sprintf(
            '%s %d payment reminder%s.',
            $dryRun ? 'Would queue' : 'Queued',
            $queued,
            $queued === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }

    /** @return LazyCollection<int, School> */
    private function schools()
    {
        return School::query()
            ->whereIn('status', [School::STATUS_ACTIVE, School::STATUS_TRIAL])
            ->when($this->option('school'), function ($query, string $school): void {
                $query->where(fn ($q) => $q->where('id', $school)->orWhere('slug', $school));
            })
            ->cursor();
    }
}
