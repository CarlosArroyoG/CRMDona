<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Pausar, reanudar y cancelar: solo Administrador y Coordinador, siempre con
 * motivo (queda en la bitácora).
 */
trait ValidatesSubscriptionChange
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    protected function validatedReason(User $actor, ?string $reason): string
    {
        if (! $actor->hasPermission(Permission::ManageSubscriptions)) {
            throw new AuthorizationException('No tienes permiso para modificar donativos mensuales.');
        }

        /** @var array{reason: string} $data */
        $data = Validator::make(
            ['reason' => $reason !== null ? trim($reason) : null],
            ['reason' => ['required', 'string', 'min:5', 'max:1000']],
            [],
            ['reason' => 'motivo'],
        )->validate();

        return $data['reason'];
    }
}
