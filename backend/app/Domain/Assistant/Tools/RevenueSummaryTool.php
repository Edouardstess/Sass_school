<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Contracts\AssistantTool;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Domain\Shared\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** "Combien avons-nous encaissé ce mois-ci ?" */
final class RevenueSummaryTool implements AssistantTool
{
    public function name(): string
    {
        return 'revenue_summary';
    }

    public function description(): string
    {
        return 'Summarise money actually collected over a date range, broken down by '
            .'payment method. Only confirmed payments are counted. Use this for '
            .'questions like "how much did we collect this month".';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD. Defaults to the start of this month.'],
                'to' => ['type' => 'string', 'description' => 'End date YYYY-MM-DD. Defaults to today.'],
            ],
            'required' => [],
        ];
    }

    public function authorize(User $user): bool
    {
        return $user->hasPermission('finance.view');
    }

    public function execute(User $user, array $arguments): array
    {
        $from = CarbonImmutable::parse((string) ($arguments['from'] ?? CarbonImmutable::now()->startOfMonth()->toDateString()));
        $to = CarbonImmutable::parse((string) ($arguments['to'] ?? CarbonImmutable::now()->toDateString()));

        $currency = $user->school->currency ?? (string) config('schoolflow.currency.default');

        // Only `succeeded` counts: a pending gateway attempt is not revenue.
        $rows = Payment::query()
            ->where('status', PaymentStatus::Succeeded->value)
            ->whereBetween('paid_at', [$from->startOfDay(), $to->endOfDay()])
            ->groupBy('method')
            ->toBase()
            ->get(['method', DB::raw('count(*) as count'), DB::raw('sum(amount_minor) as total')]);

        $total = (int) $rows->sum('total');

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total_collected' => Money::of($total, $currency)->toDecimalString().' '.$currency,
            'payment_count' => (int) $rows->sum('count'),
            'by_method' => $rows->map(fn ($row): array => [
                'method' => (string) $row->method,
                'count' => (int) $row->count,
                'amount' => Money::of((int) $row->total, $currency)->toDecimalString().' '.$currency,
            ])->all(),
        ];
    }
}
