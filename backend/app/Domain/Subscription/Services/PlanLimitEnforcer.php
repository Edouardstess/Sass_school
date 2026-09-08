<?php

declare(strict_types=1);

namespace App\Domain\Subscription\Services;

use App\Domain\Identity\Models\User;
use App\Domain\School\Models\School;
use App\Domain\Shared\Exceptions\PlanLimitExceededException;
use App\Domain\Student\Models\Student;
use App\Domain\Subscription\Models\FeatureFlag;
use App\Domain\Subscription\Models\PlanFeature;
use App\Domain\Subscription\Models\Subscription;

/**
 * Enforces what a tenant's plan allows.
 *
 * Resolution order for any feature — most specific wins:
 *   1. the subscription's `feature_overrides` (the Enterprise custom-limit case)
 *   2. the plan's `plan_features` row
 *   3. the platform default in config
 *
 * No limit is a constant in code, which is the whole point: raising a ceiling
 * for one school is a row, not a release.
 */
final class PlanLimitEnforcer
{
    public function assertCanAddStudent(School $school): void
    {
        $limit = $this->limit($school, PlanFeature::MAX_STUDENTS);

        if ($limit === null) {
            return;   // unlimited
        }

        // Counted inside the caller's transaction, so two concurrent creates
        // cannot both observe the same "one seat left".
        $current = Student::query()->forTenant($school->id)->count();

        if ($current >= $limit) {
            throw new PlanLimitExceededException(
                feature: PlanFeature::MAX_STUDENTS,
                limit: $limit,
                current: $current,
                message: __('subscription.student_limit_reached', ['limit' => $limit]),
            );
        }
    }

    public function assertCanAddUser(School $school): void
    {
        $limit = $this->limit($school, PlanFeature::MAX_USERS);

        if ($limit === null) {
            return;
        }

        $current = User::query()->where('school_id', $school->id)->count();

        if ($current >= $limit) {
            throw new PlanLimitExceededException(
                feature: PlanFeature::MAX_USERS,
                limit: $limit,
                current: $current,
                message: __('subscription.user_limit_reached', ['limit' => $limit]),
            );
        }
    }

    /** Throws when a capability is not part of the tenant's plan. */
    public function assertFeatureEnabled(School $school, string $feature): void
    {
        if (! $this->featureEnabled($school, $feature)) {
            throw new PlanLimitExceededException(
                feature: $feature,
                limit: null,
                current: null,
                message: __('subscription.feature_not_available', ['feature' => $feature]),
            );
        }
    }

    /**
     * Whether a capability is on for this tenant.
     *
     * A feature_flags row is a hard kill switch and wins over the plan: it is
     * how an integration is disabled during an incident without downgrading
     * anyone's subscription.
     */
    public function featureEnabled(School $school, string $feature): bool
    {
        $flag = FeatureFlag::query()
            ->where('key', $feature)
            ->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', $school->id))
            ->orderByRaw('school_id IS NULL')   // the tenant's own row first
            ->first();

        if ($flag !== null && ! $flag->enabled) {
            return false;
        }

        $subscription = $this->subscription($school);

        if ($subscription === null) {
            return (bool) config("schoolflow.features.{$feature}", false);
        }

        $override = $subscription->feature_overrides[$feature] ?? null;

        if ($override !== null) {
            return (bool) $override;
        }

        $planFeature = $subscription->plan?->features->firstWhere('key', $feature);

        return $planFeature !== null
            ? $planFeature->enabled
            : (bool) config("schoolflow.features.{$feature}", false);
    }

    /** The numeric ceiling for a metered feature; null means unlimited. */
    public function limit(School $school, string $feature): ?int
    {
        $subscription = $this->subscription($school);

        if ($subscription === null) {
            // No subscription at all: fall back to the smallest plan's limits
            // rather than granting unlimited use.
            return match ($feature) {
                PlanFeature::MAX_STUDENTS => 50,
                PlanFeature::MAX_USERS => 5,
                default => null,
            };
        }

        if (array_key_exists($feature, $subscription->feature_overrides ?? [])) {
            $override = $subscription->feature_overrides[$feature];

            return $override === null ? null : (int) $override;
        }

        $planFeature = $subscription->plan?->features->firstWhere('key', $feature);

        return $planFeature?->limit_value;
    }

    /**
     * Current usage against every metered limit — what the settings screen and
     * the upgrade prompt render.
     *
     * @return array<string, array{used: int, limit: int|null, remaining: int|null}>
     */
    public function usage(School $school): array
    {
        $metrics = [
            PlanFeature::MAX_STUDENTS => Student::query()->forTenant($school->id)->count(),
            PlanFeature::MAX_USERS => User::query()->where('school_id', $school->id)->count(),
        ];

        $usage = [];

        foreach ($metrics as $feature => $used) {
            $limit = $this->limit($school, $feature);

            $usage[$feature] = [
                'used' => $used,
                'limit' => $limit,
                'remaining' => $limit === null ? null : max(0, $limit - $used),
            ];
        }

        return $usage;
    }

    private function subscription(School $school): ?Subscription
    {
        return Subscription::query()
            ->with('plan.features')
            ->where('school_id', $school->id)
            ->live()
            ->first();
    }
}
