<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

use App\Domain\Finance\Models\NumberSequence;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mints sequential, per-tenant document numbers: INV-2025-000042.
 *
 * Correctness under concurrency is the whole point. The counter row is read
 * with `lockForUpdate()` inside a transaction, so two accountants issuing an
 * invoice at the same instant serialise on the row rather than both reading
 * 41 and both writing 42.
 */
final class NumberGenerator
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  string  $scope  invoice | receipt | payment | refund | matricule | …
     * @param  string|null  $period  defaults to the current year, or "all" for
     *                               scopes configured as non-periodic
     */
    public function next(string $scope, ?string $period = null, ?string $schoolId = null): string
    {
        $config = config("schoolflow.numbering.{$scope}");

        if ($config === null) {
            throw new RuntimeException("No numbering configuration for scope [{$scope}].");
        }

        $schoolId ??= $this->tenant->idOrFail();
        $period ??= $config['period'] === 'year' ? (string) now()->year : 'all';

        $value = $this->nextValue($schoolId, $scope, $period);

        return sprintf(
            '%s-%s-%s',
            $config['prefix'],
            $period,
            str_pad((string) $value, (int) $config['padding'], '0', STR_PAD_LEFT),
        );
    }

    /**
     * Reserve and return the next integer in the sequence.
     *
     * `lockForUpdate` on an existing row serialises concurrent callers. The
     * first-insert race is handled by catching the unique violation and
     * re-reading, rather than by hoping two requests never arrive in the same
     * millisecond.
     */
    private function nextValue(string $schoolId, string $scope, string $period): int
    {
        return DB::transaction(function () use ($schoolId, $scope, $period): int {
            $sequence = $this->lockSequence($schoolId, $scope, $period);

            if ($sequence === null) {
                try {
                    $sequence = new NumberSequence;
                    $sequence->forceFill([
                        'school_id' => $schoolId,
                        'scope' => $scope,
                        'period' => $period,
                        'next_value' => 1,
                    ])->save();
                } catch (UniqueConstraintViolationException) {
                    $sequence = $this->lockSequence($schoolId, $scope, $period);
                }
            }

            if ($sequence === null) {
                throw new RuntimeException("Unable to reserve a number for scope [{$scope}].");
            }

            $value = $sequence->next_value;
            $sequence->forceFill(['next_value' => $value + 1])->save();

            return $value;
        });
    }

    private function lockSequence(string $schoolId, string $scope, string $period): ?NumberSequence
    {
        return NumberSequence::query()
            ->withoutTenantScope()
            ->where('school_id', $schoolId)
            ->where('scope', $scope)
            ->where('period', $period)
            ->lockForUpdate()
            ->first();
    }

    /**
     * A student matricule, using the same counter machinery but allowing a
     * school-specific prefix.
     */
    public function matricule(?string $schoolId = null, ?string $prefix = null): string
    {
        $number = $this->next('matricule', null, $schoolId);

        return $prefix === null
            ? $number
            : (preg_replace('/^[A-Z]+/', $prefix, $number) ?? $number);
    }

    /**
     * A random, non-sequential verification code for certificates.
     *
     * Deliberately not drawn from a sequence: holding one code must not let
     * you guess another, and must not reveal how many the school has issued.
     * The alphabet excludes I/O/0/1 so codes survive being read aloud.
     */
    public function verificationCode(int $length = 16): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }
}
