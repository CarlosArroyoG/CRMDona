<?php

declare(strict_types=1);

namespace App\Actions\Donations;

use App\Models\Donation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Solo un donativo "Por confirmar" cambia sus datos. Uno confirmado o
 * cancelado ya no: si hay un error, se cancela y se registra de nuevo.
 */
class UpdatePendingDonation
{
    use ValidatesDonationData;

    public const string NOT_PENDING = 'Solo se pueden modificar donativos "Por confirmar". Si un donativo confirmado tiene un error, cancélalo indicando el motivo y registra uno nuevo.';

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(Donation $donation, array $input): Donation
    {
        $attributes = $this->validatedAttributes($input);

        return DB::transaction(function () use ($donation, $attributes): Donation {
            $locked = Donation::query()->lockForUpdate()->findOrFail($donation->id);

            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['donation' => self::NOT_PENDING]);
            }

            $locked->fill($attributes)->save();

            return $locked;
        });
    }
}
