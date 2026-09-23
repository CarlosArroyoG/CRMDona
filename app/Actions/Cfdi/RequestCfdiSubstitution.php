<?php

declare(strict_types=1);

namespace App\Actions\Cfdi;

use App\Cfdi\CfdiProviderRegistry;
use App\Cfdi\Exceptions\CfdiNotReadyException;
use App\Enums\AuditEvent;
use App\Enums\CfdiCancellationMotive;
use App\Enums\CfdiStatus;
use App\Enums\Permission;
use App\Jobs\CancelCfdi;
use App\Jobs\StampCfdi;
use App\Models\Cfdi;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sustituye un CFDI con errores cuando la operación subsiste (motivo 01).
 * Orden [V] del "Esquema de cancelación de CFDI 2026":
 *
 * 1. se timbra el CFDI nuevo relacionado con TipoRelacion 04 (datos actuales
 *    del donativo y del donante, ya corregidos);
 * 2. al timbrarse, se cancela el original con motivo 01 y el UUID nuevo
 *    (StampCfdi → CancelCfdi).
 *
 * Si el receptor rechaza esa cancelación, ambos siguen vigentes; volver a
 * llamar a esta acción reintenta la cancelación del original sin timbrar otro.
 */
class RequestCfdiSubstitution
{
    public function __construct(
        private readonly CfdiProviderRegistry $registry,
        private readonly BuildDonationCfdiDraft $draft,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Cfdi $original, ?string $reason, User $actor): Cfdi
    {
        if (! $actor->hasPermission(Permission::CancelCfdis) || ! $actor->hasPermission(Permission::IssueCfdis)) {
            throw new AuthorizationException('No tienes permiso para sustituir CFDI.');
        }

        /** @var array{reason: string} $data */
        $data = Validator::make(
            ['reason' => $reason !== null ? trim($reason) : null],
            ['reason' => ['required', 'string', 'min:5', 'max:1000']],
            [],
            ['reason' => 'razón'],
        )->validate();

        $provider = $this->registry->current();

        try {
            $this->draft->handle($original->donation);
        } catch (CfdiNotReadyException $exception) {
            throw ValidationException::withMessages(['cfdi' => $exception->reasons]);
        }

        [$replacement, $retryCancellation] = DB::transaction(function () use ($original, $data, $actor, $provider): array {
            $locked = Cfdi::query()->lockForUpdate()->findOrFail($original->id);
            if ($locked->status !== CfdiStatus::Stamped || $locked->replacement_pending) {
                throw ValidationException::withMessages(['cfdi' => 'Solo se sustituye un CFDI timbrado y vigente.']);
            }

            $existing = Cfdi::query()->where('substitutes_cfdi_id', $locked->id)->where('replacement_pending', true)
                ->whereNotIn('status', CfdiStatus::inactiveValues())->first();

            if ($existing !== null) {
                if ($existing->status !== CfdiStatus::Stamped || $existing->uuid === null) {
                    throw ValidationException::withMessages(['cfdi' => 'Ya hay una sustitución en curso para este CFDI.']);
                }

                // La cancelación anterior se rechazó: se vuelve a pedir con el mismo sustituto.
                self::markOriginalForCancellation($locked, $existing, $data['reason'], $actor->id);

                return [$existing, true];
            }

            if (Cfdi::query()->where('donation_id', $locked->donation_id)->where('replacement_pending', true)
                ->whereNotIn('status', CfdiStatus::inactiveValues())->exists()) {
                throw ValidationException::withMessages(['cfdi' => 'Ya hay una sustitución en curso para este donativo.']);
            }

            $replacement = Cfdi::query()->create([
                'donation_id' => $locked->donation_id,
                'substitutes_cfdi_id' => $locked->id,
                'replacement_pending' => true,
                'substitution_reason' => $data['reason'],
                'provider' => $provider->name(),
                'status' => CfdiStatus::Pending,
                'total' => $locked->donation->amount,
                'currency' => $locked->currency,
                'idempotency_key' => "substitution:{$locked->id}:".Str::uuid(),
                'requested_by_id' => $actor->id,
                'requested_at' => now(),
            ]);

            return [$replacement, false];
        });

        if ($retryCancellation) {
            CancelCfdi::dispatch($original->id)->afterCommit();
        } else {
            StampCfdi::dispatch($replacement->id)->afterCommit();
        }

        return $replacement;
    }

    /**
     * Paso 2: con el sustituto timbrado, el original pasa a cancelación con
     * motivo 01 y el UUID que lo sustituye. Debe llamarse dentro de una
     * transacción con el original bloqueado.
     */
    public static function markOriginalForCancellation(Cfdi $original, Cfdi $replacement, string $reason, ?int $actorId): void
    {
        $original->auditAs(AuditEvent::Cancelled, ['reason' => $reason])->forceFill([
            'status' => CfdiStatus::CancellationPending,
            'cancellation_motive' => CfdiCancellationMotive::ErrorsWithRelation,
            'cancellation_replacement_uuid' => $replacement->uuid,
            'cancellation_reason' => $reason,
            'cancellation_requested_at' => now(),
            'cancellation_requested_by_id' => $actorId,
            'cancellation_provider_status' => null,
        ])->save();
    }
}
