<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Models\Campaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Solo sin donativos (la llave foránea también lo impide). Lo habitual es
 * archivarla.
 */
class DeleteCampaign
{
    public const string HAS_DONATIONS = 'Esta campaña tiene donativos y no puede eliminarse. Puedes archivarla.';

    /**
     * @throws ValidationException
     */
    public function handle(Campaign $campaign): void
    {
        DB::transaction(function () use ($campaign): void {
            if ($campaign->donations()->exists()) {
                throw ValidationException::withMessages(['campaign' => self::HAS_DONATIONS]);
            }

            $campaign->delete();
        });
    }
}
