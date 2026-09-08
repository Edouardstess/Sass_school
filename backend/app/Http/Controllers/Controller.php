<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Page size for an index endpoint.
     *
     * Clamped to a configured ceiling so `?per_page=100000` cannot be used to
     * pull an entire tenant into one response — the "never load 10,000
     * students in one request" rule, enforced rather than documented.
     */
    protected function perPage(Request $request): int
    {
        $requested = (int) $request->integer('per_page', (int) config('schoolflow.pagination.default_per_page', 25));

        return max(1, min($requested, (int) config('schoolflow.pagination.max_per_page', 100)));
    }

    /** The current user, typed, for controllers that require authentication. */
    protected function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
