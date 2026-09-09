<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\InvoiceItem;
use App\Domain\Finance\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'currency' => $this->currency,

            // Both the exact integers and preformatted strings, so a client
            // never has to reimplement currency arithmetic to display a total.
            'subtotal' => $this->subtotal()->jsonSerialize(),
            'discount' => $this->discount()->jsonSerialize(),
            'total' => $this->total()->jsonSerialize(),
            'paid' => $this->paid()->jsonSerialize(),
            'balance' => $this->balance()->jsonSerialize(),

            'issued_on' => $this->issued_on?->toDateString(),
            'due_on' => $this->due_on?->toDateString(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'is_overdue' => $this->isOverdue(),
            'days_overdue' => $this->daysOverdue(),
            'notes' => $this->notes,

            'student' => $this->whenLoaded('student', fn (): array => [
                'id' => $this->student?->id,
                'matricule' => $this->student?->matricule,
                'full_name' => $this->student?->full_name,
            ]),
            'guardian' => $this->whenLoaded('guardian', fn () => $this->guardian === null ? null : [
                'id' => $this->guardian->id,
                'full_name' => $this->guardian->full_name,
                'phone' => $this->guardian->phone,
                'email' => $this->guardian->email,
            ]),

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (InvoiceItem $item): array => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unitPrice()->jsonSerialize(),
                'discount_minor' => $item->discount_minor,
                'total' => $item->total()->jsonSerialize(),
            ])->all()),

            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn (Payment $p): array => [
                'id' => $p->id,
                'reference' => $p->reference,
                'amount' => $p->amount()->jsonSerialize(),
                'method' => $p->method->value,
                'method_label' => $p->method->label(),
                'status' => $p->status->value,
                'paid_at' => $p->paid_at?->toIso8601String(),
            ])->all()),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
