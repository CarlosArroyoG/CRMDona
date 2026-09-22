<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Donor;
use App\Models\User;

class DonorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewDonors);
    }

    public function view(User $user, Donor $donor): bool
    {
        return $user->hasPermission(Permission::ViewDonors);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageDonors);
    }

    public function update(User $user, Donor $donor): bool
    {
        return $user->hasPermission(Permission::ManageDonors);
    }

    /**
     * Los datos fiscales tienen su propio formulario: el Contador los edita
     * sin poder cambiar los datos generales del donante.
     */
    public function updateTaxProfile(User $user, Donor $donor): bool
    {
        return $user->hasPermission(Permission::ManageDonorTaxProfiles);
    }

    public function archive(User $user, Donor $donor): bool
    {
        return $user->hasPermission(Permission::ManageDonors);
    }

    public function delete(User $user, Donor $donor): bool
    {
        return $user->hasPermission(Permission::DeleteDonors) && ! $donor->donations()->exists();
    }

    public function viewTaxProfile(User $user): bool
    {
        return $user->hasPermission(Permission::ManageDonorTaxProfiles);
    }

    public function export(User $user): bool
    {
        return $user->hasPermission(Permission::ExportDonors);
    }
}
