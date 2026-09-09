<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Academic\Models\Subject;
use App\Domain\Audit\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubjectController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Subject::class);

        $subjects = Subject::query()
            ->when($request->boolean('active_only', true), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return ApiResponse::success($subjects->map(fn (Subject $s): array => $this->present($s))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Subject::class);

        $subject = Subject::query()->create($request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:20'],
            'default_coefficient' => ['nullable', 'numeric', 'min:0.1', 'max:20'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]));

        $this->audit->created($subject);

        return ApiResponse::created($this->present($subject), __('responses.created'));
    }

    public function show(Subject $subject): JsonResponse
    {
        $this->authorize('view', $subject);

        return ApiResponse::success($this->present($subject));
    }

    public function update(Request $request, Subject $subject): JsonResponse
    {
        $this->authorize('update', $subject);

        $before = $subject->getOriginal();

        $subject->fill($request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:20'],
            'default_coefficient' => ['nullable', 'numeric', 'min:0.1', 'max:20'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => ['boolean'],
        ]))->save();

        $this->audit->updated($subject, $before);

        return ApiResponse::success($this->present($subject), __('responses.updated'));
    }

    /**
     * Soft delete. Grades and report card lines reference subjects, so the row
     * stays and simply leaves the active list.
     */
    public function destroy(Subject $subject): JsonResponse
    {
        $this->authorize('delete', $subject);

        $subject->forceFill(['is_active' => false])->save();
        $subject->delete();
        $this->audit->deleted($subject);

        return ApiResponse::noContent();
    }

    /** @return array<string, mixed> */
    private function present(Subject $subject): array
    {
        return [
            'id' => $subject->id,
            'name' => $subject->name,
            'code' => $subject->code,
            'default_coefficient' => (float) $subject->default_coefficient,
            'color' => $subject->color,
            'is_active' => $subject->is_active,
        ];
    }
}
