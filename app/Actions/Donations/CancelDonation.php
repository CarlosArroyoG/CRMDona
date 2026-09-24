<?php

declare(strict_types=1);

namespace App\Actions\Donations;

use App\Enums\AuditEvent;
use App\Enums\DonationStatus;
use App\Enums\Permission;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Cancela un donativo "Por confirmar" o "Confirmado" con motivo. El donativo
 * nunca se elimina: queda en el historial como "Cancelado".
 */
class CancelDonation
{
    public const string ALREADY_CANCELLED = 'Este donativo ya está cancelado.';

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Donation $donation, ?string $reason, User $actor): Donation
    {
        if (! $actor->hasPermission(Permission::ConfirmDonations)) {
            throw new AuthorizationException('No tienes permiso para cancelar donativos.');
        }

        $data = Validator::make(
            ['cancellation_reason' => $reason !== null ? trim($reason) : null],
            ['cancellation_reason' => ['required', 'string', 'min:5', 'max:1000']],
            [],
            ['cancellation_reason' => 'motivo de cancelación'],
        )->validate();

        return DB::transaction(function () use ($donation, $data, $actor): Donation {
            $locked = Donation::query()->lockForUpdate()->findOrFail($donation->id);

            if ($locked->status === DonationStatus::Cancelled) {
                throw ValidationException::withMessages(['donation' => self::ALREADY_CANCELLED]);
            }

            $locked->auditAs(AuditEvent::Cancelled)->forceFill([
                'status' => DonationStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by_id' => $actor->id,
                'cancellation_reason' => $data['cancellation_reason'],
            ])->save();

            return $locked;
        });
    }
}
