<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Assistant\Services\AssistantService;
use App\Domain\Assistant\Services\ToolRegistry;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    public function __construct(
        private readonly AssistantService $assistant,
        private readonly ToolRegistry $tools,
    ) {}

    /** What the assistant can do for this particular caller. */
    public function capabilities(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return ApiResponse::success([
            'available' => $this->assistant->isAvailable() && $user->hasPermission('assistant.use'),
            'tools' => array_map(
                fn ($tool): array => ['name' => $tool->name(), 'description' => $tool->description()],
                $this->tools->availableTo($user),
            ),
        ]);
    }

    public function ask(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:2000'],
            'history' => ['nullable', 'array', 'max:20'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.content' => ['required'],
        ]);

        $result = $this->assistant->ask(
            $this->user($request),
            $data['question'],
            $data['history'] ?? [],
        );

        return ApiResponse::success($result);
    }
}
