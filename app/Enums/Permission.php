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

    // Fase 2 — pagos (fase-2-diseno-pagos.md §15).
    case ViewPayments = 'payments.view';
    case ViewPaymentTechnicalDetails = 'payments.view_technical';
    case ExportPayments = 'payments.export';
    case RequestRefunds = 'refunds.request';
    case ViewSubscriptions = 'subscriptions.view';
    case ManageSubscriptions = 'subscriptions.manage';
    case ViewDisputes = 'disputes.view';
    case ViewIncidents = 'incidents.view';
    case ManageIncidents = 'incidents.manage';
    case HandleTechnicalIncidents = 'incidents.technical';
    case ReceivePaymentAlerts = 'payments.receive_alerts';
    case ViewWebhooks = 'webhooks.view';
    case ViewPaymentSettings = 'payment_settings.view';

    // Fase 3 — CFDI (docs/tecnico/fase-3-cfdi.md; aprobados 2026-09-23).
    case ViewCfdis = 'cfdi.view';
    case ViewCfdiTechnicalDetails = 'cfdi.view_technical';
    case IssueCfdis = 'cfdi.issue';
    case CancelCfdis = 'cfdi.cancel';

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
            self::ExportPrograms, self::ExportCampaigns, self::ViewPayments, self::ViewSubscriptions => Role::cases(),
            self::ManageDonors, self::ManageTags, self::ManagePrograms, self::ManageCampaigns,
            self::ManageSubscriptions => $fundraising,
            self::ManageDonorTaxProfiles, self::ExportDonors, self::RegisterDonations, self::ExportDonations,
            self::ExportPayments, self::ViewIncidents, self::ManageIncidents, self::ReceivePaymentAlerts,
            self::ViewCfdis => $staff,
            self::ConfirmDonations, self::ViewOrganizationSettings, self::ViewPaymentTechnicalDetails,
            self::RequestRefunds, self::ViewDisputes, self::HandleTechnicalIncidents,
            self::IssueCfdis, self::CancelCfdis, self::ViewCfdiTechnicalDetails => $finance,
            self::DeleteDonors, self::DeletePrograms, self::DeleteCampaigns,
            self::UpdateOrganizationSettings, self::ViewAuditLog, self::ManageUsers,
            self::ViewWebhooks, self::ViewPaymentSettings => [Role::Administrator],
        };
    }

    public function allows(?Role $role): bool
    {
        return $role !== null && in_array($role, $this->roles(), true);
    }
}
