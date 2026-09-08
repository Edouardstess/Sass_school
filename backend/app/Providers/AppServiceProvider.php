<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Academic\Models\Assessment;
use App\Domain\Academic\Models\Grade;
use App\Domain\Academic\Models\GradePeriod;
use App\Domain\Academic\Models\Level;
use App\Domain\Academic\Models\ReportCard;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\TimetableEntry;
use App\Domain\Attendance\Models\AttendanceJustification;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Document\Models\Certificate;
use App\Domain\Document\Models\Document;
use App\Domain\Document\Models\ExportJob;
use App\Domain\Document\Models\ImportBatch;
use App\Domain\Finance\Models\FeeType;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\InvoiceItem;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\Receipt;
use App\Domain\Finance\Models\Refund;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Models\Notification;
use App\Domain\School\Models\AcademicYear;
use App\Domain\School\Models\School;
use App\Domain\Student\Models\AdmissionApplication;
use App\Domain\Student\Models\Enrollment;
use App\Domain\Student\Models\Guardian;
use App\Domain\Student\Models\Student;
use App\Domain\Subscription\Models\Subscription;
use App\Domain\Teacher\Models\Teacher;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant context per request / per job. Everything that reads or
        // writes tenant data resolves this same instance.
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureMorphMap();
        $this->configureUrls();
    }

    private function configureModels(): void
    {
        // Fail loudly when code touches a relation that was not eager loaded,
        // instead of quietly issuing N+1 queries in production.
        Model::preventLazyLoading(! app()->isProduction());

        // Accessing an attribute that was never selected is a bug, not a null.
        Model::preventAccessingMissingAttributes(! app()->isProduction());

        // Mass assignment is opt-in everywhere: every model declares $fillable.
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        Model::unguard(false);
    }

    /**
     * Short, stable aliases for polymorphic columns.
     *
     * Without this the fully-qualified class name is written into the
     * database, and moving a class between namespaces silently orphans every
     * row that referenced it.
     */
    private function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'school' => School::class,
            'user' => User::class,
            'student' => Student::class,
            'teacher' => Teacher::class,
            'school_class' => SchoolClass::class,
            'invoice' => Invoice::class,
            'payment' => Payment::class,
            'grade' => Grade::class,
            'assessment' => Assessment::class,
            'report_card' => ReportCard::class,
            'attendance_record' => AttendanceRecord::class,
            'document' => Document::class,
            'admission_application' => AdmissionApplication::class,
            'certificate' => Certificate::class,
            'receipt' => Receipt::class,
            'refund' => Refund::class,
            'invoice_item' => InvoiceItem::class,
            'fee_type' => FeeType::class,
            'guardian' => Guardian::class,
            'enrollment' => Enrollment::class,
            'subject' => Subject::class,
            'level' => Level::class,
            'grade_period' => GradePeriod::class,
            'timetable_entry' => TimetableEntry::class,
            'academic_year' => AcademicYear::class,
            'attendance_justification' => AttendanceJustification::class,
            'subscription' => Subscription::class,
            'notification' => Notification::class,
            'import_batch' => ImportBatch::class,
            'export_job' => ExportJob::class,
        ]);
    }

    private function configureUrls(): void
    {
        // Behind a TLS-terminating proxy Laravel would otherwise generate
        // http:// links in verification e-mails.
        if (app()->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
