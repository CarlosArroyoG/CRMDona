<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Cfdi\BuildDonationCfdiDraft;
use App\Actions\Cfdi\RequestCfdiSubstitution;
use App\Actions\Communications\QueueCfdiDelivery;
use App\Cfdi\CfdiProviderRegistry;
use App\Cfdi\CfdiStorage;
use App\Cfdi\Exceptions\CfdiNotReadyException;
use App\Cfdi\Exceptions\CfdiProviderUnavailableException;
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
 * Timbra un CFDI en cola.
 *
 * - Solo un proceso lo toma (pending/failed → stamping con UPDATE condicional).
 * - La llamada al PAC ocurre sin transacción abierta y siempre con la misma
 *   llave de idempotencia: un reintento tras un timeout obtiene el mismo UUID.
 * - Error temporal → `failed` y reintento con espera; rechazo por datos →
 *   `rejected` (una persona corrige y reintenta).
 */
class StampCfdi implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /** @var list<int> */
    public array $backoff;

    public function __construct(public readonly int $cfdiId)
    {
        $this->tries = config()->integer('cfdi.stamping.tries');
        /** @var list<int> $backoff */
        $backoff = config()->array('cfdi.stamping.backoff');
        $this->backoff = $backoff;
    }

    public function handle(CfdiProviderRegistry $registry, BuildDonationCfdiDraft $builder, CfdiStorage $storage, AuditOrigin $origin, QueueCfdiDelivery $cfdiDelivery): void
    {
        $origin->run(AuditSource::Job, function () use ($registry, $builder, $storage, $cfdiDelivery): void {
            $claimed = DB::table('cfdis')->where('id', $this->cfdiId)
                ->whereIn('status', [CfdiStatus::Pending->value, CfdiStatus::Failed->value])
                ->update(['status' => CfdiStatus::Stamping->value, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

            if ($claimed !== 1) {
                return;
            }

            $cfdi = Cfdi::query()->with(['donation.donor.taxProfile', 'substitutes'])->findOrFail($this->cfdiId);

            try {
                $draft = $builder->handle($cfdi->donation, $cfdi);
                $result = $registry->current()->stamp($draft, $cfdi->idempotency_key);
            } catch (CfdiNotReadyException $exception) {
                $this->finish($cfdi, CfdiStatus::Rejected, 'not_ready', $exception->getMessage());

                return;
            } catch (CfdiRejectedException $exception) {
                $this->finish($cfdi, CfdiStatus::Rejected, $exception->errorCode, $exception->getMessage());

                return;
            } catch (CfdiProviderUnavailableException $exception) {
                $this->finish($cfdi, CfdiStatus::Failed, 'unavailable', $exception->getMessage());

                throw $exception;
            }

            $paths = $storage->store($cfdi, $result->uuid, $result->xml, $result->pdf);

            $cancelOriginal = DB::transaction(function () use ($cfdi, $result, $draft, $paths): ?int {
                $cfdi->forceFill([
                    'status' => CfdiStatus::Stamped,
                    'uuid' => $result->uuid,
                    'external_id' => $result->externalId,
                    'series' => $draft->series,
                    'folio' => $draft->folio,
                    'stamped_at' => $result->stampedAt,
                    'xml_path' => $paths['xml'],
                    'pdf_path' => $paths['pdf'],
                    'last_error_code' => null,
                    'last_error' => null,
                ])->save();

                return $this->requestOriginalCancellation($cfdi);
            });

            if ($cancelOriginal !== null) {
                CancelCfdi::dispatch($cancelOriginal);
            }

            // Fase 4: enviar el CFDI al donante (una vez; sin repetir el agradecimiento).
            // Un error de correo nunca revierte ni reintenta el timbrado.
            rescue(fn () => $cfdiDelivery->handle($cfdi));
        });
    }

    /**
     * Sustitución (motivo 01): timbrado el sustituto, el original pasa a
     * cancelación con el UUID nuevo [V SAT, esquema de cancelación 2026].
     */
    private function requestOriginalCancellation(Cfdi $replacement): ?int
    {
        if (! $replacement->replacement_pending || $replacement->substitutes_cfdi_id === null) {
            return null;
        }

        $original = Cfdi::query()->lockForUpdate()->findOrFail($replacement->substitutes_cfdi_id);
        if ($original->status !== CfdiStatus::Stamped) {
            return null;
        }

        RequestCfdiSubstitution::markOriginalForCancellation($original, $replacement, (string) $replacement->substitution_reason, $replacement->requested_by_id);

        return $original->id;
    }

    private function finish(Cfdi $cfdi, CfdiStatus $status, ?string $code, string $message): void
    {
        $cfdi->forceFill([
            'status' => $status,
            'last_error_code' => $code !== null ? mb_substr($code, 0, 100) : null,
            'last_error' => SensitiveData::safeText($message),
        ])->save();
    }
}
