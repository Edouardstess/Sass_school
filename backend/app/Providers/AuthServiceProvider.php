<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Academic\Models\Assessment;
use App\Domain\Academic\Models\Grade;
use App\Domain\Academic\Models\ReportCard;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Document\Models\Certificate;
use App\Domain\Document\Models\Document;
use App\Domain\Finance\Models\FeeType;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\School\Models\School;
use App\Domain\Student\Models\AdmissionApplication;
use App\Domain\Student\Models\Guardian;
use App\Domain\Student\Models\Student;
use App\Domain\Teacher\Models\Teacher;
use App\Policies\AdmissionApplicationPolicy;
use App\Policies\AssessmentPolicy;
use App\Policies\AttendanceRecordPolicy;
use App\Policies\CertificatePolicy;
use App\Policies\DocumentPolicy;
use App\Policies\FeeTypePolicy;
use App\Policies\GradePolicy;
use App\Policies\GuardianPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\PaymentPolicy;
use App\Policies\ReportCardPolicy;
use App\Policies\SchoolClassPolicy;
use App\Policies\SchoolPolicy;
use App\Policies\StudentPolicy;
use App\Policies\SubjectPolicy;
use App\Policies\TeacherPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private array $policies = [
        School::class => SchoolPolicy::class,
        User::class => UserPolicy::class,
        Student::class => StudentPolicy::class,
        Guardian::class => GuardianPolicy::class,
        Teacher::class => TeacherPolicy::class,
        SchoolClass::class => SchoolClassPolicy::class,
        Subject::class => SubjectPolicy::class,
        Assessment::class => AssessmentPolicy::class,
        Grade::class => GradePolicy::class,
        ReportCard::class => ReportCardPolicy::class,
        AttendanceRecord::class => AttendanceRecordPolicy::class,
        Invoice::class => InvoicePolicy::class,
        Payment::class => PaymentPolicy::class,
        FeeType::class => FeeTypePolicy::class,
        Document::class => DocumentPolicy::class,
        Certificate::class => CertificatePolicy::class,
        AdmissionApplication::class => AdmissionApplicationPolicy::class,
    ];

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        /*
         * Deliberately NO `Gate::before` super-admin bypass.
         *
         * A blanket bypass would let a platform admin read tenant data without
         * the impersonation check and without an audit entry. Platform
         * capabilities are expressed as ordinary `platform.*` permissions
         * instead, so every one of them is visible, revocable and logged.
         */
    }
}
