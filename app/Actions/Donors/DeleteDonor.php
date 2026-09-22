<?php

declare(strict_types=1);

namespace App\Actions\Donors;

use App\Models\Donor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Elimina un donante solo si no tiene donativos (la base de datos también lo
 * impide con la llave foránea). Sus datos fiscales y etiquetas se van con él;
 * la bitácora conserva el registro de la eliminación.
 */
class DeleteDonor
{
    public const string HAS_DONATIONS = 'Este donante tiene donativos registrados y no puede eliminarse. Puedes archivarlo.';

    /**
     * @throws ValidationException
     */
    public function handle(Donor $donor): void
    {
        DB::transaction(function () use ($donor): void {
            if ($donor->donations()->exists()) {
                throw ValidationException::withMessages(['donor' => self::HAS_DONATIONS]);
            }

            $donor->delete();
        });
    }
}
