<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/** The critical actions the audit trail is required to capture. */
enum AuditAction: string
{
    case Login = 'LOGIN';
    case LoginFailed = 'LOGIN_FAILED';
    case Logout = 'LOGOUT';
    case Create = 'CREATE';
    case Update = 'UPDATE';
    case Delete = 'DELETE';
    case Payment = 'PAYMENT';
    case Refund = 'REFUND';
    case GradeUpdate = 'GRADE_UPDATE';
    case AttendanceUpdate = 'ATTENDANCE_UPDATE';
    case RoleChange = 'ROLE_CHANGE';
    case PasswordChange = 'PASSWORD_CHANGE';
    case DocumentDownload = 'DOCUMENT_DOWNLOAD';
    case DocumentUpload = 'DOCUMENT_UPLOAD';
    case Export = 'EXPORT';
    case Import = 'IMPORT';
    case SubscriptionChange = 'SUBSCRIPTION_CHANGE';
    case Impersonation = 'IMPERSONATION';
}
