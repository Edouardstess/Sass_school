<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Academic\Models\Room;
use App\Domain\Identity\Models\User;
use App\Policies\Concerns\ChecksTenant;

class RoomPolicy
{
    use ChecksTenant;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['classes.view', 'timetable.view']);
    }

    public function view(User $user, Room $room): bool
    {
        return $this->allows($user, $room, 'classes.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('classes.create');
    }

    public function update(User $user, Room $room): bool
    {
        return $this->allows($user, $room, 'classes.update');
    }

    public function delete(User $user, Room $room): bool
    {
        return $this->allows($user, $room, 'classes.delete');
    }
}
