<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Identity\Models\User;
use App\Policies\Concerns\ChecksTenant;

class AttendanceRecordPolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['attendance.view', 'attendance.view_own']);
    }

    public function view(User $user, AttendanceRecord $record): bool
    {
        if (! $this->sameTenant($user, $record)) {
            return false;
        }

        if ($user->hasPermission('attendance.view')) {
            return true;
        }

        return $user->hasPermission('attendance.view_own')
            && app(StudentPolicy::class)->isRelated($user, $record->student);
    }

    public function record(User $user): bool
    {
        return $user->hasPermission('attendance.record');
    }

    public function update(User $user, AttendanceRecord $record): bool
    {
        return $this->allows($user, $record, 'attendance.update');
    }

    /** A guardian may submit a justification only for their own child. */
    public function justify(User $user, AttendanceRecord $record): bool
    {
        if (! $this->sameTenant($user, $record) || ! $user->hasPermission('attendance.justify')) {
            return false;
        }

        if (! $record->status->canBeJustified()) {
            return false;
        }

        return app(StudentPolicy::class)->isRelated($user, $record->student);
    }

    public function approveJustification(User $user, AttendanceRecord $record): bool
    {
        return $this->allows($user, $record, 'attendance.approve_justification');
    }
}
