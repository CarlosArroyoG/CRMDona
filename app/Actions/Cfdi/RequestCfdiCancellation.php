<?php

declare(strict_types=1);

namespace App\Actions\Cfdi;

use App\Enums\AuditEvent;
use App\Enums\CfdiCancellationMotive;
use App\Enums\CfdiStatus;
use App\Enums\Permission;
use App\Jobs\CancelCfdi;
use App\Models\Cfdi;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Solicita la cancelación de un CFDI timbrado. Nunca es automática: la
 * decide una persona con permiso y queda auditada con motivo SAT y razón.
 * No cancela el donativo ni el pago.
 *
 * Motivos directos [V]: 02 y 03. El 01 se pide con RequestCfdiSubstitution
 * (primero se timbra el sustituto). El 04 solo aplica a una factura global,
 * que aún no está habilitada [F].
 */
class RequestCfdiCancellation
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Cfdi $cfdi, string $motive, ?string $reason, User $actor): Cfdi
    {
        if (! $actor->hasPermission(Permission::CancelCfdis)) {
            throw new AuthorizationException('No tienes permiso para cancelar CFDI.');
        }

        /** @var array{motive: string, reason: string} $data */
        $data = Validator::make(
            ['motive' => $motive, 'reason' => $reason !== null ? trim($reason) : null],
            [
                'motive' => ['required', Rule::in(array_map(fn (CfdiCancellationMotive $motive): string => $motive->value, CfdiCancellationMotive::direct()))],
                'reason' => ['required', 'string', 'min:5', 'max:1000'],
            ],
            ['motive.in' => 'Aquí solo se cancela con motivo 02 o 03. Para el 01 usa "Sustituir CFDI"; el 04 aplica solo a la factura global, aún no habilitada.'],
            ['motive' => 'motivo de cancelación', 'reason' => 'razón'],
        )->validate();

        $locked = DB::transaction(function () use ($cfdi, $data, $actor): Cfdi {
            $locked = Cfdi::query()->lockForUpdate()->findOrFail($cfdi->id);
            if ($locked->status !== CfdiStatus::Stamped) {
                throw ValidationException::withMessages(['cfdi' => 'Solo se cancela un CFDI timbrado y vigente.']);
            }

            if (Cfdi::query()->where('substitutes_cfdi_id', $locked->id)->whereNotIn('status', CfdiStatus::inactiveValues())->exists()) {
                throw ValidationException::withMessages(['cfdi' => 'Este CFDI tiene una sustitución en curso: se cancela con motivo 01 al timbrarse el sustituto.']);
            }

            $locked->auditAs(AuditEvent::Cancelled, ['reason' => $data['reason']])->forceFill([
                'status' => CfdiStatus::CancellationPending,
                'cancellation_motive' => CfdiCancellationMotive::from($data['motive']),
                'cancellation_reason' => $data['reason'],
                'cancellation_requested_at' => now(),
                'cancellation_requested_by_id' => $actor->id,
                'cancellation_provider_status' => null,
            ])->save();

            return $locked;
        });

        CancelCfdi::dispatch($locked->id);

        return $locked;
    }
}
