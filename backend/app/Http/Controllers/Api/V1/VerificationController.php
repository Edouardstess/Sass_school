<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Document\Services\CertificateService;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Public document verification: `GET /api/v1/verify/{code}`.
 *
 * The only unauthenticated read endpoint in the system. It answers "is this
 * document genuine" and nothing more — the holder's initials rather than their
 * name, no matricule, no marks, no address. An employer checking a certificate
 * gets what they need; a stranger with a stolen code learns nothing useful.
 *
 * Rate limited (`throttle:verification`) so it cannot become a code-guessing
 * oracle, and the codes themselves are random rather than sequential.
 */
class VerificationController extends Controller
{
    public function __construct(private readonly CertificateService $certificates) {}

    public function show(string $code): JsonResponse
    {
        $result = $this->certificates->verify($code);

        if ($result === null) {
            // Deliberately the same shape as an invalid document, so timing
            // and payload do not distinguish "no such code" from "revoked".
            return ApiResponse::error(__('certificates.not_found'), 404);
        }

        return ApiResponse::success($result);
    }
}
