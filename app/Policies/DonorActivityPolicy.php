<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\DonorActivity;
use App\Models\User;

class DonorActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewDonorActivities);
    }

    public function view(User $user, DonorActivity $activity): bool
    {
        return $user->hasPermission(Permission::ViewDonorActivities);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageDonorActivities);
    }

    public function update(User $user, DonorActivity $activity): bool
    {
        return $user->hasPermission(Permission::ManageDonorActivities) && $activity->isScheduled();
    }

    public function complete(User $user, DonorActivity $activity): bool
    {
        return $user->hasPermission(Permission::ManageDonorActivities) && $activity->isScheduled();
    }

    public function reschedule(User $user, DonorActivity $activity): bool
    {
        return $user->hasPermission(Permission::ManageDonorActivities) && $activity->isScheduled();
    }

    public function cancel(User $user, DonorActivity $activity): bool
    {
        return $user->hasPermission(Permission::ManageDonorActivities) && $activity->isScheduled();
    }
}
