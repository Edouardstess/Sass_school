<?php

declare(strict_types=1);

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\AuditAction;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * Writes the audit trail.
 *
 * Two rules govern every method here:
 *
 *  1. Sensitive values are redacted before they are stored. Recording *that*
 *     a password changed is the point; recording the password is a liability.
 *  2. A failure to write an audit entry must never fail the business
 *     operation that triggered it — the entry is logged to the application
 *     log instead, so the event is not lost and the user's payment still
 *     goes through.
 */
final class AuditLogger
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @param array<string, mixed> $attributes */
    public function log(
        AuditAction $action,
        ?Model $resource = null,
        array $attributes = [],
    ): ?AuditLog {
        return $this->write([
            'action' => $action->value,
            'resource_type' => $resource !== null ? $resource::class : ($attributes['resource_type'] ?? null),
            'resource_id' => $resource?->getKey() ?? ($attributes['resource_id'] ?? null),
            'description' => $attributes['description'] ?? null,
            'old_values' => $this->redact($attributes['old_values'] ?? null),
            'new_values' => $this->redact($attributes['new_values'] ?? null),
            'metadata' => $this->redact($attributes['metadata'] ?? null),
            'school_id' => $attributes['school_id'] ?? $this->resolveSchoolId($resource),
            'user_id' => $attributes['user_id'] ?? Auth::id(),
        ]);
    }

    /** Records a creation, capturing the resulting attributes. */
    public function created(Model $resource, ?string $description = null): ?AuditLog
    {
        return $this->log(AuditAction::Create, $resource, [
            'description' => $description ?? 'Created '.class_basename($resource),
            'new_values' => $resource->getAttributes(),
        ]);
    }

    /** Records an update, capturing only what actually changed. */
    public function updated(Model $resource, array $before, ?string $description = null): ?AuditLog
    {
        $changed = array_keys($resource->getChanges());

        if ($changed === []) {
            return null;
        }

        return $this->log(AuditAction::Update, $resource, [
            'description' => $description ?? 'Updated '.class_basename($resource),
            'old_values' => array_intersect_key($before, array_flip($changed)),
            'new_values' => $resource->getChanges(),
        ]);
    }

    public function deleted(Model $resource, ?string $description = null): ?AuditLog
    {
        return $this->log(AuditAction::Delete, $resource, [
            'description' => $description ?? 'Deleted '.class_basename($resource),
            'old_values' => $resource->getOriginal(),
        ]);
    }

    /** Authentication events, which may occur without a tenant context. */
    public function authentication(
        AuditAction $action,
        ?User $user,
        ?string $schoolId = null,
        array $metadata = [],
    ): ?AuditLog {
        return $this->write([
            'action' => $action->value,
            'resource_type' => $user !== null ? User::class : null,
            'resource_id' => $user?->id,
            'description' => $metadata['description'] ?? null,
            'metadata' => $this->redact($metadata),
            'school_id' => $schoolId ?? $user?->school_id,
            'user_id' => $user?->id,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function write(array $row): ?AuditLog
    {
        try {
            return AuditLog::create([
                ...$row,
                'ip_address' => Request::ip(),
                'user_agent' => substr((string) Request::userAgent(), 0, 1000),
                'request_id' => Request::header('X-Request-Id') ?? request()->attributes->get('request_id'),
                'acting_as_platform_admin' => $this->tenant->isImpersonating(),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // The audit write must not take the business operation down with
            // it — but the event must not vanish either.
            Log::error('Failed to write audit log entry', [
                'exception' => $e->getMessage(),
                'entry' => $row,
            ]);

            return null;
        }
    }

    /**
     * Strip secrets from anything about to be persisted.
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $sensitive = config('schoolflow.security.redacted_keys', []);

        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitive, true)) {
                $values[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }

    private function resolveSchoolId(?Model $resource): ?string
    {
        $fromResource = $resource?->getAttribute('school_id');

        return is_string($fromResource) ? $fromResource : $this->tenant->id();
    }
}
