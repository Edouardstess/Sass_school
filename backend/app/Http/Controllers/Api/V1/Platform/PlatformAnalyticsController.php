<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Subscription\Services\PlatformAnalytics;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The super-admin dashboard: MRR, ARR, churn, tenants and usage. */
class PlatformAnalyticsController extends Controller
{
    public function __construct(private readonly PlatformAnalytics $analytics) {}

    public function overview(Request $request): JsonResponse
    {
        abort_unless($this->user($request)->hasPermission('platform.analytics.view'), 403, __('auth.forbidden'));

        return ApiResponse::success($this->analytics->overview());
    }

    /** Cross-tenant audit trail; the platform's own accountability record. */
    public function audit(Request $request): JsonResponse
    {
        abort_unless($this->user($request)->hasPermission('platform.audit.view'), 403, __('auth.forbidden'));

        $logs = AuditLog::query()
            ->with(['user:id,first_name,last_name,email', 'school:id,name'])
            ->when($request->filled('school_id'), fn ($q) => $q->where('school_id', $request->query('school_id')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->query('action')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->query('user_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->query('to')))
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ApiResponse::paginated($logs, null);
    }
}
