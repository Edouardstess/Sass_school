<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\School\Models\School;
use App\Domain\School\Models\SchoolSetting;
use App\Domain\Shared\Enums\AuditAction;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The tenant's own profile and settings. */
class SchoolController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $school = $this->tenant->schoolOrFail();
        $this->authorize('view', $school);

        return ApiResponse::success($this->present($school));
    }

    public function update(Request $request): JsonResponse
    {
        $school = $this->tenant->schoolOrFail();
        $this->authorize('update', $school);

        $before = $school->getOriginal();

        $school->fill($request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'url', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'locale' => ['sometimes', 'string', 'in:fr,en,ht'],
            'timezone' => ['sometimes', 'timezone'],
            // Currency is deliberately not editable here: changing it would
            // reinterpret every stored minor-unit amount.
        ]))->save();

        $this->audit->updated($school, $before);

        return ApiResponse::success($this->present($school), __('responses.updated'));
    }

    /** Namespaced settings, grouped for the settings screen. */
    public function settings(Request $request): JsonResponse
    {
        $school = $this->tenant->schoolOrFail();
        $this->authorize('manageSettings', $school);

        $settings = SchoolSetting::query()
            ->when($request->filled('group'), fn ($q) => $q->where('group', $request->query('group')))
            ->get()
            ->groupBy('group')
            ->map(fn ($group) => $group->mapWithKeys(
                fn (SchoolSetting $setting): array => [$setting->key => $setting->typed()],
            ));

        return ApiResponse::success($settings);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $school = $this->tenant->schoolOrFail();
        $this->authorize('manageSettings', $school);

        $data = $request->validate([
            'settings' => ['required', 'array', 'max:100'],
            'settings.*.group' => ['required', 'string', 'max:60'],
            'settings.*.key' => ['required', 'string', 'max:100'],
            'settings.*.value' => ['nullable'],
            'settings.*.type' => ['nullable', 'in:string,int,bool,decimal,json'],
        ]);

        foreach ($data['settings'] as $setting) {
            SchoolSetting::query()->updateOrCreate(
                ['school_id' => $school->id, 'group' => $setting['group'], 'key' => $setting['key']],
                // Wrapped so a scalar round-trips through JSONB unambiguously.
                ['value' => ['value' => $setting['value'] ?? null], 'type' => $setting['type'] ?? 'string'],
            );
        }

        $this->audit->log(
            AuditAction::Update,
            $school,
            ['description' => 'School settings updated', 'metadata' => ['keys' => array_column($data['settings'], 'key')]],
        );

        return ApiResponse::success(null, __('responses.updated'));
    }

    /** @return array<string, mixed> */
    private function present(School $school): array
    {
        return [
            'id' => $school->id,
            'slug' => $school->slug,
            'name' => $school->name,
            'legal_name' => $school->legal_name,
            'email' => $school->email,
            'phone' => $school->phone,
            'website' => $school->website,
            'address' => [
                'line1' => $school->address_line1,
                'line2' => $school->address_line2,
                'city' => $school->city,
                'state' => $school->state,
                'postal_code' => $school->postal_code,
                'country' => $school->country,
            ],
            'logo_url' => $school->logo_path,
            'locale' => $school->locale,
            'timezone' => $school->timezone,
            'currency' => $school->currency,
            'status' => $school->status,
        ];
    }
}
