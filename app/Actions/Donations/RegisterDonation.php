<?php

declare(strict_types=1);

namespace App\Actions\Donations;

use App\Enums\DonationStatus;
use App\Models\Donation;
use App\Models\User;
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
     * @throws ValidationException
     */
    public function handle(array $input, User $actor): Donation
    {
        $attributes = $this->validatedAttributes($input);

        return DB::transaction(function () use ($attributes, $actor): Donation {
            $donation = new Donation($attributes);
            $donation->forceFill([
                'status' => DonationStatus::Pending,
                'currency' => 'MXN',
                'registered_by_id' => $actor->id,
            ])->save();

            return $donation;
        });
    }
}
