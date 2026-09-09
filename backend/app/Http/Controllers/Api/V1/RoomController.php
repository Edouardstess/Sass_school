<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Academic\Models\Room;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Room::class);

        return ApiResponse::success(
            Room::query()->orderBy('name')->get()
                ->map(fn (Room $r): array => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'building' => $r->building,
                    'capacity' => $r->capacity,
                    'is_active' => $r->is_active,
                ])->all()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Room::class);

        $room = Room::query()->create($request->validate([
            'name' => ['required', 'string', 'max:80'],
            'building' => ['nullable', 'string', 'max:80'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]));

        return ApiResponse::created(['id' => $room->id, 'name' => $room->name], __('responses.created'));
    }

    public function update(Request $request, Room $room): JsonResponse
    {
        $this->authorize('update', $room);

        $room->fill($request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'building' => ['nullable', 'string', 'max:80'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['boolean'],
        ]))->save();

        return ApiResponse::success(['id' => $room->id, 'name' => $room->name], __('responses.updated'));
    }

    public function destroy(Room $room): JsonResponse
    {
        $this->authorize('delete', $room);

        $room->forceFill(['is_active' => false])->save();

        return ApiResponse::noContent();
    }
}
