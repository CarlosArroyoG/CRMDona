<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\Role;

/*
 * Matriz aprobada para las Fases 1 y 2 (ADR-002). Si cambia, debe cambiar aquí a
 * propósito: A = Administrador, C = Coordinador, Co = Contador, L = Solo lectura.
 */
const APPROVED_MATRIX = [
    'donors.view' => ['A', 'C', 'Co', 'L'],
    'donors.manage' => ['A', 'C'],
    'donors.delete' => ['A'],
    'donors.tax_profile' => ['A', 'C', 'Co'],
    'donors.export' => ['A', 'C', 'Co'],
    'tags.manage' => ['A', 'C'],
    'programs.view' => ['A', 'C', 'Co', 'L'],
    'programs.manage' => ['A', 'C'],
    'programs.delete' => ['A'],
    'programs.export' => ['A', 'C', 'Co', 'L'],
    'campaigns.view' => ['A', 'C', 'Co', 'L'],
    'campaigns.manage' => ['A', 'C'],
    'campaigns.delete' => ['A'],
    'campaigns.export' => ['A', 'C', 'Co', 'L'],
    'donations.view' => ['A', 'C', 'Co', 'L'],
    'donations.register' => ['A', 'C', 'Co'],
    'donations.confirm' => ['A', 'Co'],
    'donations.export' => ['A', 'C', 'Co'],
    'organization.view' => ['A', 'Co'],
    'organization.update' => ['A'],
    'audit.view' => ['A'],
    'users.manage' => ['A'],
    // Fase 2 (fase-2-diseno-pagos.md §15).
    'payments.view' => ['A', 'C', 'Co', 'L'],
    'payments.view_technical' => ['A', 'Co'],
    'payments.export' => ['A', 'C', 'Co'],
    'refunds.request' => ['A', 'Co'],
    'subscriptions.view' => ['A', 'C', 'Co', 'L'],
    'subscriptions.manage' => ['A', 'C'],
    'disputes.view' => ['A', 'Co'],
    'incidents.view' => ['A', 'C', 'Co'],
    'incidents.manage' => ['A', 'C', 'Co'],
    'incidents.technical' => ['A', 'Co'],
    'payments.receive_alerts' => ['A', 'C', 'Co'],
    'webhooks.view' => ['A'],
    'payment_settings.view' => ['A'],
    // CFDI externos (cambio de alcance: el CRM no emite CFDI).
    'cfdi.view' => ['A', 'C', 'Co'],
    'cfdi.manage' => ['A', 'Co'],
    'accounting.process' => ['A', 'Co'],
    // Fase 4 — comunicaciones.
    'receipts.view' => ['A', 'C', 'Co'],
    'communications.view' => ['A', 'C', 'Co'],
    'communications.resend' => ['A', 'C', 'Co'],
    'communications.templates' => ['A', 'C'],
];

function roleCode(Role $role): string
{
    return match ($role) {
        Role::Administrator => 'A',
        Role::FundraisingCoordinator => 'C',
        Role::Accountant => 'Co',
        Role::ReadOnly => 'L',
    };
}

it('coincide con la matriz aprobada', function (Permission $permission): void {
    $granted = array_map(roleCode(...), $permission->roles());

    expect($granted)->toEqualCanonicalizing(APPROVED_MATRIX[$permission->value]);
})->with(Permission::cases());

it('cubre todos los permisos de la matriz aprobada', function (): void {
    expect(array_map(fn (Permission $permission): string => $permission->value, Permission::cases()))
        ->toEqualCanonicalizing(array_keys(APPROVED_MATRIX));
});

it('da al Administrador todos los permisos', function (): void {
    foreach (Permission::cases() as $permission) {
        expect($permission->allows(Role::Administrator))->toBeTrue();
    }
});

it('no da nada a un usuario sin rol', function (): void {
    foreach (Permission::cases() as $permission) {
        expect($permission->allows(null))->toBeFalse();
    }
});

it('mantiene a Solo lectura sin crear, editar, confirmar, exportar datos personales ni ver datos fiscales', function (): void {
    $forbidden = [
        Permission::ManageDonors, Permission::DeleteDonors, Permission::ManageDonorTaxProfiles, Permission::ExportDonors,
        Permission::ManageTags, Permission::ManagePrograms, Permission::ManageCampaigns, Permission::RegisterDonations,
        Permission::ConfirmDonations, Permission::ExportDonations, Permission::ViewOrganizationSettings,
        Permission::ViewAuditLog, Permission::ManageUsers,
        // Pagos: solo ve la información operativa; ninguna acción, detalle técnico ni exportación.
        Permission::ViewPaymentTechnicalDetails, Permission::ExportPayments, Permission::RequestRefunds,
        Permission::ManageSubscriptions, Permission::ViewDisputes, Permission::ViewIncidents, Permission::ManageIncidents,
        Permission::HandleTechnicalIncidents, Permission::ReceivePaymentAlerts, Permission::ViewWebhooks,
        Permission::ViewPaymentSettings,
    ];

    foreach ($forbidden as $permission) {
        expect($permission->allows(Role::ReadOnly))->toBeFalse();
    }
});
