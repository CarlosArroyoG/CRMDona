<?php

declare(strict_types=1);

namespace App\Actions\Donations;

use App\Actions\Cfdi\IssueCfdiAutomatically;
use App\Actions\Communications\QueueDonationThankYou;
use App\Enums\AuditEvent;
use App\Enums\DonationStatus;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Por confirmar → Confirmado. A partir de aquí el donativo podrá tener su
 * recibo simple (fase posterior); sus datos sustantivos ya no cambian.
 */
class ConfirmDonation
{
    public const string NOT_PENDING = 'Solo se pueden confirmar donativos "Por confirmar".';

    /**
     * @throws ValidationException
     */
    public function handle(Donation $donation, User $actor): Donation
    {
        return DB::transaction(function () use ($donation, $actor): Donation {
            $locked = Donation::query()->lockForUpdate()->findOrFail($donation->id);

            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['donation' => self::NOT_PENDING]);
            }

            $locked->auditAs(AuditEvent::Confirmed)->forceFill([
                'status' => DonationStatus::Confirmed,
                'confirmed_at' => now(),
                'confirmed_by_id' => $actor->id,
            ])->save();

            // Primero se registra el agradecimiento (adjunta el CFDI si llega a tiempo); después el CFDI.
            DB::afterCommit(fn () => rescue(fn () => app(QueueDonationThankYou::class)->handle($locked)));
            DB::afterCommit(fn () => app(IssueCfdiAutomatically::class)->handle($locked));

            return $locked;
        });
    }
}
