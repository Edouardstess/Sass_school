<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Finance\Models\FeeType;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeeTypeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FeeType::class);

        $fees = FeeType::query()
            ->when($request->boolean('active_only', true), fn ($q) => $q->active())
            ->with('level:id,name')
            ->orderBy('name')
            ->get();

        return ApiResponse::success($fees->map(fn (FeeType $f): array => $this->present($f))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', FeeType::class);

        $fee = FeeType::query()->create($this->validated($request));
        $this->audit->created($fee, "Created fee type {$fee->name}");

        return ApiResponse::created($this->present($fee), __('responses.created'));
    }

    public function update(Request $request, FeeType $feeType): JsonResponse
    {
        $this->authorize('update', $feeType);

        $before = $feeType->getOriginal();
        $feeType->fill($this->validated($request, partial: true))->save();
        $this->audit->updated($feeType, $before);

        return ApiResponse::success($this->present($feeType), __('responses.updated'));
    }

    public function destroy(FeeType $feeType): JsonResponse
    {
        $this->authorize('delete', $feeType);

        // Invoice lines denormalise the description, so deactivating never
        // rewrites an invoice that has already been issued.
        $feeType->forceFill(['is_active' => false])->save();
        $feeType->delete();
        $this->audit->deleted($feeType);

        return ApiResponse::noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:1000'],
            // Minor units: the client sends 250000 for 2 500,00 HTG, so no
            // float ever crosses the wire.
            'default_amount_minor' => [$required, 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'recurrence' => ['nullable', Rule::in([
                FeeType::RECURRENCE_ONE_TIME, FeeType::RECURRENCE_MONTHLY,
                FeeType::RECURRENCE_TERMLY, FeeType::RECURRENCE_YEARLY,
            ])],
            'level_id' => ['nullable', 'uuid'],
            'is_mandatory' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(FeeType $fee): array
    {
        return [
            'id' => $fee->id,
            'name' => $fee->name,
            'code' => $fee->code,
            'description' => $fee->description,
            'amount' => $fee->defaultAmount()->jsonSerialize(),
            'recurrence' => $fee->recurrence,
            'is_recurring' => $fee->isRecurring(),
            'is_mandatory' => $fee->is_mandatory,
            'is_active' => $fee->is_active,
            'level' => $fee->relationLoaded('level') && $fee->level !== null
                ? ['id' => $fee->level->id, 'name' => $fee->level->name]
                : null,
        ];
    }
}
