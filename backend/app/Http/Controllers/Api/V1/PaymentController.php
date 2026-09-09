<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\PaymentService;
use App\Domain\Shared\Enums\PaymentMethod;
use App\Domain\Shared\ValueObjects\Money;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Infrastructure\Payments\PaymentGatewayManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $user = $this->user($request);

        $payments = Payment::query()
            ->with(['student:id,first_name,middle_name,last_name,matricule', 'invoice:id,number', 'receipt:id,number,payment_id'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->query('status')))
            ->when($request->filled('method'), fn (Builder $q) => $q->where('method', $request->query('method')))
            ->when($request->filled('invoice_id'), fn (Builder $q) => $q->where('invoice_id', $request->query('invoice_id')))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('paid_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('paid_at', '<=', $request->query('to')))
            ->when(
                ! $user->hasAnyPermission(['payments.view', 'finance.view']),
                fn (Builder $q) => $q->whereIn('student_id', $this->relatedStudentIds($user)),
            )
            ->applySearch($request->query('search'), ['reference', 'external_reference', 'payer_name'])
            ->applySort($request->query('sort'), ['paid_at', 'amount_minor', 'created_at'], '-created_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ApiResponse::paginated($payments, null);
    }

    /**
     * Record a payment collected off-platform.
     *
     * Only manual methods are accepted here; a gateway payment can never be
     * marked received by a human, it must come from a verified webhook.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Payment::class);

        $data = $request->validate([
            'invoice_id' => ['required', 'uuid'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::in([
                PaymentMethod::Cash->value,
                PaymentMethod::BankTransfer->value,
                PaymentMethod::Cheque->value,
            ])],
            'payer_name' => ['nullable', 'string', 'max:160'],
            'external_reference' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $invoice = Invoice::query()->findOrFail($data['invoice_id']);
        $this->authorize('view', $invoice);

        $payment = $this->payments->recordManualPayment(
            invoice: $invoice,
            amount: Money::of((int) $data['amount_minor'], $invoice->currency),
            method: PaymentMethod::from($data['method']),
            recordedBy: $this->user($request)->id,
            payerName: $data['payer_name'] ?? null,
            externalReference: $data['external_reference'] ?? null,
            notes: $data['notes'] ?? null,
        );

        return ApiResponse::created($this->present($payment->load(['receipt', 'invoice'])), __('finance.payment_recorded'));
    }

    public function show(Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        return ApiResponse::success(
            $this->present($payment->load(['invoice:id,number', 'student:id,first_name,middle_name,last_name', 'receipt', 'refunds']))
        );
    }

    /** Payment methods that are actually usable right now. */
    public function methods(): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        return ApiResponse::success(array_map(
            fn ($gateway): array => [
                'key' => $gateway->key(),
                'label' => $gateway->displayName(),
                'is_manual' => $gateway->isManual(),
                'currencies' => $gateway->supportedCurrencies(),
            ],
            // Unconfigured providers are absent, so the payment screen never
            // offers a button that fails on click.
            $this->gateways->available(),
        ));
    }

    /**
     * Start an online payment.
     *
     * Creates a pending payment and returns the provider's checkout URL.
     * Nothing is applied to the invoice here — the webhook does that.
     */
    public function checkout(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('pay', $invoice);

        $data = $request->validate([
            'method' => ['required', Rule::in([
                PaymentMethod::MonCash->value,
                PaymentMethod::NatCash->value,
                PaymentMethod::Stripe->value,
            ])],
            'amount_minor' => ['nullable', 'integer', 'min:1'],
            'payer_phone' => ['nullable', 'string', 'max:40'],
        ]);

        $amount = isset($data['amount_minor'])
            ? Money::of((int) $data['amount_minor'], $invoice->currency)
            : $invoice->balance();

        $transaction = $this->payments->initiateGatewayPayment(
            invoice: $invoice,
            amount: $amount,
            method: PaymentMethod::from($data['method']),
            context: ['payer_phone' => $data['payer_phone'] ?? null],
        );

        return ApiResponse::created([
            'transaction_id' => $transaction->id,
            'provider' => $transaction->provider,
            'checkout_url' => $transaction->checkout_url,
            'expires_at' => $transaction->expires_at?->toIso8601String(),
            'amount' => $amount->jsonSerialize(),
            // Stated explicitly so no client is tempted to treat the redirect
            // back from the provider as proof of payment.
            'note' => __('finance.checkout_pending_note'),
        ], __('finance.checkout_created'));
    }

    public function refund(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('refund', $payment);

        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $refund = $this->payments->refund(
            payment: $payment,
            amount: Money::of((int) $data['amount_minor'], $payment->currency),
            reason: $data['reason'],
            approvedBy: $this->user($request)->id,
        );

        return ApiResponse::created([
            'id' => $refund->id,
            'reference' => $refund->reference,
            'amount' => $refund->amount()->jsonSerialize(),
            'status' => $refund->status,
        ], __('finance.refund_recorded'));
    }

    /** @return array<string, mixed> */
    private function present(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'reference' => $payment->reference,
            'amount' => $payment->amount()->jsonSerialize(),
            'method' => $payment->method->value,
            'method_label' => $payment->method->label(),
            'status' => $payment->status->value,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'payer_name' => $payment->payer_name,
            'external_reference' => $payment->external_reference,
            'notes' => $payment->notes,
            'invoice' => $payment->relationLoaded('invoice') ? [
                'id' => $payment->invoice?->id,
                'number' => $payment->invoice?->number,
            ] : null,
            'student' => $payment->relationLoaded('student') ? [
                'id' => $payment->student?->id,
                'full_name' => $payment->student?->full_name,
            ] : null,
            'receipt' => $payment->relationLoaded('receipt') && $payment->receipt !== null ? [
                'id' => $payment->receipt->id,
                'number' => $payment->receipt->number,
                'has_pdf' => $payment->receipt->document_id !== null,
            ] : null,
            'refundable' => $payment->status->isSuccessful()
                ? $payment->refundableAmount()->jsonSerialize()
                : null,
        ];
    }

    /** @return list<string> */
    private function relatedStudentIds($user): array
    {
        $user->loadMissing(['guardian', 'student']);

        $ids = [];

        if ($user->student !== null) {
            $ids[] = $user->student->id;
        }

        if ($user->guardian !== null) {
            $ids = [...$ids, ...$user->guardian->students()->pluck('students.id')->all()];
        }

        return $ids;
    }
}
