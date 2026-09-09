<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Models\NotificationPreference;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * In-app notifications.
 *
 * Everything here is scoped to the caller's own `user_id`: there is no path,
 * with any permission, to read another person's notifications.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::query()
            ->where('user_id', $this->user($request)->id)
            ->when($request->boolean('unread_only'), fn ($q) => $q->unread())
            ->latest()
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ApiResponse::paginated($notifications, null);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'count' => Notification::query()
                ->where('user_id', $this->user($request)->id)
                ->unread()
                ->count(),
        ]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $this->user($request)->id, 404);

        $notification->markRead();

        return ApiResponse::success(['id' => $notification->id, 'read_at' => $notification->read_at?->toIso8601String()]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = Notification::query()
            ->where('user_id', $this->user($request)->id)
            ->unread()
            ->update(['read_at' => now()]);

        return ApiResponse::success(['marked' => $count]);
    }

    /** Per-event channel preferences. */
    public function preferences(Request $request): JsonResponse
    {
        return ApiResponse::success(
            NotificationPreference::query()
                ->where('user_id', $this->user($request)->id)
                ->get()
                ->map(fn (NotificationPreference $p): array => [
                    'key' => $p->key,
                    'email' => $p->email,
                    'sms' => $p->sms,
                    'whatsapp' => $p->whatsapp,
                    'in_app' => $p->in_app,
                ])->all()
        );
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array', 'max:50'],
            'preferences.*.key' => ['required', 'string', 'max:80'],
            'preferences.*.email' => ['boolean'],
            'preferences.*.sms' => ['boolean'],
            'preferences.*.whatsapp' => ['boolean'],
            'preferences.*.in_app' => ['boolean'],
        ]);

        $user = $this->user($request);

        foreach ($data['preferences'] as $preference) {
            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->id, 'key' => $preference['key']],
                [
                    'school_id' => $user->school_id,
                    'email' => (bool) ($preference['email'] ?? true),
                    'sms' => (bool) ($preference['sms'] ?? false),
                    'whatsapp' => (bool) ($preference['whatsapp'] ?? false),
                    'in_app' => (bool) ($preference['in_app'] ?? true),
                ],
            );
        }

        return ApiResponse::success(null, __('responses.updated'));
    }
}
