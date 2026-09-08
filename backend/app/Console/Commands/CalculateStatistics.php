<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Identity\Models\User;
use App\Domain\School\Models\School;
use App\Domain\Student\Models\Student;
use App\Domain\Subscription\Models\Subscription;
use App\Domain\Subscription\Models\UsageRecord;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Records the day's usage figures per tenant.
 *
 * Written as history rather than a running total so the platform's usage
 * graphs show what actually happened, and so a plan-limit dispute can be
 * settled by looking at the day in question.
 */
class CalculateStatistics extends Command
{
    protected $signature = 'schoolflow:calculate-statistics';

    protected $description = 'Record per-tenant usage metrics for the day';

    public function handle(TenantContext $tenant): int
    {
        $recordedOn = now()->toDateString();
        $tenants = 0;

        School::query()->cursor()->each(function (School $school) use ($tenant, $recordedOn, &$tenants): void {
            $tenant->runFor($school, function () use ($school, $recordedOn, &$tenants): void {
                $subscription = Subscription::query()->where('school_id', $school->id)->live()->first();

                $metrics = [
                    'students' => Student::query()->active()->count(),
                    'users' => User::query()->where('school_id', $school->id)->count(),
                ];

                foreach ($metrics as $metric => $value) {
                    // One row per (school, metric, day): re-running the command
                    // corrects the day's figure instead of duplicating it.
                    UsageRecord::query()->updateOrCreate(
                        ['school_id' => $school->id, 'metric' => $metric, 'recorded_on' => $recordedOn],
                        ['subscription_id' => $subscription?->id, 'value' => $value],
                    );
                }

                $tenants++;
            });
        });

        $this->info("Recorded usage for {$tenants} tenant(s).");

        return self::SUCCESS;
    }
}
