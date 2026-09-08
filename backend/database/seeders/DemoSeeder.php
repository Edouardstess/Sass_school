<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Academic\Models\Assessment;
use App\Domain\Academic\Models\ClassSubject;
use App\Domain\Academic\Models\Grade;
use App\Domain\Academic\Models\GradePeriod;
use App\Domain\Academic\Models\Level;
use App\Domain\Academic\Models\Room;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\TimetableEntry;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Finance\Models\FeeType;
use App\Domain\Finance\Services\InvoiceService;
use App\Domain\Finance\Services\PaymentService;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\School\Models\AcademicYear;
use App\Domain\School\Models\School;
use App\Domain\Shared\Enums\AssessmentType;
use App\Domain\Shared\Enums\AttendanceStatus;
use App\Domain\Shared\Enums\PaymentMethod;
use App\Domain\Shared\Enums\SubscriptionStatus;
use App\Domain\Shared\Services\NumberGenerator;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Student\Models\Enrollment;
use App\Domain\Student\Models\Guardian;
use App\Domain\Student\Models\Student;
use App\Domain\Subscription\Models\Plan;
use App\Domain\Subscription\Models\Subscription;
use App\Domain\Teacher\Models\Teacher;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * A complete, working demonstration tenant.
 *
 * Everything here goes through the real services — invoices are built by
 * InvoiceService, payments applied by PaymentService — so the demo data is
 * arithmetically consistent with what the application would produce itself.
 * Seeding is therefore also a smoke test of the domain layer.
 *
 * Credentials are documented in the README for local use only.
 */
class DemoSeeder extends Seeder
{
    private const PASSWORD = 'password';

    private School $school;

