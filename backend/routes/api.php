<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AcademicYearController;
use App\Http\Controllers\Api\V1\AdmissionController;
use App\Http\Controllers\Api\V1\AssessmentController;
use App\Http\Controllers\Api\V1\AssistantController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CertificateController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\EnrollmentController;
use App\Http\Controllers\Api\V1\FeeTypeController;
use App\Http\Controllers\Api\V1\GradeController;
use App\Http\Controllers\Api\V1\GuardianController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\LevelController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\Platform\PlatformAnalyticsController;
use App\Http\Controllers\Api\V1\Platform\PlatformSchoolController;
use App\Http\Controllers\Api\V1\ReportCardController;
use App\Http\Controllers\Api\V1\RoomController;
use App\Http\Controllers\Api\V1\SchoolClassController;
use App\Http\Controllers\Api\V1\SchoolController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\StudentController;
use App\Http\Controllers\Api\V1\SubjectController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TeacherController;
use App\Http\Controllers\Api\V1\TimetableController;
use App\Http\Controllers\Api\V1\VerificationController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SchoolFlow API v1
|--------------------------------------------------------------------------
|
| Three concentric rings, least to most trusted:
|
|   public   no authentication — admissions form, document verification,
|            payment webhooks, health probes. Every one of these is rate
|            limited, and each carries its own proof of legitimacy (a signed
|            webhook, a random verification code, a school slug).
|
|   auth     authenticated, but not inside a tenant: the caller's own
|            profile, and the platform endpoints.
|
|   tenant   authenticated AND operating inside one school. `tenant.required`
|            is what stops a platform admin who omitted X-School-Id from
|            reaching these controllers with the isolation scope inactive.
|
| Route-level `permission:` middleware is a coarse first gate; policies inside
| the controllers do the record-level and ownership checks. Both, always.
|
*/

