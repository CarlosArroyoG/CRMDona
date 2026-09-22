<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Desactiva o reactiva un usuario. Un usuario desactivado no entra al panel.
 */
class SetUserActive
{
    public function __construct(private readonly EnsureActiveAdministratorRemains $guard) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $user, bool $active, User $actor): User
    {
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
