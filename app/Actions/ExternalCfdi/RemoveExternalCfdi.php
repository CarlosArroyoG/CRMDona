<?php

declare(strict_types=1);

namespace App\Actions\ExternalCfdi;

use App\Enums\AuditEvent;
use App\Enums\Permission;
use App\Models\ExternalCfdi;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Retira un CFDI externo del donativo (por ejemplo, se adjuntó el archivo
 * equivocado o contabilidad lo reemplazó). No borra el registro ni los
 * archivos: queda como historial con fecha, persona y motivo. No cancela
 * nada ante el SAT.
 */
class RemoveExternalCfdi
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(ExternalCfdi $record, ?string $reason, User $actor): ExternalCfdi
    {
        if (! $actor->hasPermission(Permission::ManageExternalCfdis)) {
            throw new AuthorizationException('No tienes permiso para retirar CFDI externos.');
        }

        /** @var array{reason: string} $data */
        $data = Validator::make(['reason' => $reason !== null ? trim($reason) : null], ['reason' => ['required', 'string', 'min:5', 'max:1000']], [], ['reason' => 'motivo'])->validate();

        return DB::transaction(function () use ($record, $data, $actor): ExternalCfdi {
            $locked = ExternalCfdi::query()->lockForUpdate()->findOrFail($record->id);
            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['reason' => 'Ese CFDI externo ya estaba retirado.']);
            }

            $locked->auditAs(AuditEvent::Discarded, ['reason' => $data['reason']])->forceFill([
                'removed_at' => now(),
                'removed_by_id' => $actor->id,
                'removal_reason' => $data['reason'],
            ])->save();

            return $locked;
        });
    }
}
