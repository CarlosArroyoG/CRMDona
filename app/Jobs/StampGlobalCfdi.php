<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Cfdi\BuildGlobalCfdiDraft;
use App\Cfdi\CfdiProviderRegistry;
use App\Cfdi\CfdiStorage;
use App\Cfdi\Exceptions\CfdiNotReadyException;
use App\Cfdi\Exceptions\CfdiProviderUnavailableException;
use App\Cfdi\Exceptions\CfdiRejectedException;
use App\Enums\CfdiStatus;
use App\Models\Cfdi;
use App\Models\GlobalCfdi;
use App\Support\SensitiveData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class StampGlobalCfdi implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /** @var list<int> */
    public array $backoff;

    public function __construct(public readonly int $globalCfdiId)
    {
        $this->tries = config()->integer('cfdi.stamping.tries');
        /** @var list<int> $backoff */
        $backoff = config()->array('cfdi.stamping.backoff');
        $this->backoff = $backoff;
    }

    public function handle(CfdiProviderRegistry $registry, BuildGlobalCfdiDraft $builder, CfdiStorage $storage): void
    {
        $cfdi = Cfdi::query()->with(['globalCfdi.donations.donor.taxProfile', 'globalCfdi'])->where('global_cfdi_id', $this->globalCfdiId)->firstOrFail();
        $claimed = DB::table('cfdis')->where('id', $cfdi->id)
            ->whereIn('status', [CfdiStatus::Pending->value, CfdiStatus::Failed->value])
            ->update(['status' => CfdiStatus::Stamping->value, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }

        $global = $cfdi->globalCfdi;
        if ($global === null) {
            return;
        }

        try {
            $draft = $builder->handle($global, $global->donations);
            $result = $registry->current()->stamp($draft, $cfdi->idempotency_key);
        } catch (CfdiNotReadyException $exception) {
            $this->finish($cfdi, CfdiStatus::Rejected, 'not_ready', $exception->getMessage());

            return;
        } catch (CfdiRejectedException $exception) {
            $this->finish($cfdi, CfdiStatus::Rejected, $exception->errorCode ?? 'rejected', $exception->getMessage());

            return;
        } catch (CfdiProviderUnavailableException $exception) {
            $this->finish($cfdi, CfdiStatus::Failed, 'unavailable', $exception->getMessage());
            throw $exception;
        }

        $paths = $storage->store($cfdi, $result->uuid, $result->xml, $result->pdf);
        DB::transaction(function () use ($cfdi, $global, $result, $draft, $paths): void {
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
            $global->forceFill(['status' => CfdiStatus::Stamped, 'uuid' => $result->uuid, 'external_id' => $result->externalId, 'series' => $draft->series, 'folio' => $draft->folio, 'stamped_at' => $result->stampedAt, 'xml_path' => $paths['xml'], 'pdf_path' => $paths['pdf']])->save();
        });
    }

    private function finish(Cfdi $cfdi, CfdiStatus $status, string $code, string $message): void
    {
        $cfdi->forceFill(['status' => $status, 'last_error_code' => $code, 'last_error' => SensitiveData::safeText($message)])->save();
        GlobalCfdi::query()->whereKey($this->globalCfdiId)->update(['status' => $status->value, 'last_error_code' => $code, 'last_error' => SensitiveData::safeText($message)]);
    }
}