Route::prefix('v1')->group(function (): void {

    /*
    |----------------------------------------------------------------------
    | Public
    |----------------------------------------------------------------------
    */
    Route::get('/health', [HealthController::class, 'health'])->name('health');
    Route::get('/ready', [HealthController::class, 'ready'])->name('ready');

    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth')
        ->name('auth.login');

    // Document authenticity. The only unauthenticated read of tenant data,
    // and it returns an attestation rather than a record.
    Route::get('/verify/{code}', [VerificationController::class, 'show'])
        ->middleware('throttle:verification')
        ->name('documents.verify');

    // Public admissions.
    Route::prefix('public/{schoolSlug}')->name('public.')->group(function (): void {
        Route::get('/admissions/levels', [AdmissionController::class, 'publicLevels'])
            ->middleware('throttle:verification')
            ->name('admissions.levels');

        Route::post('/admissions', [AdmissionController::class, 'submit'])
            ->middleware('throttle:public-write')
            ->name('admissions.submit');

        Route::get('/admissions/{reference}', [AdmissionController::class, 'publicStatus'])
            ->middleware('throttle:verification')
            ->name('admissions.status');
    });

    // Payment provider callbacks. Unauthenticated by necessity: trust comes
    // entirely from the HMAC signature check inside WebhookProcessor.
    Route::post('/webhooks/payments/{provider}', [WebhookController::class, 'handle'])
        ->name('webhooks.payments');

    /*
    |----------------------------------------------------------------------
    | Authenticated (no tenant required)
    |----------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/password', [AuthController::class, 'changePassword'])->name('auth.password');

        Route::prefix('auth/two-factor')->name('auth.2fa.')->group(function (): void {
            Route::post('/', [AuthController::class, 'enableTwoFactor'])->name('enable');
            Route::post('/confirm', [AuthController::class, 'confirmTwoFactor'])->name('confirm');
            Route::delete('/', [AuthController::class, 'disableTwoFactor'])->name('disable');
        });

        // Notifications belong to the person, not to a tenant context.
        Route::prefix('notifications')->name('notifications.')->group(function (): void {
            Route::get('/', [NotificationController::class, 'index'])->name('index');
            Route::get('/unread-count', [NotificationController::class, 'unreadCount'])->name('unread');
            Route::post('/{notification}/read', [NotificationController::class, 'markRead'])->name('read');
            Route::post('/read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
            Route::get('/preferences', [NotificationController::class, 'preferences'])->name('preferences');
            Route::put('/preferences', [NotificationController::class, 'updatePreferences'])->name('preferences.update');
        });

        /*
        |------------------------------------------------------------------
        | Platform operator
        |------------------------------------------------------------------
        */
        Route::prefix('platform')->name('platform.')->group(function (): void {
            Route::get('/analytics', [PlatformAnalyticsController::class, 'overview'])->name('analytics');
            Route::get('/audit', [PlatformAnalyticsController::class, 'audit'])->name('audit');

            Route::get('/schools', [PlatformSchoolController::class, 'index'])->name('schools.index');
            Route::post('/schools', [PlatformSchoolController::class, 'store'])->name('schools.store');
            Route::get('/schools/{school}', [PlatformSchoolController::class, 'show'])->name('schools.show');
            Route::put('/schools/{school}', [PlatformSchoolController::class, 'update'])->name('schools.update');
            Route::post('/schools/{school}/suspend', [PlatformSchoolController::class, 'suspend'])->name('schools.suspend');
            Route::post('/schools/{school}/reactivate', [PlatformSchoolController::class, 'reactivate'])->name('schools.reactivate');
        });

        // Plans are readable by any signed-in user: the upgrade screen needs
        // them, and they are public commercial information.
        Route::get('/plans', [SubscriptionController::class, 'plans'])->name('plans.index');

        /*
        |------------------------------------------------------------------
        | Tenant scope
        |------------------------------------------------------------------
        */
        Route::middleware(['tenant', 'tenant.required'])->group(function (): void {

            Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
            Route::get('/search', SearchController::class)->name('search');

            // --- school ---------------------------------------------------
            Route::get('/school', [SchoolController::class, 'show'])->name('school.show');
            Route::put('/school', [SchoolController::class, 'update'])->name('school.update');
            Route::get('/school/settings', [SchoolController::class, 'settings'])->name('school.settings');
            Route::put('/school/settings', [SchoolController::class, 'updateSettings'])->name('school.settings.update');
            Route::get('/school/subscription', [SubscriptionController::class, 'show'])->name('school.subscription');

            // --- academic calendar ---------------------------------------
            Route::apiResource('academic-years', AcademicYearController::class)->except(['destroy']);
            Route::post('academic-years/{academicYear}/activate', [AcademicYearController::class, 'activate'])->name('academic-years.activate');
            Route::post('academic-years/{academicYear}/close', [AcademicYearController::class, 'close'])->name('academic-years.close');
            Route::post('academic-years/{academicYear}/promote', [AcademicYearController::class, 'promote'])->name('academic-years.promote');

            // --- academic structure ---------------------------------------
            Route::apiResource('levels', LevelController::class)->except(['show']);
            Route::apiResource('subjects', SubjectController::class);
            Route::apiResource('rooms', RoomController::class)->except(['show']);

            Route::apiResource('classes', SchoolClassController::class)->parameters(['classes' => 'schoolClass']);
            Route::get('classes/{schoolClass}/students', [SchoolClassController::class, 'students'])->name('classes.students');
            Route::post('classes/{schoolClass}/subjects', [SchoolClassController::class, 'assignSubject'])->name('classes.subjects.assign');
            Route::delete('classes/{schoolClass}/subjects/{classSubject}', [SchoolClassController::class, 'removeSubject'])->name('classes.subjects.remove');

            // --- people ---------------------------------------------------
            Route::apiResource('students', StudentController::class);
            Route::post('students/{student}/guardians', [StudentController::class, 'attachGuardian'])->name('students.guardians.attach');
            Route::delete('students/{student}/guardians/{guardianId}', [StudentController::class, 'detachGuardian'])->name('students.guardians.detach');

            Route::apiResource('guardians', GuardianController::class);
            Route::get('guardians/{guardian}/students', [GuardianController::class, 'students'])->name('guardians.students');

            Route::apiResource('teachers', TeacherController::class);
            Route::get('teachers/{teacher}/schedule', [TeacherController::class, 'schedule'])->name('teachers.schedule');

            Route::post('enrollments', [EnrollmentController::class, 'store'])->name('enrollments.store');
            Route::post('enrollments/{enrollment}/transfer', [EnrollmentController::class, 'transfer'])->name('enrollments.transfer');
            Route::post('enrollments/{enrollment}/withdraw', [EnrollmentController::class, 'withdraw'])->name('enrollments.withdraw');

            // --- admissions -----------------------------------------------
            Route::get('admissions', [AdmissionController::class, 'index'])->name('admissions.index');
            Route::get('admissions/{admissionApplication}', [AdmissionController::class, 'show'])->name('admissions.show');
            Route::post('admissions/{admissionApplication}/decide', [AdmissionController::class, 'decide'])->name('admissions.decide');
            Route::post('admissions/{admissionApplication}/enroll', [AdmissionController::class, 'enroll'])->name('admissions.enroll');
            Route::post('admissions/{admissionApplication}/comments', [AdmissionController::class, 'comment'])->name('admissions.comment');

            // --- timetable ------------------------------------------------
            Route::get('timetable', [TimetableController::class, 'index'])->name('timetable.index');
            Route::post('timetable', [TimetableController::class, 'store'])->name('timetable.store');
            Route::post('timetable/check', [TimetableController::class, 'checkConflicts'])->name('timetable.check');
            Route::put('timetable/{timetableEntry}', [TimetableController::class, 'update'])->name('timetable.update');
            Route::delete('timetable/{timetableEntry}', [TimetableController::class, 'destroy'])->name('timetable.destroy');

            // --- grading --------------------------------------------------
            Route::apiResource('assessments', AssessmentController::class);
            Route::post('assessments/{assessment}/lock', [AssessmentController::class, 'lock'])->name('assessments.lock');
            Route::post('assessments/{assessment}/unlock', [AssessmentController::class, 'unlock'])->name('assessments.unlock');
            Route::post('assessments/{assessment}/grades', [GradeController::class, 'store'])->name('grades.store');

            Route::put('grades/{grade}', [GradeController::class, 'update'])->name('grades.update');
            Route::get('grades/{grade}/revisions', [GradeController::class, 'revisions'])->name('grades.revisions');

            Route::get('report-cards', [ReportCardController::class, 'index'])->name('report-cards.index');
            Route::post('report-cards/generate', [ReportCardController::class, 'generate'])->name('report-cards.generate');
            Route::post('report-cards/publish', [ReportCardController::class, 'publish'])->name('report-cards.publish');
            Route::get('report-cards/{reportCard}', [ReportCardController::class, 'show'])->name('report-cards.show');
            Route::get('report-cards/{reportCard}/download', [ReportCardController::class, 'download'])->name('report-cards.download');

            // --- attendance -----------------------------------------------
            Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
            Route::get('attendance/summary', [AttendanceController::class, 'summary'])->name('attendance.summary');
            Route::get('attendance/classes/{schoolClass}/roster', [AttendanceController::class, 'roster'])->name('attendance.roster');
            Route::post('attendance/classes/{schoolClass}', [AttendanceController::class, 'store'])->name('attendance.store');
            Route::post('attendance/{attendanceRecord}/justify', [AttendanceController::class, 'justify'])->name('attendance.justify');
            Route::post('attendance/justifications/{justification}/review', [AttendanceController::class, 'reviewJustification'])->name('attendance.justifications.review');

            // --- finance --------------------------------------------------
            Route::apiResource('fee-types', FeeTypeController::class)->except(['show'])->parameters(['fee-types' => 'feeType']);

            Route::get('invoices/summary', [InvoiceController::class, 'summary'])->name('invoices.summary');
            Route::apiResource('invoices', InvoiceController::class);
            Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
            Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
            Route::post('invoices/{invoice}/discount', [InvoiceController::class, 'applyDiscount'])->name('invoices.discount');
            Route::post('invoices/{invoice}/checkout', [PaymentController::class, 'checkout'])->name('invoices.checkout');

            Route::get('payments/methods', [PaymentController::class, 'methods'])->name('payments.methods');
            Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
            Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
            Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
            Route::post('payments/{payment}/refund', [PaymentController::class, 'refund'])->name('payments.refund');

            // --- documents ------------------------------------------------
            Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
            Route::post('documents', [DocumentController::class, 'store'])->middleware('throttle:heavy')->name('documents.store');
            Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download-url');
            Route::get('documents/{document}/stream', [DocumentController::class, 'stream'])->name('documents.download');
            Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

            Route::get('certificates', [CertificateController::class, 'index'])->name('certificates.index');
            Route::post('certificates', [CertificateController::class, 'store'])->name('certificates.store');
            Route::get('certificates/{certificate}', [CertificateController::class, 'show'])->name('certificates.show');
            Route::get('certificates/{certificate}/download', [CertificateController::class, 'download'])->name('certificates.download');
            Route::post('certificates/{certificate}/revoke', [CertificateController::class, 'revoke'])->name('certificates.revoke');

            // --- assistant ------------------------------------------------
            Route::get('assistant/capabilities', [AssistantController::class, 'capabilities'])->name('assistant.capabilities');
            Route::post('assistant/ask', [AssistantController::class, 'ask'])
                ->middleware('throttle:assistant')
                ->name('assistant.ask');
        });
    });
});