    private AcademicYear $year;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoSeeder must never run outside local or testing.');
        }

        $this->createPlatformAdmin();

        $this->school = $this->createSchool();

        // Everything below writes tenant-owned rows, so it runs inside the
        // tenant context — exactly as a request would.
        app(TenantContext::class)->runFor($this->school, function (): void {
            $this->year = $this->createAcademicYear();
            $periods = $this->createGradePeriods();
            $levels = $this->createLevels();
            $rooms = $this->createRooms();
            $subjects = $this->createSubjects();
            $teachers = $this->createTeachers();
            $staff = $this->createStaff($teachers);
            $classes = $this->createClasses($levels, $teachers, $rooms);
            $assignments = $this->assignSubjects($classes, $subjects, $teachers);
            $this->createTimetable($assignments, $rooms);

            [$students, $guardians] = $this->createFamilies($classes);

            $this->recordGrades($assignments, $periods->first(), $students);
            $this->recordAttendance($classes, $students);
            $this->createFinance($students, $staff['accountant']);
        });

        $this->command->info('Demo tenant ready — see README.md for credentials.');
    }

    // ---------------------------------------------------------------- platform

    private function createPlatformAdmin(): void
    {
        $admin = User::query()->firstOrCreate(
            ['email' => 'superadmin@schoolflow.test'],
            [
                'school_id' => null,
                'first_name' => 'Sasha',
                'last_name' => 'Platform',
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
                'status' => User::STATUS_ACTIVE,
                'locale' => 'fr',
            ],
        );

        $this->attachRole($admin, Role::PLATFORM_SUPER_ADMIN, null);
    }

    private function createSchool(): School
    {
        $school = School::query()->firstOrCreate(
            ['slug' => 'lycee-toussaint-louverture'],
            [
                'name' => 'Lycée Toussaint Louverture',
                'legal_name' => 'Lycée Toussaint Louverture S.A.',
                'email' => 'direction@lycee-tl.test',
                'phone' => '+509 2811 1000',
                'website' => 'https://lycee-tl.test',
                'address_line1' => '15, rue Capois',
                'city' => 'Port-au-Prince',
                'country' => 'HT',
                'locale' => 'fr',
                'timezone' => 'America/Port-au-Prince',
                'currency' => 'HTG',
                'status' => School::STATUS_ACTIVE,
            ],
        );

        $plan = Plan::query()->where('code', Plan::PROFESSIONAL)->firstOrFail();

        Subscription::query()->firstOrCreate(
            ['school_id' => $school->id, 'plan_id' => $plan->id],
            [
                'status' => SubscriptionStatus::Active->value,
                'billing_cycle' => 'yearly',
                'current_period_start' => CarbonImmutable::now()->startOfYear(),
                'current_period_end' => CarbonImmutable::now()->addYear(),
            ],
        );

        return $school;
    }

    // ---------------------------------------------------------------- academic

    private function createAcademicYear(): AcademicYear
    {
        $start = CarbonImmutable::create((int) now()->year, 9, 1);

        return AcademicYear::query()->firstOrCreate(
            ['school_id' => $this->school->id, 'name' => $start->year.'-'.($start->year + 1)],
            [
                'starts_on' => $start,
                'ends_on' => $start->addMonths(10),
                'status' => AcademicYear::STATUS_ACTIVE,
                'grading_scale_max' => 100,
                'passing_grade' => 50,
            ],
        );
    }

    /** @return Collection<int, GradePeriod> */
    private function createGradePeriods()
    {
        $start = $this->year->starts_on;

        return collect([
            ['name' => 'Trimestre 1', 'sequence' => 1, 'from' => $start, 'to' => $start->addMonths(3)],
            ['name' => 'Trimestre 2', 'sequence' => 2, 'from' => $start->addMonths(3), 'to' => $start->addMonths(6)],
            ['name' => 'Trimestre 3', 'sequence' => 3, 'from' => $start->addMonths(6), 'to' => $this->year->ends_on],
        ])->map(fn (array $period): GradePeriod => GradePeriod::query()->firstOrCreate(
            ['academic_year_id' => $this->year->id, 'sequence' => $period['sequence']],
            [
                'school_id' => $this->school->id,
                'name' => $period['name'],
                'starts_on' => $period['from'],
                'ends_on' => $period['to'],
                'weight' => 1,
            ],
        ));
    }

    /** @return Collection<int, Level> */
    private function createLevels()
    {
        return collect(['6ème', '5ème', '4ème'])->values()->map(
            fn (string $name, int $index): Level => Level::query()->firstOrCreate(
                ['school_id' => $this->school->id, 'name' => $name],
                ['code' => 'L'.($index + 1), 'sequence' => $index + 1],
            )
        );
    }

    /** @return Collection<int, Room> */
    private function createRooms()
    {
        return collect(['Salle A1', 'Salle A2', 'Salle B1'])->map(
            fn (string $name): Room => Room::query()->firstOrCreate(
                ['school_id' => $this->school->id, 'name' => $name],
                ['building' => 'Bâtiment principal', 'capacity' => 35],
            )
        );
    }

    /** @return Collection<int, Subject> */
    private function createSubjects()
    {
        return collect([
            ['name' => 'Mathématiques', 'code' => 'MATH', 'coefficient' => 4, 'color' => '#2563EB'],
            ['name' => 'Français', 'code' => 'FR', 'coefficient' => 4, 'color' => '#DC2626'],
            ['name' => 'Sciences expérimentales', 'code' => 'SVT', 'coefficient' => 3, 'color' => '#059669'],
            ['name' => 'Histoire-Géographie', 'code' => 'HG', 'coefficient' => 2, 'color' => '#D97706'],
            ['name' => 'Anglais', 'code' => 'ANG', 'coefficient' => 2, 'color' => '#7C3AED'],
        ])->map(fn (array $subject): Subject => Subject::query()->firstOrCreate(
            ['school_id' => $this->school->id, 'name' => $subject['name']],
            [
                'code' => $subject['code'],
                'default_coefficient' => $subject['coefficient'],
                'color' => $subject['color'],
                'is_active' => true,
            ],
        ));
    }

    // ------------------------------------------------------------------ people

    /** @return Collection<int, Teacher> */
    private function createTeachers()
    {
        return collect([
            ['first' => 'Marie', 'last' => 'Joseph', 'specialty' => 'Mathématiques'],
            ['first' => 'Jean', 'last' => 'Baptiste', 'specialty' => 'Lettres'],
            ['first' => 'Nadège', 'last' => 'Pierre', 'specialty' => 'Sciences'],
        ])->values()->map(function (array $data, int $index): Teacher {
            $user = $this->createUser(
                $data['first'],
                $data['last'],
                sprintf('prof%d@lycee-tl.test', $index + 1),
                Role::TEACHER,
            );

            return Teacher::query()->firstOrCreate(
                ['school_id' => $this->school->id, 'employee_number' => 'EMP-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT)],
                [
                    'user_id' => $user->id,
                    'first_name' => $data['first'],
                    'last_name' => $data['last'],
                    'email' => $user->email,
                    'phone' => '+509 3'.random_int(1000000, 9999999),
                    'specialty' => $data['specialty'],
                    'qualification' => 'Licence',
                    'hired_on' => CarbonImmutable::now()->subYears(3),
                    'status' => Teacher::STATUS_ACTIVE,
                ],
            );
        });
    }

    /** @return array<string, User> */
    private function createStaff($teachers): array
    {
        return [
            'owner' => $this->createUser('Roseline', 'Étienne', 'proprietaire@lycee-tl.test', Role::SCHOOL_OWNER),
            'admin' => $this->createUser('Patrick', 'Louis', 'admin@lycee-tl.test', Role::SCHOOL_ADMIN),
            'principal' => $this->createUser('Gérard', 'Charles', 'directeur@lycee-tl.test', Role::PRINCIPAL),
            'accountant' => $this->createUser('Sabine', 'Moïse', 'comptable@lycee-tl.test', Role::ACCOUNTANT),
        ];
    }

    /** @return Collection<int, SchoolClass> */
    private function createClasses($levels, $teachers, $rooms)
    {
        return $levels->values()->map(fn (Level $level, int $index): SchoolClass => SchoolClass::query()->firstOrCreate(
            ['academic_year_id' => $this->year->id, 'name' => $level->name.' A'],
            [
                'school_id' => $this->school->id,
                'level_id' => $level->id,
                'section' => 'A',
                'capacity' => 30,
                'homeroom_teacher_id' => $teachers[$index % $teachers->count()]->id,
                'room_id' => $rooms[$index % $rooms->count()]->id,
                'is_active' => true,
            ],
        ));
    }

    /** @return Collection<int, ClassSubject> */
    private function assignSubjects($classes, $subjects, $teachers)
    {
        $assignments = collect();

        foreach ($classes as $class) {
            foreach ($subjects->values() as $index => $subject) {
                $assignments->push(ClassSubject::query()->firstOrCreate(
                    ['school_class_id' => $class->id, 'subject_id' => $subject->id],
                    [
                        'school_id' => $this->school->id,
                        'teacher_id' => $teachers[$index % $teachers->count()]->id,
                        'coefficient' => $subject->default_coefficient,
                        'weekly_hours' => 4,
                        'is_active' => true,
                    ],
                ));
            }
        }

        return $assignments;
    }

    /**
     * A conflict-free weekly timetable.
     *
     * Slots are handed out round-robin so no teacher and no room is ever
     * double-booked — the same invariant TimetableConflictDetector enforces at
     * runtime, so the demo data would survive its own validation.
     */
    private function createTimetable($assignments, $rooms): void
    {
        $slots = [['08:00', '09:30'], ['09:45', '11:15'], ['11:30', '13:00'], ['13:45', '15:15']];
        $cursor = 0;

        foreach ($assignments as $assignment) {
            $day = intdiv($cursor, count($slots)) % 5 + 1;   // Monday–Friday
            [$from, $to] = $slots[$cursor % count($slots)];

            TimetableEntry::query()->firstOrCreate(
                [
                    'school_class_id' => $assignment->school_class_id,
                    'day_of_week' => $day,
                    'starts_at' => $from,
                ],
                [
                    'school_id' => $this->school->id,
                    'academic_year_id' => $this->year->id,
                    'subject_id' => $assignment->subject_id,
                    'teacher_id' => $assignment->teacher_id,
                    'room_id' => $rooms[$cursor % $rooms->count()]->id,
                    'ends_at' => $to,
                ],
            );

            $cursor++;
        }
    }

    /**
     * Ten families, thirty students, spread across the classes.
     *
     * @return array{0: Collection<int, Student>, 1: Collection<int, Guardian>}
     */
    private function createFamilies($classes): array
    {
        $numbers = app(NumberGenerator::class);
        $firstNames = ['Wideline', 'Jimmy', 'Chantal', 'Ricardo', 'Myrlande', 'Kervens', 'Darline', 'Steevens', 'Rosemene', 'Woodly'];
        $lastNames = ['Alexis', 'Beauvais', 'Cadet', 'Delva', 'Estimé', 'Fils-Aimé', 'Georges', 'Hyppolite', 'Innocent', 'Jean-Louis'];

        $students = collect();
        $guardians = collect();

        for ($family = 0; $family < 10; $family++) {
            $surname = $lastNames[$family];

            $guardianUser = $this->createUser(
                'Parent',
                $surname,
                sprintf('parent%d@lycee-tl.test', $family + 1),
                Role::PARENT,
            );

            $guardian = Guardian::query()->firstOrCreate(
                ['school_id' => $this->school->id, 'user_id' => $guardianUser->id],
                [
                    'first_name' => 'Parent',
                    'last_name' => $surname,
                    'email' => $guardianUser->email,
                    'phone' => '+509 4'.random_int(1000000, 9999999),
                    'occupation' => 'Commerçant(e)',
                    'preferred_channel' => 'email',
                ],
            );

            $guardians->push($guardian);

            // Three children per family gives 30 students across 3 classes.
            for ($child = 0; $child < 3; $child++) {
                $class = $classes[$child % $classes->count()];

                $student = Student::query()->firstOrCreate(
                    [
                        'school_id' => $this->school->id,
                        'first_name' => $firstNames[($family + $child) % count($firstNames)],
                        'last_name' => $surname,
                        'birth_date' => CarbonImmutable::now()->subYears(12 + $child)->subDays($family * 7)->toDateString(),
                    ],
                    [
                        'matricule' => $numbers->matricule($this->school->id),
                        'gender' => $child % 2 === 0 ? 'female' : 'male',
                        'birth_place' => 'Port-au-Prince',
                        'nationality' => 'Haïtienne',
                        'address' => sprintf('%d, rue Lamarre, Port-au-Prince', 10 + $family),
                        'emergency_contact_name' => 'Parent '.$surname,
                        'emergency_contact_phone' => $guardian->phone,
                        'emergency_contact_relation' => 'Parent',
                        'status' => Student::STATUS_ACTIVE,
                        'enrolled_on' => $this->year->starts_on,
                    ],
                );

                $student->guardians()->syncWithoutDetaching([
                    $guardian->id => [
                        'school_id' => $this->school->id,
                        'relationship' => 'parent',
                        'is_primary' => true,
                        'is_financial_responsible' => true,
                        'can_pick_up' => true,
                    ],
                ]);

                Enrollment::query()->firstOrCreate(
                    ['student_id' => $student->id, 'academic_year_id' => $this->year->id],
                    [
                        'school_id' => $this->school->id,
                        'school_class_id' => $class->id,
                        'enrolled_on' => $this->year->starts_on,
                        'status' => 'active',
                        'enrollment_type' => Enrollment::TYPE_NEW,
                    ],
                );

                $students->push($student);
            }
        }

        return [$students, $guardians];
    }

    // ---------------------------------------------------------------- academics

    private function recordGrades($assignments, GradePeriod $period, $students): void
    {
        foreach ($assignments as $assignment) {
            $enrolled = $students->filter(
                fn (Student $student): bool => $student->enrollments()
                    ->where('school_class_id', $assignment->school_class_id)
                    ->exists()
            );

            if ($enrolled->isEmpty()) {
                continue;
            }

            foreach ([AssessmentType::Quiz, AssessmentType::Exam] as $type) {
                $assessment = Assessment::query()->firstOrCreate(
                    [
                        'class_subject_id' => $assignment->id,
                        'grade_period_id' => $period->id,
                        'title' => $type->label().' — '.$period->name,
                    ],
                    [
                        'school_id' => $this->school->id,
                        'academic_year_id' => $this->year->id,
                        'type' => $type->value,
                        'max_score' => $type === AssessmentType::Quiz ? 20 : 100,
                        'weight' => $type->defaultWeight(),
                        'assessed_on' => $period->starts_on->addDays(20),
                        'status' => Assessment::STATUS_PUBLISHED,
                        'published_at' => now(),
                    ],
                );

                foreach ($enrolled as $student) {
                    // A couple of absences so the "absent ≠ zero" behaviour is
                    // actually exercised by the demo data.
                    $absent = random_int(1, 20) === 1;

                    Grade::query()->firstOrCreate(
                        ['assessment_id' => $assessment->id, 'student_id' => $student->id],
                        [
                            'school_id' => $this->school->id,
                            'score' => $absent ? null : random_int(
                                (int) round((float) $assessment->max_score * 0.35),
                                (int) $assessment->max_score,
                            ),
                            'is_absent' => $absent,
                        ],
                    );
                }
            }
        }
    }

    private function recordAttendance($classes, $students): void
    {
        $start = CarbonImmutable::now()->subDays(20);

        foreach ($students as $student) {
            $enrollment = $student->enrollments()->where('academic_year_id', $this->year->id)->first();

            if ($enrollment === null) {
                continue;
            }

            for ($offset = 0; $offset < 20; $offset++) {
                $date = $start->addDays($offset);

                if ($date->isWeekend()) {
                    continue;
                }

                $roll = random_int(1, 100);
                $status = match (true) {
                    $roll <= 90 => AttendanceStatus::Present,
                    $roll <= 96 => AttendanceStatus::Absent,
                    $roll <= 99 => AttendanceStatus::Late,
                    default => AttendanceStatus::Excused,
                };

                AttendanceRecord::query()->firstOrCreate(
                    [
                        'student_id' => $student->id,
                        'attendance_date' => $date->toDateString(),
                        'timetable_entry_id' => null,
                    ],
                    [
                        'school_id' => $this->school->id,
                        'school_class_id' => $enrollment->school_class_id,
                        'academic_year_id' => $this->year->id,
                        'status' => $status->value,
                        'minutes_late' => $status === AttendanceStatus::Late ? random_int(5, 30) : null,
                    ],
                );
            }
        }
    }

    // ----------------------------------------------------------------- finance

    /**
     * Fee types, then a real invoice per student built through InvoiceService,
     * with roughly two thirds of them paid through PaymentService — so the
     * demo dashboard shows a genuine mix of paid, partially paid and
     * outstanding balances rather than fabricated numbers.
     */
    private function createFinance($students, User $accountant): void
    {
        $fees = collect([
            ['name' => 'Frais d\'inscription', 'amount' => 500_00, 'recurrence' => FeeType::RECURRENCE_YEARLY],
            ['name' => 'Frais de scolarité', 'amount' => 2_500_00, 'recurrence' => FeeType::RECURRENCE_TERMLY],
            ['name' => 'Cantine', 'amount' => 800_00, 'recurrence' => FeeType::RECURRENCE_MONTHLY],
            ['name' => 'Transport', 'amount' => 600_00, 'recurrence' => FeeType::RECURRENCE_MONTHLY],
        ])->map(fn (array $fee): FeeType => FeeType::query()->firstOrCreate(
            ['school_id' => $this->school->id, 'name' => $fee['name']],
            [
                'default_amount_minor' => $fee['amount'],
                'currency' => 'HTG',
                'recurrence' => $fee['recurrence'],
                'is_mandatory' => true,
                'is_active' => true,
            ],
        ));

        $invoices = app(InvoiceService::class);
        $payments = app(PaymentService::class);

        foreach ($students as $index => $student) {
            $student->loadMissing('guardians');

            $items = $fees->map(fn (FeeType $fee): array => [
                'fee_type_id' => $fee->id,
                'description' => $fee->name,
                'quantity' => 1,
                'unit_price_minor' => $fee->default_amount_minor,
                'discount_minor' => 0,
            ])->all();

            $invoice = $invoices->create(
                student: $student,
                academicYearId: $this->year->id,
                items: $items,
                currency: 'HTG',
                generationKey: sprintf('demo-%s-%s', $this->year->id, $student->id),
                createdBy: $accountant->id,
            );

            $invoices->issue($invoice, CarbonImmutable::now()->addDays(15 - $index));

            // Every third family is left unpaid so the receivables report and
            // the reminder scheduler have something real to act on.
            if ($index % 3 === 0) {
                continue;
            }

            $invoice->refresh();

            // Half of the payers settle in full, half pay an instalment.
            $amount = $index % 2 === 0
                ? $invoice->balance()
                : Money::of(intdiv($invoice->balance_minor, 2), $invoice->currency);

            $payments->recordManualPayment(
                invoice: $invoice,
                amount: $amount,
                method: $index % 4 === 1 ? PaymentMethod::Cash : PaymentMethod::BankTransfer,
                recordedBy: $accountant->id,
                payerName: 'Parent '.$student->last_name,
            );
        }
    }

    // ------------------------------------------------------------------ helpers

    private function createUser(string $first, string $last, string $email, string $role): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'school_id' => $this->school->id,
                'first_name' => $first,
                'last_name' => $last,
                'password' => Hash::make(self::PASSWORD),
                'phone' => '+509 3'.random_int(1000000, 9999999),
                'email_verified_at' => now(),
                'status' => User::STATUS_ACTIVE,
                'locale' => 'fr',
            ],
        );

        $this->attachRole($user, $role, $this->school->id);

        return $user;
    }

    private function attachRole(User $user, string $roleName, ?string $schoolId): void
    {
        $role = Role::query()->where('name', $roleName)->availableTo($schoolId)->firstOrFail();

        $user->roles()->syncWithoutDetaching([$role->id => ['assigned_at' => now()]]);
        $user->bumpPermissionsVersion();
    }
}
