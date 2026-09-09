<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Services;

use App\Domain\Assistant\Contracts\AssistantTool;
use App\Domain\Assistant\Tools\CountAbsencesTool;
use App\Domain\Assistant\Tools\FindStudentsTool;
use App\Domain\Assistant\Tools\ListOverdueInvoicesTool;
use App\Domain\Assistant\Tools\LowPerformingStudentsTool;
use App\Domain\Assistant\Tools\RevenueSummaryTool;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Exceptions\DomainException;
use Illuminate\Contracts\Container\Container;

/**
 * The complete, closed set of things the assistant can do.
 *
 * Adding a capability means writing a tool class and listing it here. There is
 * deliberately no dynamic discovery and no "run this query" escape hatch: the
 * blast radius of a prompt injection is bounded by this list.
 */
final class ToolRegistry
{
    /** @var list<class-string<AssistantTool>> */
    private const TOOLS = [
        FindStudentsTool::class,
        CountAbsencesTool::class,
        ListOverdueInvoicesTool::class,
        RevenueSummaryTool::class,
        LowPerformingStudentsTool::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * The tools this particular user may use, which is what gets advertised
     * to the model — it is never told about capabilities the caller lacks.
     *
     * @return list<AssistantTool>
     */
    public function availableTo(User $user): array
    {
        return array_values(array_filter(
            array_map(fn (string $class): AssistantTool => $this->container->make($class), self::TOOLS),
            fn (AssistantTool $tool): bool => $tool->authorize($user),
        ));
    }

    public function resolve(string $name, User $user): AssistantTool
    {
        foreach ($this->availableTo($user) as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        // Covers both "no such tool" and "not allowed for you", deliberately
        // indistinguishable so the model cannot enumerate the full tool list.
        throw new DomainException(__('assistant.unknown_tool', ['tool' => $name]));
    }

    /**
     * Tool definitions in the shape the Anthropic Messages API expects.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function definitionsFor(User $user): array
    {
        return array_map(fn (AssistantTool $tool): array => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'input_schema' => $tool->schema(),
        ], $this->availableTo($user));
    }
}
