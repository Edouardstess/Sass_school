<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Finance\Models\Invoice;
use App\Domain\School\Models\School;
use App\Domain\Shared\Enums\InvoiceStatus;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Flips issued and partially-paid invoices to `overdue` once their due date
 * has passed, in the school's own timezone.
 *
 * The status is stored rather than derived on read so receivables reports,
 * dashboards and the reminder sweep all agree on what "overdue" means at a
 * given moment, instead of each recomputing it against its own clock.
 */
class CheckOverdueInvoices extends Command
{
    protected $signature = 'schoolflow:check-overdue-invoices {--school=}';

    protected $description = 'Mark outstanding invoices whose due date has passed as overdue';

    public function handle(TenantContext $tenant): int
    {
        $total = 0;

        School::query()
            ->whereIn('status', [School::STATUS_ACTIVE, School::STATUS_TRIAL])
            ->when($this->option('school'), fn ($q, $s) => $q->where('id', $s)->orWhere('slug', $s))
            ->cursor()
            ->each(function (School $school) use ($tenant, &$total): void {
                $tenant->runFor($school, function () use ($school, &$total): void {
                    $today = now($school->timezone ?: 'UTC')->toDateString();

                    $updated = Invoice::query()
                        ->whereIn('status', [
                            InvoiceStatus::Issued->value,
                            InvoiceStatus::PartiallyPaid->value,
                        ])
                        ->whereNotNull('due_on')
                        ->whereDate('due_on', '<', $today)
                        ->update(['status' => InvoiceStatus::Overdue->value, 'updated_at' => now()]);

                    $total += $updated;
                });
            });

        $this->info("Marked {$total} invoice(s) overdue.");

        return self::SUCCESS;
    }
}
