<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Matriz de permisos (ADR-002): la única fuente de verdad de qué rol puede
 * hacer qué. Las Policies la consultan y agregan encima las reglas de estado
 * (por ejemplo, solo se edita un donativo "Por confirmar").
 */
enum Permission: string
{
    case ViewDonors = 'donors.view';
    case ManageDonors = 'donors.manage';
    case DeleteDonors = 'donors.delete';
    case ManageDonorTaxProfiles = 'donors.tax_profile';
    case ExportDonors = 'donors.export';
    case ManageTags = 'tags.manage';

    case ViewPrograms = 'programs.view';
    case ManagePrograms = 'programs.manage';
    case DeletePrograms = 'programs.delete';
    case ExportPrograms = 'programs.export';

    case ViewCampaigns = 'campaigns.view';
    case ManageCampaigns = 'campaigns.manage';
    case DeleteCampaigns = 'campaigns.delete';
    case ExportCampaigns = 'campaigns.export';

    case ViewDonations = 'donations.view';
    case RegisterDonations = 'donations.register';
    case ConfirmDonations = 'donations.confirm';
    case ExportDonations = 'donations.export';

    case ViewOrganizationSettings = 'organization.view';
    case UpdateOrganizationSettings = 'organization.update';
    case ViewAuditLog = 'audit.view';
    case ManageUsers = 'users.manage';

    /**
     * @return list<Role>
     */
    public function roles(): array
    {
        $staff = [Role::Administrator, Role::FundraisingCoordinator, Role::Accountant];
        $fundraising = [Role::Administrator, Role::FundraisingCoordinator];
        $finance = [Role::Administrator, Role::Accountant];

        return match ($this) {
            self::ViewDonors, self::ViewPrograms, self::ViewCampaigns, self::ViewDonations,
            self::ExportPrograms, self::ExportCampaigns => Role::cases(),
            self::ManageDonors, self::ManageTags, self::ManagePrograms, self::ManageCampaigns => $fundraising,
            self::ManageDonorTaxProfiles, self::ExportDonors, self::RegisterDonations, self::ExportDonations => $staff,
            self::ConfirmDonations, self::ViewOrganizationSettings => $finance,
            self::DeleteDonors, self::DeletePrograms, self::DeleteCampaigns,
            self::UpdateOrganizationSettings, self::ViewAuditLog, self::ManageUsers => [Role::Administrator],
        };
    }

    public function allows(?Role $role): bool
    {
        return $role !== null && in_array($role, $this->roles(), true);
    }
}
