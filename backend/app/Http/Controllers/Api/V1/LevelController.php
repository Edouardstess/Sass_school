<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Academic\Models\Level;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Shared\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Grade levels. Ordered by `sequence`, which drives promotion. */
class LevelController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Level::class);

        return ApiResponse::success(
            Level::query()->withCount('classes')->orderBy('sequence')->get()
                ->map(fn (Level $level): array => $this->present($level))->all()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Level::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'code' => ['nullable', 'string', 'max:20'],
            'sequence' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $level = Level::query()->create($data);
        $this->audit->created($level);

        return ApiResponse::created($this->present($level), __('responses.created'));
    }

    public function update(Request $request, Level $level): JsonResponse
    {
        $this->authorize('update', $level);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'code' => ['nullable', 'string', 'max:20'],
            'sequence' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $before = $level->getOriginal();
        $level->fill($data)->save();
        $this->audit->updated($level, $before);

        return ApiResponse::success($this->present($level), __('responses.updated'));
    }

    public function destroy(Level $level): JsonResponse
    {
        $this->authorize('delete', $level);

        // Deleting a level with classes would orphan them; the FK is
        // RESTRICT, so refuse with a message the user can act on.
        if ($level->classes()->exists()) {
            throw new DomainException(__('academics.level_in_use', ['name' => $level->name]));
        }

        $level->delete();
        $this->audit->deleted($level);

        return ApiResponse::noContent();
    }

    /** @return array<string, mixed> */
    private function present(Level $level): array
    {
        return [
            'id' => $level->id,
            'name' => $level->name,
            'code' => $level->code,
            'sequence' => $level->sequence,
            'classes_count' => $level->classes_count ?? null,
        ];
    }
}
