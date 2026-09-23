<?php

declare(strict_types=1);

namespace App\Actions\Cfdi;

use App\Enums\AuditEvent;
use App\Enums\CfdiStatus;
use App\Enums\Permission;
use App\Models\Cfdi;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Descarta un CFDI rechazado que nunca se timbró (por ejemplo, una
 * sustitución pedida por error). Solo desde `rejected`: el PAC respondió en
 * definitiva, así que no puede existir un timbre desconocido. Un error
 * temporal (`failed`) no se descarta porque su resultado es incierto.
 */
class DiscardCfdi
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Cfdi $cfdi, ?string $reason, User $actor): Cfdi
    {
        if (! $actor->hasPermission(Permission::IssueCfdis)) {
            throw new AuthorizationException('No tienes permiso para descartar CFDI.');
        }

        /** @var array{reason: string} $data */
        $data = Validator::make(
            ['reason' => $reason !== null ? trim($reason) : null],
            ['reason' => ['required', 'string', 'min:5', 'max:1000']],
            [],
            ['reason' => 'razón'],
        )->validate();

        return DB::transaction(function () use ($cfdi, $data): Cfdi {
            $locked = Cfdi::query()->lockForUpdate()->findOrFail($cfdi->id);
            if ($locked->status !== CfdiStatus::Rejected || $locked->uuid !== null) {
                throw ValidationException::withMessages(['cfdi' => 'Solo se descarta un CFDI rechazado que nunca se timbró.']);
            }

            $locked->auditAs(AuditEvent::Discarded, ['reason' => $data['reason']])->forceFill([
                'status' => CfdiStatus::Discarded,
                'replacement_pending' => false,
            ])->save();

            return $locked;
        });
    }
}
