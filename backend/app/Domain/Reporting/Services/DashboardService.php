<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Academic\Models\Assessment;
use App\Domain\Academic\Models\ClassSubject;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\AttendanceStatus;
use App\Domain\Shared\Enums\InvoiceStatus;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Student\Models\Student;
use App\Domain\Teacher\Models\Teacher;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Builds the figures each role's dashboard shows.
 *
 * Every number here is a query against real rows — there is no fixture data
 * and no placeholder anywhere in this class. Aggregates that are expensive and
 * do not need to be to-the-second are cached briefly, keyed per tenant.
 */
final class DashboardService
{
    private const CACHE_SECONDS = 300;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * Pick the dashboard shape for this user.
     *
     * Role first, because "which dashboard" is a product concept expressed in
     * roles — an accountant and an administrator share several permissions, so
     * inferring the answer from the permission set alone gets it wrong.
     * Permissions remain the fallback for users on bespoke roles.
     *
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $user->loadMissing(['roles', 'teacher', 'guardian', 'student']);

        $roles = $user->roles->pluck('name');

        return match (true) {
            $roles->contains(Role::ACCOUNTANT) => $this->accountant(),
            $roles->contains(Role::TEACHER) && $user->teacher !== null => $this->teacher($user->teacher),
            $roles->contains(Role::PARENT) && $user->guardian !== null => $this->guardian($user),
            $roles->contains(Role::STUDENT) && $user->student !== null => $this->student($user->student),
            $roles->intersect([Role::SCHOOL_OWNER, Role::SCHOOL_ADMIN, Role::PRINCIPAL])->isNotEmpty() => $this->administration(),

            // Bespoke roles: fall back to what the permissions imply.
            $user->hasPermission('finance.view') => $this->accountant(),
            $user->hasAnyPermission(['school.view', 'reports.view']) => $this->administration(),
            $user->teacher !== null => $this->teacher($user->teacher),
            $user->guardian !== null => $this->guardian($user),
            $user->student !== null => $this->student($user->student),
            default => ['role' => 'unknown', 'widgets' => []],
        };
    }

    /** Direction: the whole-school view. */
    public function administration(): array
    {
        return $this->remember('administration', function (): array {
            $currency = $this->currency();
            $monthStart = CarbonImmutable::now()->startOfMonth();

            $activeStudents = Student::query()->active()->count();

            $newThisMonth = Student::query()
                ->where('created_at', '>=', $monthStart)
                ->count();

            $absencesThisWeek = AttendanceRecord::query()
                ->where('status', AttendanceStatus::Absent->value)
                ->where('attendance_date', '>=', CarbonImmutable::now()->startOfWeek()->toDateString())
                ->count();

            $revenueThisMonth = (int) Payment::query()
                ->where('status', PaymentStatus::Succeeded->value)
                ->where('paid_at', '>=', $monthStart)
                ->sum('amount_minor');

            $outstanding = (int) Invoice::query()->outstanding()->sum('balance_minor');
            $overdueCount = Invoice::query()->overdue()->count();

            return [
                'role' => 'administration',
                'students' => [
                    'active' => $activeStudents,
                    'new_this_month' => $newThisMonth,
                    'archived' => Student::query()->where('status', Student::STATUS_ARCHIVED)->count(),
                ],
                'staff' => [
                    'teachers' => Teacher::query()->active()->count(),
                    'users' => User::query()->where('school_id', $this->tenant->idOrFail())->count(),
                ],
                'classes' => [
                    'total' => SchoolClass::query()->where('is_active', true)->count(),
                    'average_size' => $this->averageClassSize(),
                ],
                'attendance' => [
                    'absences_this_week' => $absencesThisWeek,
                    'rate_this_month' => $this->attendanceRate($monthStart),
                ],
                'finance' => [
                    'revenue_this_month' => Money::of($revenueThisMonth, $currency)->jsonSerialize(),
                    'outstanding' => Money::of($outstanding, $currency)->jsonSerialize(),
                    'overdue_invoices' => $overdueCount,
                ],
                'academics' => [
                    'pass_rate' => $this->passRate(),
                    'assessments_this_month' => Assessment::query()->where('assessed_on', '>=', $monthStart->toDateString())->count(),
                ],
                'alerts' => $this->alerts(),
            ];
        });
    }

    /** Accountancy: receivables and cash in. */
    public function accountant(): array
    {
        return $this->remember('accountant', function (): array {
            $currency = $this->currency();
            $monthStart = CarbonImmutable::now()->startOfMonth();

            $byStatus = Invoice::query()
                ->selectRaw('status, count(*) as count, sum(total_minor) as total, sum(balance_minor) as balance')
                ->groupBy('status')
                ->toBase()
                ->get();

            return [
                'role' => 'accountant',
                'currency' => $currency,
                'invoiced_total' => Money::of((int) $byStatus->sum('total'), $currency)->jsonSerialize(),
                'collected_this_month' => Money::of(
                    (int) Payment::query()
                        ->where('status', PaymentStatus::Succeeded->value)
                        ->where('paid_at', '>=', $monthStart)
                        ->sum('amount_minor'),
                    $currency,
                )->jsonSerialize(),
                'outstanding' => Money::of(
                    (int) $byStatus->whereIn('status', [
                        InvoiceStatus::Issued->value,
                        InvoiceStatus::PartiallyPaid->value,
                        InvoiceStatus::Overdue->value,
                    ])->sum('balance'),
                    $currency,
                )->jsonSerialize(),
                'overdue' => Money::of(
                    (int) $byStatus->where('status', InvoiceStatus::Overdue->value)->sum('balance'),
                    $currency,
                )->jsonSerialize(),
                'by_status' => $byStatus->map(fn ($row): array => [
                    'status' => $row->status,
                    'count' => (int) $row->count,
                    'balance_minor' => (int) $row->balance,
                ])->all(),
                'due_next_7_days' => Invoice::query()
                    ->outstanding()
                    ->whereBetween('due_on', [
                        CarbonImmutable::now()->toDateString(),
                        CarbonImmutable::now()->addDays(7)->toDateString(),
                    ])
                    ->count(),
                'revenue_by_month' => $this->revenueByMonth($currency),
            ];
        });
    }

    /** A teacher's own classes and outstanding marking. */
    public function teacher(Teacher $teacher): array
    {
        /** @var Collection<int, ClassSubject> $assignments */
        $assignments = $teacher->classSubjects()
            ->with(['schoolClass:id,name', 'subject:id,name'])
            ->where('is_active', true)
            ->get();

        // Published assessments where at least one student is still unmarked:
        // this is the teacher's actual to-do list.
        $pending = Assessment::query()
            ->whereIn('class_subject_id', $assignments->pluck('id'))
            ->where('status', Assessment::STATUS_PUBLISHED)
            ->withCount('grades')
            ->get()
            ->filter(function (Assessment $assessment) use ($assignments): bool {
                $classId = $assignments->firstWhere('id', $assessment->class_subject_id)?->school_class_id;

                if ($classId === null) {
                    return false;
                }

                $expected = DB::table('enrollments')
                    ->where('school_class_id', $classId)
                    ->where('status', 'active')
                    ->count();

                return $assessment->grades_count < $expected;
            });

        return [
            'role' => 'teacher',
            'classes' => $assignments->map(fn (ClassSubject $a): array => [
                'class_subject_id' => $a->id,
                'class' => ['id' => $a->schoolClass?->id, 'name' => $a->schoolClass?->name],
                'subject' => ['id' => $a->subject?->id, 'name' => $a->subject?->name],
            ])->values()->all(),
            'classes_count' => $assignments->pluck('school_class_id')->unique()->count(),
            'subjects_count' => $assignments->pluck('subject_id')->unique()->count(),
            'pending_grading' => $pending->map(fn (Assessment $a): array => [
                'id' => $a->id,
                'title' => $a->title,
                'assessed_on' => $a->assessed_on?->toDateString(),
                'graded' => $a->grades_count,
            ])->values()->all(),
            'lessons_this_week' => $teacher->timetableEntries()->count(),
        ];
    }

    /** A parent's children, with what they actually need to act on. */
    public function guardian(User $user): array
    {
        $guardian = $user->loadMissing('guardian')->guardian;

        if ($guardian === null) {
            return ['role' => 'guardian', 'children' => []];
        }

        $students = $guardian->students()->with('enrollments.schoolClass')->get();
        $currency = $this->currency();

        return [
            'role' => 'guardian',
            'children' => $students->map(function (Student $student) use ($currency): array {
                $outstanding = (int) Invoice::query()
                    ->where('student_id', $student->id)
                    ->outstanding()
                    ->sum('balance_minor');

                $absences = AttendanceRecord::query()
                    ->where('student_id', $student->id)
                    ->where('status', AttendanceStatus::Absent->value)
                    ->where('attendance_date', '>=', CarbonImmutable::now()->subDays(30)->toDateString())
                    ->count();

                $latestCard = DB::table('report_cards')
                    ->where('student_id', $student->id)
                    ->where('status', 'published')
                    ->orderByDesc('published_at')
                    ->first();

                return [
                    'id' => $student->id,
                    'full_name' => $student->full_name,
                    'matricule' => $student->matricule,
                    'class' => $student->enrollments->first()?->schoolClass?->name,
                    'outstanding_balance' => Money::of($outstanding, $currency)->jsonSerialize(),
                    'absences_last_30_days' => $absences,
                    'latest_average' => $latestCard?->average === null ? null : (float) $latestCard->average,
                    'latest_rank' => $latestCard?->rank,
                ];
            })->all(),
            'unread_notifications' => DB::table('notifications')
                ->where('user_id', $user->id)
                ->whereNull('read_at')
                ->count(),
        ];
    }

    /** A student's own view. */
    public function student(Student $student): array
    {
        $enrollment = $student->enrollments()->with('schoolClass')->where('status', 'active')->first();

        $latestCard = DB::table('report_cards')
            ->where('student_id', $student->id)
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->first();

        return [
            'role' => 'student',
            'profile' => [
                'full_name' => $student->full_name,
                'matricule' => $student->matricule,
                'class' => $enrollment?->schoolClass?->name,
            ],
            'latest_average' => $latestCard?->average === null ? null : (float) $latestCard->average,
            'latest_rank' => $latestCard?->rank,
            'class_size' => $latestCard?->class_size,
            'absences_last_30_days' => AttendanceRecord::query()
                ->where('student_id', $student->id)
                ->where('status', AttendanceStatus::Absent->value)
                ->where('attendance_date', '>=', CarbonImmutable::now()->subDays(30)->toDateString())
                ->count(),
        ];
    }

    // ------------------------------------------------------------- internals

    private function averageClassSize(): float
    {
        $sizes = DB::table('enrollments')
            ->join('school_classes', 'enrollments.school_class_id', '=', 'school_classes.id')
            ->where('enrollments.school_id', $this->tenant->idOrFail())
            ->where('enrollments.status', 'active')
            ->groupBy('enrollments.school_class_id')
            ->selectRaw('count(*) as size')
            ->pluck('size');

        return $sizes->isEmpty() ? 0.0 : round((float) $sizes->avg(), 1);
    }

    private function attendanceRate(CarbonImmutable $since): ?float
    {
        $rows = AttendanceRecord::query()
            ->where('attendance_date', '>=', $since->toDateString())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $total = (int) $rows->sum();

        if ($total === 0) {
            return null;
        }

        $absent = (int) $rows->get(AttendanceStatus::Absent->value, 0);

        return round((($total - $absent) / $total) * 100, 1);
    }

    private function passRate(): ?float
    {
        $row = DB::table('report_cards')
            ->join('academic_years', function ($join): void {
                $join->on('academic_years.school_id', '=', 'report_cards.school_id');
            })
            ->where('report_cards.school_id', $this->tenant->idOrFail())
            ->whereNotNull('report_cards.average')
            ->selectRaw('count(*) as total, count(*) filter (where report_cards.average >= academic_years.passing_grade) as passing')
            ->first();

        if ($row === null || (int) $row->total === 0) {
            return null;
        }

        return round(((int) $row->passing / (int) $row->total) * 100, 1);
    }

    /** @return list<array{month: string, amount_minor: int}> */
    private function revenueByMonth(string $currency): array
    {
        return Payment::query()
            ->where('status', PaymentStatus::Succeeded->value)
            ->where('paid_at', '>=', CarbonImmutable::now()->subMonths(11)->startOfMonth())
            ->selectRaw("to_char(paid_at, 'YYYY-MM') as month, sum(amount_minor) as amount")
            ->groupBy('month')
            ->orderBy('month')
            ->toBase()
            ->get()
            ->map(fn ($row): array => [
                'month' => (string) $row->month,
                'amount_minor' => (int) $row->amount,
            ])
            ->all();
    }

    /** @return list<array{level: string, message: string}> */
    private function alerts(): array
    {
        $alerts = [];

        $overdue = Invoice::query()->overdue()->count();

        if ($overdue > 0) {
            $alerts[] = ['level' => 'warning', 'message' => __('dashboard.alert_overdue', ['count' => $overdue])];
        }

        $unjustified = AttendanceRecord::query()
            ->where('status', AttendanceStatus::Absent->value)
            ->where('is_justified', false)
            ->where('attendance_date', '>=', CarbonImmutable::now()->subDays(7)->toDateString())
            ->count();

        if ($unjustified > 0) {
            $alerts[] = ['level' => 'info', 'message' => __('dashboard.alert_unjustified', ['count' => $unjustified])];
        }

        $unassigned = SchoolClass::query()->where('is_active', true)->whereNull('homeroom_teacher_id')->count();

        if ($unassigned > 0) {
            $alerts[] = ['level' => 'info', 'message' => __('dashboard.alert_no_homeroom', ['count' => $unassigned])];
        }

        return $alerts;
    }

    private function currency(): string
    {
        return $this->tenant->school()->currency ?? (string) config('schoolflow.currency.default');
    }

    /**
     * Short per-tenant cache. Five minutes is well inside what a dashboard
     * needs, and keeps a class of heavy aggregates off every page load.
     *
     * @param  Closure(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function remember(string $key, Closure $callback): array
    {
        return Cache::remember(
            "dashboard:{$this->tenant->idOrFail()}:{$key}",
            self::CACHE_SECONDS,
            $callback,
        );
    }
}
