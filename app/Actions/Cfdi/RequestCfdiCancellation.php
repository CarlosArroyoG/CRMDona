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
use Illuminate\Validation\ValidationException;

/**
 * Solicita la cancelación de un CFDI timbrado. Nunca es automática: la
 * decide una persona con permiso y queda auditada con motivo SAT y razón.
 * No cancela el donativo ni el pago.
 *
 * Motivos disponibles: 02 y 03. El 01 (con sustitución) y el 04 requieren
 * el flujo de CFDI relacionado, pendiente del PAC y de decisión fiscal [F]/[S].
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
                'motive' => ['required', 'in:02,03'],
                'reason' => ['required', 'string', 'min:5', 'max:1000'],
            ],
            ['motive.in' => 'Por ahora solo se cancela con motivo 02 o 03; la sustitución (01) y el 04 están pendientes.'],
            ['motive' => 'motivo de cancelación', 'reason' => 'razón'],
        )->validate();

        $locked = DB::transaction(function () use ($cfdi, $data, $actor): Cfdi {
            $locked = Cfdi::query()->lockForUpdate()->findOrFail($cfdi->id);
            if ($locked->status !== CfdiStatus::Stamped) {
                throw ValidationException::withMessages(['cfdi' => 'Solo se cancela un CFDI timbrado y vigente.']);
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
