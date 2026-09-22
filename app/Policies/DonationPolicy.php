<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\DonationStatus;
use App\Enums\Permission;
use App\Models\Donation;
use App\Models\User;

/**
 * Separación de funciones: quien registra no necesariamente confirma. Ningún
 * rol elimina donativos (ADR-008).
 */
class DonationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewDonations);
    }

    public function view(User $user, Donation $donation): bool
    {
        return $user->hasPermission(Permission::ViewDonations);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::RegisterDonations);
    }

    public function update(User $user, Donation $donation): bool
    {
        return $user->hasPermission(Permission::RegisterDonations) && $donation->isPending();
    }

    public function confirm(User $user, Donation $donation): bool
    {
        return $user->hasPermission(Permission::ConfirmDonations) && $donation->isPending();
    }

    public function cancel(User $user, Donation $donation): bool
    {
        return $user->hasPermission(Permission::ConfirmDonations) && $donation->status !== DonationStatus::Cancelled;
    }

    public function export(User $user): bool
    {
        return $user->hasPermission(Permission::ExportDonations);
    }
}
