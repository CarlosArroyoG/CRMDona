<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Enums\AccountingNoticeStatus;
use App\Enums\DonationStatus;
use App\Enums\Permission;
use App\Jobs\SendAccountingNotice;
use App\Models\AccountingNotice;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vuelve a enviar el aviso a Contabilidad de un donativo cuando falló o no
 * se envió (por ejemplo, no había destinatarios configurados). Quien ya lo
 * recibió no lo recibe dos veces.
 */
class RetryAccountingNotice
{
    public function __construct(private readonly QueueAccountingNotice $queue) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Donation $donation, User $actor): AccountingNotice
    {
        if (! $actor->hasPermission(Permission::ProcessAccounting)) {
            throw new AuthorizationException('No tienes permiso para reenviar avisos a Contabilidad.');
        }

        if ($donation->status !== DonationStatus::Confirmed) {
            throw ValidationException::withMessages(['notice' => 'Solo los donativos confirmados tienen aviso a Contabilidad.']);
        }

        $notice = AccountingNotice::query()->where('donation_id', $donation->id)->first();
        if ($notice === null) {
            return $this->queue->handle($donation) ?? throw ValidationException::withMessages(['notice' => 'No se pudo registrar el aviso.']);
        }

        return DB::transaction(function () use ($notice): AccountingNotice {
            $locked = AccountingNotice::query()->lockForUpdate()->findOrFail($notice->id);
            if (! $locked->status->canRetry()) {
                throw ValidationException::withMessages(['notice' => 'El aviso ya se envió o está en proceso.']);
            }

            $locked->forceFill(['status' => AccountingNoticeStatus::Pending, 'skip_reason' => null])->save();
            SendAccountingNotice::dispatch($locked->id)->afterCommit();

            return $locked;
        });
    }
}
