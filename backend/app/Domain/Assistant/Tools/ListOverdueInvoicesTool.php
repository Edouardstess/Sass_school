<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Contracts\AssistantTool;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\ValueObjects\Money;
use Carbon\CarbonImmutable;

/** "Quels sont les impayés de plus de 30 jours ?" */
final class ListOverdueInvoicesTool implements AssistantTool
{
    public function name(): string
    {
        return 'list_overdue_invoices';
    }

    public function description(): string
    {
        return 'List invoices past their due date, optionally only those overdue by at '
            .'least N days. Returns the total amount outstanding and up to 50 invoices. '
            .'Use this for questions about unpaid fees and receivables.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'minimum_days_overdue' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
            ],
            'required' => [],
        ];
    }

    public function authorize(User $user): bool
    {
        return $user->hasAnyPermission(['finance.view', 'invoices.view']);
    }

    public function execute(User $user, array $arguments): array
    {
        $minDays = max(0, (int) ($arguments['minimum_days_overdue'] ?? 0));
        $limit = min((int) ($arguments['limit'] ?? 20), 50);
        $cutoff = CarbonImmutable::now()->subDays($minDays)->toDateString();

        $query = Invoice::query()
            ->outstanding()
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<=', $cutoff);

        $total = (clone $query)->sum('balance_minor');
        $count = (clone $query)->count();

        $invoices = $query
            ->with('student:id,first_name,last_name,matricule')
            ->orderBy('due_on')
            ->limit($limit)
            ->get();

        $currency = $user->school->currency ?? (string) config('schoolflow.currency.default');

        return [
            'minimum_days_overdue' => $minDays,
            'invoice_count' => $count,
            'total_outstanding' => Money::of((int) $total, $currency)->toDecimalString().' '.$currency,
            'invoices' => $invoices->map(fn (Invoice $i): array => [
                'number' => $i->number,
                'student' => $i->student?->full_name,
                'matricule' => $i->student?->matricule,
                'due_on' => $i->due_on?->toDateString(),
                'days_overdue' => $i->daysOverdue(),
                'balance' => $i->balance()->toDecimalString().' '.$i->currency,
                'status' => $i->status->value,
            ])->all(),
        ];
    }
}
