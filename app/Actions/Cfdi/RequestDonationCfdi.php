<?php

declare(strict_types=1);

namespace App\Actions\Cfdi;

use App\Cfdi\CfdiProviderRegistry;
use App\Cfdi\Exceptions\CfdiNotReadyException;
use App\Enums\CfdiStatus;
use App\Enums\Permission;
use App\Jobs\StampCfdi;
use App\Models\Cfdi;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Solicita el CFDI de un donativo. Idempotente: un donativo tiene como
 * máximo un CFDI vigente (índice único) y la misma llave devuelve el mismo
 * CFDI. Valida que el borrador se pueda armar ANTES de encolar; el timbrado
 * ocurre en la cola (StampCfdi), fuera de toda transacción.
 *
 * `$actor` nulo = solicitud automática (sin usuario "sistema").
 */
class RequestDonationCfdi
{
    public function __construct(
        private readonly CfdiProviderRegistry $registry,
        private readonly BuildDonationCfdiDraft $draft,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Donation $donation, ?User $actor, ?string $idempotencyKey = null): Cfdi
    {
        if ($actor !== null && ! $actor->hasPermission(Permission::IssueCfdis)) {
            throw new AuthorizationException('No tienes permiso para emitir CFDI.');
        }

        $provider = $this->registry->current();

        try {
            $this->draft->handle($donation);
        } catch (CfdiNotReadyException $exception) {
            throw ValidationException::withMessages(['cfdi' => $exception->reasons]);
        }

        $key = $idempotencyKey ?? "donation:{$donation->id}:cfdi";

        $cfdi = DB::transaction(function () use ($donation, $actor, $key, $provider): Cfdi {
            $locked = Donation::query()->lockForUpdate()->findOrFail($donation->id);

            $existing = Cfdi::query()->where('idempotency_key', $key)->first()
                ?? Cfdi::query()->where('donation_id', $locked->id)->whereNotIn('status', CfdiStatus::inactiveValues())->first();
            if ($existing !== null) {
                if ($existing->donation_id !== $locked->id) {
                    throw ValidationException::withMessages(['cfdi' => 'Esa solicitud ya se usó para otro donativo.']);
                }

                return $existing;
            }

            return Cfdi::query()->create([
                'donation_id' => $locked->id,
                'provider' => $provider->name(),
                'status' => CfdiStatus::Pending,
                'total' => $locked->amount,
                'currency' => $locked->currency,
                'idempotency_key' => $key,
                'requested_by_id' => $actor?->id,
                'requested_at' => now(),
            ]);
        });

        if ($cfdi->wasRecentlyCreated) {
            StampCfdi::dispatch($cfdi->id)->afterCommit();
        }

        return $cfdi;
    }
}
