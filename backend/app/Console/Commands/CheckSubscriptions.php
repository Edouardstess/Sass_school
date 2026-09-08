<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\School\Models\School;
use App\Domain\Shared\Enums\AuditAction;
use App\Domain\Shared\Enums\SubscriptionStatus;
use App\Domain\Subscription\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Advances subscription lifecycle state.
 *
 *   trial ended            → past_due (the tenant keeps working, briefly)
 *   period ended, active   → past_due
 *   past_due > grace       → suspended, and the school is locked out
 *
 * A grace period exists on purpose: cutting a school off from its own student
 * records the moment a card fails would be disproportionate, and the data is
 * not ours to withhold without warning.
 */
class CheckSubscriptions extends Command
{
    protected $signature = 'schoolflow:check-subscriptions {--grace-days=7} {--dry-run}';

    protected $description = 'Advance trial, renewal and suspension state on tenant subscriptions';

    public function handle(AuditLogger $audit): int
    {
        $graceDays = (int) $this->option('grace-days');
        $dryRun = (bool) $this->option('dry-run');

        $transitions = ['expired_trials' => 0, 'past_due' => 0, 'suspended' => 0];

        Subscription::query()
            ->with('school')
            ->live()
            ->cursor()
            ->each(function (Subscription $subscription) use ($graceDays, $dryRun, $audit, &$transitions): void {
                $next = $this->nextStatus($subscription, $graceDays);

                if ($next === null || $next === $subscription->status) {
                    return;
                }

                $transitions[match ($next) {
                    SubscriptionStatus::PastDue => $subscription->status === SubscriptionStatus::Trialing
                        ? 'expired_trials'
                        : 'past_due',
                    SubscriptionStatus::Suspended => 'suspended',
                    default => 'past_due',
                }]++;

                if ($dryRun) {
                    return;
                }

                DB::transaction(function () use ($subscription, $next, $audit): void {
                    $subscription->forceFill(['status' => $next->value])->save();

                    // Suspending the subscription suspends the tenant: that is
                    // what actually stops its users signing in.
                    if ($next === SubscriptionStatus::Suspended && $subscription->school !== null) {
                        $subscription->school->forceFill([
                            'status' => School::STATUS_SUSPENDED,
                            'suspended_at' => now(),
                            'suspension_reason' => 'Subscription unpaid beyond the grace period',
                        ])->save();
                    }

                    $audit->log(AuditAction::SubscriptionChange, $subscription, [
                        'school_id' => $subscription->school_id,
                        'description' => "Subscription moved to {$next->value}",
                        'new_values' => ['status' => $next->value],
                    ]);
                });
            });

        $this->table(
            ['transition', 'count'],
            collect($transitions)->map(fn (int $count, string $key): array => [$key, $count])->values()->all(),
        );

        return self::SUCCESS;
    }

    private function nextStatus(Subscription $subscription, int $graceDays): ?SubscriptionStatus
    {
        $now = now();

        if ($subscription->status === SubscriptionStatus::Trialing) {
            return $subscription->trial_ends_at !== null && $subscription->trial_ends_at->isBefore($now)
                ? SubscriptionStatus::PastDue
                : null;
        }

        if ($subscription->status === SubscriptionStatus::Active) {
            return $subscription->current_period_end !== null && $subscription->current_period_end->isBefore($now)
                ? SubscriptionStatus::PastDue
                : null;
        }

        if ($subscription->status === SubscriptionStatus::PastDue) {
            $since = $subscription->current_period_end ?? $subscription->trial_ends_at ?? $subscription->updated_at;

            return $since !== null && $since->copy()->addDays($graceDays)->isBefore($now)
                ? SubscriptionStatus::Suspended
                : null;
        }

        return null;
    }
}
