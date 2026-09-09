<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\School\Models\School;
use App\Domain\Shared\Enums\AuditAction;
use App\Domain\Shared\Enums\SubscriptionStatus;
use App\Domain\Subscription\Models\Plan;
use App\Domain\Subscription\Models\Subscription;
use App\Domain\Subscription\Services\PlatformAnalytics;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Tenant management for the platform operator.
 *
 * Every route here is gated on a `platform.*` permission. Note there is no
 * `Gate::before` bypass anywhere in the system, so a platform admin's reach is
 * exactly the set of permissions on their role — visible, revocable, audited.
 */
class PlatformSchoolController extends Controller
{
    public function __construct(
        private readonly PlatformAnalytics $analytics,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatform($request, 'platform.schools.view');

        $schools = School::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('search'), fn ($q) => $q->where(
                fn ($inner) => $inner->where('name', 'ilike', '%'.$request->query('search').'%')
                    ->orWhere('slug', 'ilike', '%'.$request->query('search').'%')
                    ->orWhere('email', 'ilike', '%'.$request->query('search').'%')
            ))
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $schools->getCollection()->transform(fn (School $school): array => [
            ...$this->present($school),
            ...$this->analytics->schoolMetrics($school),
        ]);

        return ApiResponse::paginated($schools, null);
    }

    /**
     * Onboard a school: the tenant, its trial subscription and its first
     * owner account, in one transaction so a half-created tenant cannot exist.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePlatform($request, 'platform.schools.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:64', 'alpha_dash', 'unique:schools,slug'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
            'currency' => ['nullable', 'string', 'size:3'],
            'locale' => ['nullable', 'string', 'max:8'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'plan_code' => ['required', 'string', 'exists:plans,code'],

            'owner_first_name' => ['required', 'string', 'max:120'],
            'owner_last_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:255'],
        ]);

        $result = DB::transaction(function () use ($data): array {
            $school = School::query()->create([
                'slug' => $data['slug'] ?? Str::slug($data['name']).'-'.Str::lower(Str::random(5)),
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'city' => $data['city'] ?? null,
                'country' => $data['country'] ?? 'HT',
                'currency' => $data['currency'] ?? 'HTG',
                'locale' => $data['locale'] ?? 'fr',
                'timezone' => $data['timezone'] ?? 'America/Port-au-Prince',
                'status' => School::STATUS_TRIAL,
            ]);

            $plan = Plan::query()->where('code', $data['plan_code'])->firstOrFail();

            Subscription::query()->create([
                'school_id' => $school->id,
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Trialing->value,
                'billing_cycle' => 'monthly',
                'trial_ends_at' => CarbonImmutable::now()->addDays($plan->trial_days),
                'current_period_start' => CarbonImmutable::now(),
                'current_period_end' => CarbonImmutable::now()->addDays($plan->trial_days),
            ]);

            // A one-time password the owner must change; it is returned once
            // and never stored in readable form.
            $temporaryPassword = Str::password(16);

            $owner = User::query()->create([
                'school_id' => $school->id,
                'first_name' => $data['owner_first_name'],
                'last_name' => $data['owner_last_name'],
                'email' => $data['owner_email'],
                'password' => Hash::make($temporaryPassword),
                'status' => User::STATUS_INVITED,
                'locale' => $school->locale,
            ]);

            $ownerRole = Role::query()->where('name', Role::SCHOOL_OWNER)->whereNull('school_id')->firstOrFail();
            $owner->roles()->attach($ownerRole->id, ['assigned_at' => now()]);
            $owner->bumpPermissionsVersion();

            return ['school' => $school, 'owner' => $owner, 'temporary_password' => $temporaryPassword];
        });

        $this->audit->log(AuditAction::Create, $result['school'], [
            'school_id' => $result['school']->id,
            'description' => "Onboarded school {$result['school']->name}",
        ]);

        return ApiResponse::created([
            'school' => $this->present($result['school']),
            'owner' => [
                'id' => $result['owner']->id,
                'email' => $result['owner']->email,
                // Shown exactly once, at creation.
                'temporary_password' => $result['temporary_password'],
            ],
        ], __('platform.school_created'));
    }

    public function show(Request $request, School $school): JsonResponse
    {
        $this->authorizePlatform($request, 'platform.schools.view');

        return ApiResponse::success([
            ...$this->present($school),
            ...$this->analytics->schoolMetrics($school),
        ]);
    }

    public function update(Request $request, School $school): JsonResponse
    {
        $this->authorizePlatform($request, 'platform.schools.manage');

        $before = $school->getOriginal();

        $school->fill($request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'city' => ['nullable', 'string', 'max:120'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'locale' => ['sometimes', 'string', 'max:8'],
            'timezone' => ['sometimes', 'string', 'max:64'],
        ]))->save();

        $this->audit->updated($school, $before);

        return ApiResponse::success($this->present($school), __('responses.updated'));
    }

    /** Suspending a tenant locks out its users; platform admins keep access. */
    public function suspend(Request $request, School $school): JsonResponse
    {
        $this->authorizePlatform($request, 'platform.schools.suspend');

        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        $school->forceFill([
            'status' => School::STATUS_SUSPENDED,
            'suspended_at' => now(),
            'suspension_reason' => $data['reason'],
        ])->save();

        $this->audit->log(AuditAction::Update, $school, [
            'school_id' => $school->id,
            'description' => "Suspended school {$school->name}: {$data['reason']}",
        ]);

        return ApiResponse::success($this->present($school), __('platform.school_suspended'));
    }

    public function reactivate(Request $request, School $school): JsonResponse
    {
        $this->authorizePlatform($request, 'platform.schools.suspend');

        $school->forceFill([
            'status' => School::STATUS_ACTIVE,
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();

        $this->audit->log(AuditAction::Update, $school, [
            'school_id' => $school->id,
            'description' => "Reactivated school {$school->name}",
        ]);

        return ApiResponse::success($this->present($school), __('platform.school_reactivated'));
    }

    /** @return array<string, mixed> */
    private function present(School $school): array
    {
        return [
            'id' => $school->id,
            'slug' => $school->slug,
            'name' => $school->name,
            'email' => $school->email,
            'phone' => $school->phone,
            'city' => $school->city,
            'country' => $school->country,
            'currency' => $school->currency,
            'locale' => $school->locale,
            'timezone' => $school->timezone,
            'status' => $school->status,
            'suspended_at' => $school->suspended_at?->toIso8601String(),
            'suspension_reason' => $school->suspension_reason,
            'created_at' => $school->created_at?->toIso8601String(),
        ];
    }

    private function authorizePlatform(Request $request, string $permission): void
    {
        abort_unless($this->user($request)->hasPermission($permission), 403, __('auth.forbidden'));
    }
}
