<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Finance\Models\FeeType;
use App\Domain\Finance\Services\InvoiceService;
use App\Domain\School\Models\AcademicYear;
use App\Domain\School\Models\School;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Student\Models\Student;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Issues the recurring fees (monthly, termly) for every active student.
 *
 * Idempotency is the whole design here: each invoice carries a
 * `generation_key` of (year, student, period, cadence), uniquely indexed per
 * tenant. Running this twice in a month bills nobody twice — the second insert
 * simply loses the race and is skipped.
 */
class GenerateRecurringInvoices extends Command
{
    protected $signature = 'schoolflow:generate-recurring-invoices
                            {--school= : Limit to one school}
                            {--cadence=monthly : monthly|termly}
                            {--dry-run}';

    protected $description = 'Generate recurring fee invoices for active students';

    public function handle(TenantContext $tenant, InvoiceService $invoices): int
    {
        $cadence = (string) $this->option('cadence');

        if (! in_array($cadence, [FeeType::RECURRENCE_MONTHLY, FeeType::RECURRENCE_TERMLY], true)) {
            $this->error("Unsupported cadence [{$cadence}].");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $skipped = 0;

        School::query()
            ->whereIn('status', [School::STATUS_ACTIVE, School::STATUS_TRIAL])
            ->when($this->option('school'), fn ($q, $s) => $q->where('id', $s)->orWhere('slug', $s))
            ->cursor()
            ->each(function (School $school) use ($tenant, $invoices, $cadence, $dryRun, &$created, &$skipped): void {
                $tenant->runFor($school, function () use ($school, $invoices, $cadence, $dryRun, &$created, &$skipped): void {
                    $year = AcademicYear::query()->active()->first();

                    if ($year === null) {
                        return;   // nothing to bill against
                    }

                    $fees = FeeType::query()->active()->where('recurrence', $cadence)->get();

                    if ($fees->isEmpty()) {
                        return;
                    }

                    $now = CarbonImmutable::now($school->timezone ?: 'UTC');
                    $periodKey = $cadence === FeeType::RECURRENCE_MONTHLY
                        ? $now->format('Y-m')
                        : $now->format('Y').'-T'.(int) ceil($now->month / 4);

                    Student::query()
                        ->active()
                        ->inYear($year->id)
                        ->with(['guardians', 'enrollments' => fn ($q) => $q->where('academic_year_id', $year->id)])
                        ->chunkById(100, function ($students) use (
                            $invoices, $fees, $year, $periodKey, $cadence, $now, $dryRun, &$created, &$skipped
                        ): void {
                            foreach ($students as $student) {
                                $key = sprintf('%s:%s:%s:%s', $cadence, $year->id, $student->id, $periodKey);

                                $items = $fees->map(fn (FeeType $fee): array => [
                                    'fee_type_id' => $fee->id,
                                    'description' => $fee->name.' — '.$periodKey,
                                    'quantity' => 1,
                                    'unit_price_minor' => $fee->default_amount_minor,
                                    'discount_minor' => 0,
                                ])->all();

                                if ($dryRun) {
                                    $created++;

                                    continue;
                                }

                                try {
                                    $invoice = $invoices->create(
                                        student: $student,
                                        academicYearId: $year->id,
                                        items: $items,
                                        currency: $student->school?->currency,
                                        generationKey: $key,
                                    );

                                    $invoices->issue($invoice, $now->endOfMonth());
                                    $created++;
                                } catch (UniqueConstraintViolationException) {
                                    // Already generated for this period.
                                    $skipped++;
                                } catch (DomainException $e) {
                                    $this->warn("Skipped {$student->matricule}: {$e->getMessage()}");
                                    $skipped++;
                                }
                            }
                        });
                });
            });

        $this->info(sprintf(
            '%s %d invoice(s); skipped %d already generated.',
            $dryRun ? 'Would create' : 'Created', $created, $skipped,
        ));

        return self::SUCCESS;
    }
}
