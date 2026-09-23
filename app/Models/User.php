<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
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
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    use Auditable;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public static function auditValueFields(): array
    {
        return ['role', 'deactivated_at', 'password_change_required_at'];
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
        ];
    }
}
