<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\AuditEvent;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Desactiva o reactiva un usuario. Un usuario desactivado no entra al panel.
 */
class SetUserActive
{
    public function __construct(private readonly EnsureActiveAdministratorRemains $guard) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, bool $active, User $actor): User
    {
        if (! $actor->hasPermission(Permission::ManageUsers)) {
            throw new AuthorizationException('No tienes permiso para administrar usuarios.');
        }

        if (! $active && $user->is($actor)) {
            throw ValidationException::withMessages(['user' => 'No puedes desactivar tu propio usuario.']);
        }

        return DB::transaction(function () use ($user, $active): User {
            if (! $active) {
                $this->guard->handle($user, 'user');
            }

            $user->auditAs($active ? AuditEvent::Reactivated : AuditEvent::Deactivated)
                ->forceFill(['deactivated_at' => $active ? null : now()])
                ->save();

            return $user;
        });
    }
}
