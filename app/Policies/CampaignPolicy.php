<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewCampaigns);
    }

    public function view(User $user, Campaign $campaign): bool
    {
        return $user->hasPermission(Permission::ViewCampaigns);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageCampaigns);
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $user->hasPermission(Permission::ManageCampaigns);
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $user->hasPermission(Permission::DeleteCampaigns) && ! $campaign->donations()->exists();
    }

    public function export(User $user): bool
    {
        return $user->hasPermission(Permission::ExportCampaigns);
    }
}
