<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IncidentType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * `role` y `deactivated_at` no son asignables en masa: solo los cambian las
 * Actions de usuarios.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Role|null $role
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $password_change_required_at
 * @property bool $receives_payment_alerts Solo aplica a Coordinador y Contador; el Administrador siempre las recibe.
 * @property bool $receives_accounting_notices Recibe los avisos a Contabilidad (solo con accounting.process: Administrador y Contador).
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use Auditable;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // MFA nativo de Filament (#45): secreto cifrado y códigos de recuperación con hash, ocultos
    // en la serialización. Nunca entran a la bitácora (no están en los campos auditados).
    use InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery;

    public static function auditValueFields(): array
    {
        return ['role', 'deactivated_at', 'password_change_required_at', 'receives_payment_alerts', 'receives_accounting_notices'];
    }

    public static function auditNameOnlyFields(): array
    {
        return ['name', 'email', 'password'];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->role !== null && $this->isActive();
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /**
     * Destinatarios de alertas de pagos (fase-2-diseno-pagos.md §17): todo
     * Administrador; Coordinador (solo incidencias operativas) y Contador si
     * el Administrador activó su preferencia; Solo lectura nunca.
     */
    public function receivesPaymentAlertsFor(IncidentType $type): bool
    {
        if (! $this->hasPermission(Permission::ReceivePaymentAlerts)) {
            return false;
        }

        return match ($this->role) {
            Role::Administrator => true,
            Role::FundraisingCoordinator => $this->receives_payment_alerts && $type->isOperational(),
            Role::Accountant => $this->receives_payment_alerts,
            default => false,
        };
    }

    /**
     * Puede ver y atender incidencias de este tipo: las técnicas son solo
     * para quien tiene `incidents.technical`.
     */
    public function canHandleIncidentType(IncidentType $type): bool
    {
        return $type->isOperational() || $this->hasPermission(Permission::HandleTechnicalIncidents);
    }

    /**
     * Mientras tenga contraseña temporal no ejerce ningún permiso: solo puede
     * cambiarla (EnsurePasswordIsCurrent) o cerrar sesión.
     */
    public function hasPermission(Permission $permission): bool
    {
        return $this->isActive() && ! $this->mustChangePassword() && $permission->allows($this->role);
    }

    /**
     * La contraseña actual es temporal (la generó un restablecimiento).
     */
    public function mustChangePassword(): bool
    {
        return $this->password_change_required_at !== null;
    }

    public function temporaryPasswordExpired(): bool
    {
        return $this->password_change_required_at !== null
            && $this->password_change_required_at->copy()
                ->addHours(config()->integer('auth.temporary_password_ttl_hours'))
                ->isPast();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'deactivated_at' => 'datetime',
            'password_change_required_at' => 'datetime',
            'receives_payment_alerts' => 'boolean',
            'receives_accounting_notices' => 'boolean',
        ];
    }
}
