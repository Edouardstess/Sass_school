<?php

declare(strict_types=1);

/**
 * SchoolFlow product configuration.
 *
 * Everything a business decision could change — the permission catalogue, the
 * role templates, supported currencies, reminder cadence, plan limits — lives
 * here rather than being hardcoded in services.
 */
return [

    /*
    |---------------------------------------------------------------------
    | Currency
    |---------------------------------------------------------------------
    | Amounts are stored as integer minor units. `exponent` is how many minor
    | units make one major unit.
    */
    'currency' => [
        'default' => env('PAYMENTS_DEFAULT_CURRENCY', 'HTG'),
        'supported' => [
            'HTG' => ['name' => 'Gourde haïtienne', 'symbol' => 'G', 'exponent' => 2],
            'USD' => ['name' => 'US Dollar', 'symbol' => '$', 'exponent' => 2],
        ],
    ],

    /*
    |---------------------------------------------------------------------
    | Locales
    |---------------------------------------------------------------------
    */
    'locales' => ['fr', 'en', 'ht'],

    /*
    |---------------------------------------------------------------------
    | Permission catalogue
    |---------------------------------------------------------------------
    | The complete list of verbs the system checks. Policies check these and
    | never check role names, so a school can reshuffle its roles without any
    | code change. Seeded by PermissionSeeder.
    */
    'permissions' => [
        'platform' => [
            'platform.schools.view', 'platform.schools.manage', 'platform.schools.suspend',
            'platform.subscriptions.manage', 'platform.plans.manage',
            'platform.users.manage', 'platform.analytics.view',
            'platform.audit.view', 'platform.settings.manage', 'platform.impersonate',
        ],
        'school' => [
            'school.view', 'school.update', 'school.settings.manage', 'school.subscription.manage',
        ],
        'users' => [
            'users.view', 'users.create', 'users.update', 'users.delete', 'users.roles.manage',
        ],
        'academic_years' => [
            'academic_years.view', 'academic_years.create', 'academic_years.update',
            'academic_years.activate', 'academic_years.close',
        ],
        'students' => [
            'students.view', 'students.view_own', 'students.create', 'students.update',
            'students.delete', 'students.import', 'students.export', 'students.transfer',
        ],
        'guardians' => [
            'guardians.view', 'guardians.create', 'guardians.update', 'guardians.delete',
        ],
        'teachers' => [
            'teachers.view', 'teachers.create', 'teachers.update', 'teachers.delete', 'teachers.import',
        ],
        'admissions' => [
            'admissions.view', 'admissions.review', 'admissions.decide', 'admissions.enroll',
        ],
        'classes' => [
            'classes.view', 'classes.create', 'classes.update', 'classes.delete', 'classes.assign_teacher',
        ],
        'subjects' => [
            'subjects.view', 'subjects.create', 'subjects.update', 'subjects.delete',
        ],
        'timetable' => [
            'timetable.view', 'timetable.manage',
        ],
        'grades' => [
            'grades.view', 'grades.view_own', 'grades.create', 'grades.update',
            'grades.delete', 'grades.lock', 'grades.publish',
        ],
        'report_cards' => [
            'report_cards.view', 'report_cards.view_own', 'report_cards.generate', 'report_cards.publish',
        ],
        'attendance' => [
            'attendance.view', 'attendance.view_own', 'attendance.record',
            'attendance.update', 'attendance.justify', 'attendance.approve_justification',
        ],
        'finance' => [
            'finance.view', 'fee_types.manage', 'invoices.view', 'invoices.view_own',
            'invoices.create', 'invoices.update', 'invoices.issue', 'invoices.cancel',
            'payments.view', 'payments.create', 'payments.refund',
            'receipts.view', 'discounts.manage', 'scholarships.manage',
        ],
        'notifications' => [
            'notifications.view', 'notifications.send', 'notifications.templates.manage',
        ],
        'documents' => [
            'documents.view', 'documents.upload', 'documents.delete',
            'certificates.view', 'certificates.issue', 'certificates.revoke',
        ],
        'reports' => [
            'reports.view', 'reports.financial', 'reports.academic',
            'reports.attendance', 'reports.export',
        ],
        'audit' => [
            'audit.view',
        ],
        'assistant' => [
            'assistant.use',
        ],
    ],

    /*
    |---------------------------------------------------------------------
    | Role templates
    |---------------------------------------------------------------------
    | The system roles seeded into every tenant. `*` on a group means every
    | permission in that group. These are starting points: a school can grant
    | or revoke any individual permission per user afterwards.
    */
    'roles' => [
        'platform_super_admin' => [
            'label' => 'Super administrateur plateforme',
            'platform' => true,
            'permissions' => ['*'],
        ],
        'school_owner' => [
            'label' => 'Propriétaire',
            'permissions' => [
                'school.*', 'users.*', 'academic_years.*', 'students.*', 'guardians.*',
                'teachers.*', 'admissions.*', 'classes.*', 'subjects.*', 'timetable.*',
                'grades.*', 'report_cards.*', 'attendance.*', 'finance.*',
                'notifications.*', 'documents.*', 'reports.*', 'audit.view', 'assistant.use',
            ],
        ],
        'school_admin' => [
            'label' => 'Administrateur',
            'permissions' => [
                'school.view', 'users.view', 'users.create', 'users.update',
                'academic_years.view', 'students.*', 'guardians.*', 'teachers.*',
                'admissions.*', 'classes.*', 'subjects.*', 'timetable.*',
                'grades.view', 'report_cards.view', 'report_cards.generate',
                'attendance.view', 'attendance.record', 'attendance.update',
                'attendance.approve_justification',
                'invoices.view', 'documents.*', 'notifications.view', 'notifications.send',
                'reports.view', 'reports.academic', 'reports.attendance', 'reports.export',
                'assistant.use',
            ],
        ],
        'principal' => [
            'label' => 'Directeur',
            'permissions' => [
                'school.view', 'users.view', 'academic_years.view',
                'students.view', 'guardians.view', 'teachers.view',
                'admissions.view', 'admissions.decide',
                'classes.view', 'subjects.view', 'timetable.view',
                'grades.view', 'grades.lock', 'grades.publish',
                'report_cards.view', 'report_cards.generate', 'report_cards.publish',
                'attendance.view', 'attendance.approve_justification',
                'finance.view', 'invoices.view', 'payments.view',
                'documents.view', 'certificates.view', 'certificates.issue',
                'notifications.view', 'notifications.send',
                'reports.*', 'audit.view', 'assistant.use',
            ],
        ],
        'teacher' => [
            'label' => 'Enseignant',
            'permissions' => [
                'students.view', 'classes.view', 'subjects.view', 'timetable.view',
                'grades.view', 'grades.create', 'grades.update', 'grades.delete',
                'report_cards.view',
                'attendance.view', 'attendance.record', 'attendance.update',
                'documents.view', 'notifications.view',
            ],
        ],
        'accountant' => [
            'label' => 'Comptable',
            'permissions' => [
                'school.view', 'students.view', 'guardians.view',
                'finance.*', 'documents.view', 'documents.upload',
                'notifications.view', 'notifications.send',
                'reports.view', 'reports.financial', 'reports.export',
                'assistant.use',
            ],
        ],
        'parent' => [
            'label' => 'Parent',
            'permissions' => [
                'students.view_own', 'grades.view_own', 'report_cards.view_own',
                'attendance.view_own', 'attendance.justify',
                'invoices.view_own', 'receipts.view', 'payments.create',
                'documents.view', 'notifications.view', 'timetable.view',
            ],
        ],
        'student' => [
            'label' => 'Élève',
            'permissions' => [
                'students.view_own', 'grades.view_own', 'report_cards.view_own',
                'attendance.view_own', 'timetable.view',
                'documents.view', 'notifications.view',
            ],
        ],
    ],

    /*
    |---------------------------------------------------------------------
    | Numbering
    |---------------------------------------------------------------------
    | `{PREFIX}-{PERIOD}-{SEQ}`; the sequence is drawn from number_sequences
    | under a row lock, so two concurrent requests cannot collide.
    */
    'numbering' => [
        'invoice' => ['prefix' => 'INV', 'padding' => 6, 'period' => 'year'],
        'receipt' => ['prefix' => 'REC', 'padding' => 6, 'period' => 'year'],
        'payment' => ['prefix' => 'PAY', 'padding' => 6, 'period' => 'year'],
        'refund' => ['prefix' => 'RFD', 'padding' => 6, 'period' => 'year'],
        'matricule' => ['prefix' => 'STU', 'padding' => 5, 'period' => 'year'],
        'application' => ['prefix' => 'APP', 'padding' => 5, 'period' => 'year'],
        'certificate' => ['prefix' => 'CRT', 'padding' => 5, 'period' => 'year'],
    ],

    /*
    |---------------------------------------------------------------------
    | Payment reminders
    |---------------------------------------------------------------------
    | Offsets in days relative to an invoice due date. Negative = before.
    */
    'reminders' => [
        'offsets' => [-7, -3, -1, 1, 7],
        'send_hour' => 9,   // local school time
    ],

    /*
    |---------------------------------------------------------------------
    | Grading
    |---------------------------------------------------------------------
    */
    'grading' => [
        'default_scale_max' => 100,
        'default_passing_grade' => 50,
        'appreciations' => [
            ['min' => 90, 'label' => 'Excellent'],
            ['min' => 80, 'label' => 'Très bien'],
            ['min' => 70, 'label' => 'Bien'],
            ['min' => 60, 'label' => 'Assez bien'],
            ['min' => 50, 'label' => 'Passable'],
            ['min' => 0, 'label' => 'Insuffisant'],
        ],
    ],

    /*
    |---------------------------------------------------------------------
    | Pagination
    |---------------------------------------------------------------------
    | Hard ceiling: no endpoint will ever return an unbounded collection.
    */
    'pagination' => [
        'default_per_page' => 25,
        'max_per_page' => 100,
    ],

    /*
    |---------------------------------------------------------------------
    | Security
    |---------------------------------------------------------------------
    */
    'security' => [
        'max_login_attempts' => (int) env('AUTH_MAX_LOGIN_ATTEMPTS', 5),
        'lockout_seconds' => (int) env('AUTH_LOCKOUT_SECONDS', 900),
        'token_expiration_minutes' => env('SANCTUM_TOKEN_EXPIRATION') !== null
            ? (int) env('SANCTUM_TOKEN_EXPIRATION')
            : null,
        'signed_url_ttl_minutes' => 10,
        // Keys scrubbed from audit payloads before they are written.
        'redacted_keys' => [
            'password', 'password_confirmation', 'current_password',
            'token', 'secret', 'two_factor_secret', 'two_factor_recovery_codes',
            'remember_token', 'api_key', 'authorization',
        ],
    ],

    /*
    |---------------------------------------------------------------------
    | Feature flags
    |---------------------------------------------------------------------
    | Platform-wide defaults. A feature_flags row for a school overrides one.
    */
    'features' => [
        'sms' => env('SMS_DRIVER', 'log') !== 'null',
        'whatsapp' => env('WHATSAPP_DRIVER', 'log') !== 'null',
        'online_payments' => true,
        'advanced_reports' => true,
        'ai_assistant' => (bool) env('AI_ENABLED', false),
        'public_api' => true,
        'admissions_portal' => true,
    ],

    /*
    |---------------------------------------------------------------------
    | Storage
    |---------------------------------------------------------------------
    */
    'storage' => [
        'disk' => env('FILESYSTEM_DISK', 's3'),
        'max_upload_bytes' => 20 * 1024 * 1024,
        'allowed_mime_types' => [
            'image/jpeg', 'image/png', 'image/webp',
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv', 'text/plain',
        ],
    ],

    /*
    |---------------------------------------------------------------------
    | AI assistant
    |---------------------------------------------------------------------
    | The assistant may only call the registered tools. It never receives a
    | database connection, and every tool re-checks the caller's permissions.
    */
    'assistant' => [
        'enabled' => (bool) env('AI_ENABLED', false),
        'provider' => env('AI_PROVIDER', 'anthropic'),
        'model' => env('AI_MODEL', 'claude-sonnet-5'),
        'max_tool_calls' => 5,
        'timeout_seconds' => 30,
    ],
];
