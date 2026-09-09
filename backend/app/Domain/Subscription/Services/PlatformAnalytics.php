<?php

declare(strict_types=1);

namespace App\Domain\Subscription\Services;

use App\Domain\Identity\Models\User;
use App\Domain\School\Models\School;
use App\Domain\Shared\Enums\SubscriptionStatus;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Student\Models\Student;
use App\Domain\Subscription\Models\Subscription;
use App\Domain\Subscription\Models\UsageRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide business metrics for the super-admin dashboard.
 *
 * These deliberately query across tenants, which is why every method here is
 * reachable only through `platform.analytics.view` — there is no tenant
 * context, and none is wanted.
 *
 * MRR normalises yearly plans to a monthly figure so the number means what a
 * SaaS operator expects it to mean rather than spiking every January.
 */
final class PlatformAnalytics
{
    /** @return array<string, mixed> */
    public function overview(): array
    {
        $now = CarbonImmutable::now();
        $monthStart = $now->startOfMonth();

        $subscriptions = Subscription::query()
            ->withoutGlobalScopes()
            ->with('plan')
            ->get();

        $mrr = $this->monthlyRecurringRevenue($subscriptions);

        return [
            'schools' => [
                'total' => School::query()->count(),
                'active' => School::query()->where('status', School::STATUS_ACTIVE)->count(),
                'trial' => School::query()->where('status', School::STATUS_TRIAL)->count(),
                'suspended' => School::query()->where('status', School::STATUS_SUSPENDED)->count(),
                'new_this_month' => School::query()->where('created_at', '>=', $monthStart)->count(),
            ],
            'subscriptions' => [
                'trialing' => $subscriptions->where('status', SubscriptionStatus::Trialing)->count(),
                'active' => $subscriptions->where('status', SubscriptionStatus::Active)->count(),
                'past_due' => $subscriptions->where('status', SubscriptionStatus::PastDue)->count(),
                'cancelled' => $subscriptions->where('status', SubscriptionStatus::Cancelled)->count(),
                'by_plan' => $this->byPlan($subscriptions),
            ],
            'revenue' => [
                'mrr' => $mrr->jsonSerialize(),
                // ARR is MRR × 12 — the standard definition, stated so nobody
                // has to guess whether it means "last twelve months".
                'arr' => $mrr->multiply(12)->jsonSerialize(),
                'arpa' => $this->averageRevenuePerAccount($subscriptions, $mrr)->jsonSerialize(),
            ],
            'churn' => $this->churn(),
            'usage' => [
                'total_students' => Student::query()->withoutTenantScope()->where('status', Student::STATUS_ACTIVE)->count(),
                'total_users' => User::query()->count(),
                'students_by_month' => $this->usageByMonth('students'),
            ],
        ];
    }

    /** @param Collection<int, Subscription> $subscriptions */
    private function monthlyRecurringRevenue($subscriptions): Money
    {
        $currency = 'USD';
        $total = 0;

        foreach ($subscriptions as $subscription) {
            if (! $subscription->status->isBillable() || $subscription->plan === null) {
                continue;
            }

            $total += $subscription->plan->monthlyRecurringRevenue($subscription->billing_cycle)->minorUnits;
        }

        return Money::of($total, $currency);
    }

    /** @param Collection<int, Subscription> $subscriptions */
    private function averageRevenuePerAccount($subscriptions, Money $mrr): Money
    {
        $paying = $subscriptions->filter(fn (Subscription $s): bool => $s->status->isBillable())->count();

        return $paying === 0
            ? Money::zero($mrr->currency)
            : Money::of(intdiv($mrr->minorUnits, $paying), $mrr->currency);
    }

    /**
     * Logo churn for the trailing month: cancellations divided by the
     * subscriber base at the start of the period.
     *
     * @return array{rate: float|null, cancelled: int, base: int}
     */
    private function churn(): array
    {
        $periodStart = CarbonImmutable::now()->subMonth();

        $cancelled = Subscription::query()
            ->withoutGlobalScopes()
            ->whereIn('status', [SubscriptionStatus::Cancelled->value, SubscriptionStatus::Expired->value])
            ->where('updated_at', '>=', $periodStart)
            ->count();

        // The base is everyone who was live at the start of the window,
        // including those who have since left.
        $base = Subscription::query()
            ->withoutGlobalScopes()
            ->where('created_at', '<', $periodStart)
            ->count();

        return [
            'rate' => $base === 0 ? null : round(($cancelled / $base) * 100, 2),
            'cancelled' => $cancelled,
            'base' => $base,
        ];
    }

    /**
     * @param  Collection<int, Subscription>  $subscriptions
     * @return list<array{plan: string, count: int}>
     */
    private function byPlan($subscriptions): array
    {
        return $subscriptions
            ->filter(fn (Subscription $s): bool => $s->status->isUsable())
            ->groupBy(fn (Subscription $s): string => (string) $s->plan?->code)
            ->map(fn ($group, $plan): array => ['plan' => (string) $plan, 'count' => $group->count()])
            ->values()
            ->all();
    }

    /** @return list<array{month: string, value: int}> */
    private function usageByMonth(string $metric): array
    {
        return UsageRecord::query()
            ->where('metric', $metric)
            ->where('recorded_on', '>=', CarbonImmutable::now()->subMonths(11)->startOfMonth()->toDateString())
            ->selectRaw("to_char(recorded_on, 'YYYY-MM') as month, max(value) as value")
            ->groupBy('month')
            ->orderBy('month')
            ->toBase()
            ->get()
            ->map(fn ($row): array => ['month' => (string) $row->month, 'value' => (int) $row->value])
            ->all();
    }

    /**
     * Per-tenant rows for the platform's school list: enough to spot a school
     * in trouble without opening each one.
     *
     * @return array<string, mixed>
     */
    public function schoolMetrics(School $school): array
    {
        $subscription = Subscription::query()
            ->withoutGlobalScopes()
            ->with('plan')
            ->where('school_id', $school->id)
            ->live()
            ->first();

        return [
            'students' => Student::query()->forTenant($school->id)->where('status', Student::STATUS_ACTIVE)->count(),
            'users' => User::query()->where('school_id', $school->id)->count(),
            'plan' => $subscription?->plan?->code,
            'subscription_status' => $subscription?->status->value,
            'trial_ends_at' => $subscription?->trial_ends_at?->toDateString(),
            'period_ends_at' => $subscription?->current_period_end?->toDateString(),
            'mrr' => $subscription?->monthlyRecurringRevenue()->jsonSerialize(),
            'last_activity_at' => DB::table('audit_logs')
                ->where('school_id', $school->id)
                ->max('created_at'),
        ];
    }
}
