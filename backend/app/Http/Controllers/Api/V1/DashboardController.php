<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reporting\Services\DashboardService;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The dashboard is role-shaped: the service picks the widget set from the
 * caller's permissions and profile, so the frontend renders whatever it is
 * given rather than deciding what a parent is allowed to see.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->dashboard->forUser($this->user($request)));
    }
}
