<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\Models\Discount;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Services\InvoiceService;
use App\Domain\School\Models\AcademicYear;
use App\Domain\Shared\Enums\InvoiceStatus;
use App\Domain\Student\Models\Student;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $user = $this->user($request);

        $invoices = Invoice::query()
            ->with(['student:id,first_name,middle_name,last_name,matricule', 'guardian:id,first_name,last_name,phone,email'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->query('status')))
            ->when($request->boolean('outstanding_only'), fn (Builder $q) => $q->outstanding())
            ->when($request->boolean('overdue_only'), fn (Builder $q) => $q->overdue())
            ->when($request->filled('student_id'), fn (Builder $q) => $q->where('student_id', $request->query('student_id')))
            ->when($request->filled('academic_year_id'), fn (Builder $q) => $q->where('academic_year_id', $request->query('academic_year_id')))
            // A parent holds invoices.view_own and sees only their family's.
            ->when(
                ! $user->hasAnyPermission(['invoices.view', 'finance.view']),
                fn (Builder $q) => $q->whereIn('student_id', $this->relatedStudentIds($user)),
            )
            ->applySearch($request->query('search'), ['number'])
            ->applySort($request->query('sort'), ['number', 'due_on', 'total_minor', 'created_at'], '-created_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ApiResponse::paginated($invoices, InvoiceResource::class);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Invoice::class);

        $data = $request->validate([
            'student_id' => ['required', 'uuid'],
            'academic_year_id' => ['nullable', 'uuid'],
            'due_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'issue_immediately' => ['boolean'],

            // Either explicit lines, or ask the service to build the standard
            // set from the school's active fee types.
            'use_standard_fees' => ['boolean'],
            'items' => ['nullable', 'array', 'max:50'],
            'items.*.fee_type_id' => ['nullable', 'uuid'],
            'items.*.description' => ['required_with:items', 'string', 'max:200'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'items.*.unit_price_minor' => ['required_with:items', 'integer', 'min:0'],
            'items.*.discount_minor' => ['nullable', 'integer', 'min:0'],
        ]);

        $student = Student::query()->with('guardians')->findOrFail($data['student_id']);

        $yearId = $data['academic_year_id'] ?? AcademicYear::query()->active()->value('id');

        $items = ! empty($data['items'])
            ? $data['items']
            : $this->invoices->buildStandardItems($student, $student->classForYear((string) $yearId)?->level_id);

        $invoice = $this->invoices->create(
            student: $student,
            academicYearId: (string) $yearId,
            items: $items,
            dueOn: isset($data['due_on']) ? CarbonImmutable::parse($data['due_on']) : null,
            notes: $data['notes'] ?? null,
            createdBy: $this->user($request)->id,
        );

        if ($request->boolean('issue_immediately')) {
            $invoice = $this->invoices->issue(
                $invoice,
                isset($data['due_on']) ? CarbonImmutable::parse($data['due_on']) : null,
            );
        }

        return ApiResponse::created(
            new InvoiceResource($invoice->load(['items', 'student'])),
            __('responses.created'),
        );
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);

        $invoice->load([
            'items', 'student:id,first_name,middle_name,last_name,matricule',
            'guardian:id,first_name,last_name,phone,email',
            'payments' => fn ($q) => $q->latest('created_at'),
            'receipts', 'refunds',
        ]);

        return ApiResponse::success(new InvoiceResource($invoice));
    }

    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);

        $data = $request->validate([
            'due_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $invoice->fill($data)->save();

        return ApiResponse::success(new InvoiceResource($invoice->load('items')), __('responses.updated'));
    }

    /** Draft → issued: this is what makes the invoice payable and visible. */
    public function issue(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('issue', $invoice);

        $data = $request->validate(['due_on' => ['nullable', 'date']]);

        $invoice = $this->invoices->issue(
            $invoice,
            isset($data['due_on']) ? CarbonImmutable::parse($data['due_on']) : null,
        );

        return ApiResponse::success(new InvoiceResource($invoice->load('items')), __('finance.invoice_issued'));
    }

    public function cancel(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('cancel', $invoice);

        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        return ApiResponse::success(
            new InvoiceResource($this->invoices->cancel($invoice, $data['reason'])),
            __('finance.invoice_cancelled'),
        );
    }

    public function applyDiscount(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);

        $data = $request->validate(['discount_id' => ['required', 'uuid']]);

        $invoice = $this->invoices->applyDiscount(
            $invoice,
            Discount::query()->findOrFail($data['discount_id']),
        );

        return ApiResponse::success(new InvoiceResource($invoice->load('items')), __('finance.discount_applied'));
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        $this->authorize('delete', $invoice);

        $invoice->delete();

        return ApiResponse::noContent();
    }

    /** Receivables summary for the finance dashboard. */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $query = Invoice::query()
            ->when($request->filled('academic_year_id'), fn (Builder $q) => $q->where('academic_year_id', (string) $request->query('academic_year_id')));

        $rows = $query
            ->toBase()
            ->selectRaw('status, count(*) as count, sum(total_minor) as total, sum(balance_minor) as balance')
            ->groupBy('status')
            ->get();

        $currency = $this->user($request)->school->currency ?? config('schoolflow.currency.default');

        return ApiResponse::success([
            'currency' => $currency,
            'by_status' => $rows->map(fn (object $row): array => [
                'status' => $row->status,
                'label' => InvoiceStatus::from((string) $row->status)->label(),
                'count' => (int) $row->count,
                'total_minor' => (int) $row->total,
                'balance_minor' => (int) $row->balance,
            ])->values()->all(),
            'totals' => [
                'invoiced_minor' => (int) $rows->sum('total'),
                'outstanding_minor' => (int) $rows->whereIn('status', [
                    InvoiceStatus::Issued->value,
                    InvoiceStatus::PartiallyPaid->value,
                    InvoiceStatus::Overdue->value,
                ])->sum('balance'),
            ],
        ]);
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
