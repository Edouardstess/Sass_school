<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Contracts;

use App\Domain\Identity\Models\User;

/**
 * One thing the assistant is allowed to do.
 *
 * This interface is the security boundary. The model never receives a database
 * connection, a query builder, or a SQL string — it may only name a registered
 * tool and supply arguments matching that tool's declared schema. Each tool
 * then re-checks the caller's permissions itself, so asking the assistant for
 * something cannot obtain what the user could not fetch through the API.
 */
interface AssistantTool
{
    /** Stable identifier the model uses to call this tool. */
    public function name(): string;

    /** What the tool does, in the model's own working language. */
    public function description(): string;

    /**
     * JSON Schema for the arguments. Anything not described here is rejected
     * before the tool runs.
     *
     * @return array<string, mixed>
     */
    public function schema(): array;

    /**
     * Whether this user may run this tool at all.
     *
     * Checked before every invocation — not once at registration — so a
     * permission revoked mid-conversation takes effect immediately.
     */
    public function authorize(User $user): bool;

    /**
     * Execute with already-validated arguments.
     *
     * Implementations must return plain, already-scoped data. They must never
     * accept a table name, a column list, or a raw expression from the model.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(User $user, array $arguments): array;
}
