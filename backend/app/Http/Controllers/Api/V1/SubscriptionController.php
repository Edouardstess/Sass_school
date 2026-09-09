<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\School\Models\School;
use App\Domain\Subscription\Models\Plan;
use App\Domain\Subscription\Models\Subscription;
use App\Domain\Subscription\Services\PlanLimitEnforcer;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The tenant's own view of its plan, limits and current usage. */
class SubscriptionController extends Controller
{
    public function __construct(
        private readonly PlanLimitEnforcer $limits,
        private readonly TenantContext $tenant,
    ) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless(
            $this->user($request)->hasAnyPermission(['school.subscription.manage', 'school.view']),
            403,
            __('auth.forbidden'),
        );

        $school = $this->tenant->schoolOrFail();

        $subscription = Subscription::query()
            ->with('plan.features')
            ->where('school_id', $school->id)
            ->live()
            ->first();

        return ApiResponse::success([
            'subscription' => $subscription === null ? null : [
                'id' => $subscription->id,
                'status' => $subscription->status->value,
                'billing_cycle' => $subscription->billing_cycle,
                'is_on_trial' => $subscription->isOnTrial(),
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'plan' => [
                    'code' => $subscription->plan?->code,
                    'name' => $subscription->plan?->name,
                    'price' => $subscription->plan?->priceFor($subscription->billing_cycle)->jsonSerialize(),
                ],
            ],
            // What the tenant is allowed and where they currently stand — the
            // data the upgrade prompt is built from.
            'usage' => $this->limits->usage($school),
            'features' => $this->featureMatrix($school),
        ]);
    }

    /** The publicly purchasable plans, for the upgrade screen. */
    public function plans(): JsonResponse
    {
        return ApiResponse::success(
            Plan::query()->public()->with('features')->orderBy('sort_order')->get()
                ->map(fn (Plan $plan): array => [
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'price_monthly' => $plan->monthlyPrice()->jsonSerialize(),
                    'price_yearly' => $plan->yearlyPrice()->jsonSerialize(),
                    'trial_days' => $plan->trial_days,
                    'limits' => $plan->features
                        ->where('type', 'limit')
                        ->mapWithKeys(fn ($f): array => [$f->key => $f->limit_value])
                        ->all(),
                    'features' => $plan->features
                        ->where('type', 'boolean')
                        ->mapWithKeys(fn ($f): array => [$f->key => $f->enabled])
                        ->all(),
                ])->all()
        );
    }

    /** @return array<string, bool> */
    private function featureMatrix(School $school): array
    {
        $keys = array_keys((array) config('schoolflow.features', []));

        return collect($keys)
            ->mapWithKeys(fn (string $key): array => [$key => $this->limits->featureEnabled($school, $key)])
            ->all();
    }
}
