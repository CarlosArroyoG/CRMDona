<?php

declare(strict_types=1);

namespace App\Actions\Donations;

use App\Enums\DonationOrigin;
use App\Enums\DonationStatus;
use App\Enums\Permission;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registra un donativo manual. Siempre nace "Por confirmar", incluso en
 * efectivo: lo confirma el Contador o el Administrador.
 */
class RegisterDonation
{
    use ValidatesDonationData;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(array $input, User $actor): Donation
    {
        if (! $actor->hasPermission(Permission::RegisterDonations)) {
            throw new AuthorizationException('No tienes permiso para registrar donativos.');
        }

        $attributes = $this->validatedAttributes($input);

        return DB::transaction(function () use ($attributes, $actor): Donation {
            $donation = new Donation($attributes);
            $donation->forceFill([
                'status' => DonationStatus::Pending,
                'currency' => 'MXN',
                'origin' => DonationOrigin::Manual,
                'registered_by_id' => $actor->id,
            ])->save();

            return $donation;
        });
    }
}
