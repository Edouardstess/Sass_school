@extends('pdf.layout')

@section('title', __('pdf.receipt_title', ['number' => $receipt->number]))
@section('document-title', __('pdf.receipt'))
@section('document-number', $receipt->number)

@section('content')
    <h2>{{ __('pdf.payer') }}</h2>
    <table class="data">
        <tr>
            <td style="width: 30%;" class="muted">{{ __('pdf.student') }}</td>
            <td><strong>{{ $student->full_name }}</strong> — {{ $student->matricule }}</td>
        </tr>
        <tr>
            <td class="muted">{{ __('pdf.paid_by') }}</td>
            <td>{{ $payment->payer_name ?: '—' }}</td>
        </tr>
        <tr>
            <td class="muted">{{ __('pdf.payment_date') }}</td>
            <td>{{ optional($payment->paid_at)->format('d/m/Y H:i') ?? '—' }}</td>
        </tr>
        <tr>
            <td class="muted">{{ __('pdf.payment_method') }}</td>
            <td>{{ $payment->method->label() }}</td>
        </tr>
        <tr>
            <td class="muted">{{ __('pdf.reference') }}</td>
            <td>{{ $payment->reference }}</td>
        </tr>
    </table>

    <h2>{{ __('pdf.applied_to_invoice', ['number' => $invoice->number]) }}</h2>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('pdf.description') }}</th>
                <th class="num">{{ __('pdf.quantity') }}</th>
                <th class="num">{{ __('pdf.unit_price') }}</th>
                <th class="num">{{ __('pdf.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">{{ $item->unitPrice()->format() }}</td>
                    <td class="num">{{ $item->total()->format() }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="3">{{ __('pdf.invoice_total') }}</td>
                <td class="num">{{ $invoice->total()->format() }}</td>
            </tr>
        </tbody>
    </table>

    <h2>{{ __('pdf.amount_received') }}</h2>
    <table class="data">
        <tr class="total">
            <td>{{ __('pdf.amount_received') }}</td>
            <td class="num" style="font-size: 13pt; color: #065f46;">{{ $receipt->amount()->format() }}</td>
        </tr>
        <tr>
            <td class="muted">{{ __('pdf.invoice_balance') }}</td>
            <td class="num">
                {{ $invoice->balance()->format() }}
                @if ($invoice->balance()->isZero())
                    <span class="badge badge-paid">{{ __('pdf.settled') }}</span>
                @else
                    <span class="badge badge-due">{{ __('pdf.outstanding') }}</span>
                @endif
            </td>
        </tr>
    </table>

    <table class="signature">
        <tr>
            <td><div class="line">{{ __('pdf.for_the_school') }}</div></td>
            <td style="text-align: right;"><div class="line" style="margin-left: auto;">{{ __('pdf.stamp') }}</div></td>
        </tr>
    </table>
@endsection
