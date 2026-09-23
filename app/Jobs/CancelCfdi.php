<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Cfdi\CfdiProviderRegistry;
use App\Cfdi\Data\CancellationResult;
use App\Cfdi\Exceptions\CfdiRejectedException;
use App\Enums\AuditSource;
use App\Enums\CfdiStatus;
use App\Models\Cfdi;
use App\Support\AuditOrigin;
use App\Support\SensitiveData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Envía la cancelación al PAC o consulta su estado (`$poll`). Resultados
 * [V SAT]: cancelado; en espera de la aceptación del receptor (se vuelve a
 * consultar en la conciliación); o rechazado, con lo que el CFDI sigue
 * vigente.
 */
class CancelCfdi implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 1800];

    public function __construct(public readonly int $cfdiId, public readonly bool $poll = false) {}

    public function handle(CfdiProviderRegistry $registry, AuditOrigin $origin): void
    {
        $cfdi = Cfdi::query()->find($this->cfdiId);
        if ($cfdi === null || $cfdi->status !== CfdiStatus::CancellationPending || $cfdi->uuid === null || $cfdi->cancellation_motive === null) {
            return;
        }

        $provider = $registry->current();

        try {
            $result = $this->poll
                ? $provider->cancellationStatus($cfdi->uuid, $cfdi->external_id)
                : $provider->cancel($cfdi->uuid, $cfdi->external_id, $cfdi->cancellation_motive, $cfdi->cancellation_replacement_uuid);
        } catch (CfdiRejectedException $exception) {
            $result = new CancellationResult(CancellationResult::REJECTED, SensitiveData::safeText($exception->getMessage(), 50) ?? 'rejected');
        }

        $origin->run(AuditSource::Synchronization, fn () => DB::transaction(function () use ($result): void {
            $locked = Cfdi::query()->lockForUpdate()->findOrFail($this->cfdiId);
            if ($locked->status !== CfdiStatus::CancellationPending) {
                return;
            }

            $locked->forceFill(match ($result->outcome) {
                CancellationResult::CANCELLED => ['status' => CfdiStatus::Cancelled, 'cancelled_at' => now()],
                // Rechazada por el receptor o por el SAT: el CFDI sigue vigente.
                CancellationResult::REJECTED => ['status' => CfdiStatus::Stamped],
                default => [],
            } + ['cancellation_provider_status' => mb_substr($result->providerStatus, 0, 50)])->save();
        }));
    }
}
