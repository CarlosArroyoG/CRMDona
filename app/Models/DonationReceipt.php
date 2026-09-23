<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Recibo simple de agradecimiento (acuse) de un donativo confirmado. NO es
 * un comprobante fiscal ni una factura (ADR-005): el CFDI vive en `cfdis`.
 * El PDF se genera una vez y se guarda en el disco privado.
 *
 * @property int $id
 * @property int $donation_id
 * @property string|null $folio
 * @property string|null $pdf_path
 * @property CarbonInterface $issued_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Donation $donation
 */
class DonationReceipt extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    public static function auditValueFields(): array
    {
        return ['donation_id', 'folio', 'issued_at'];
    }

    public static function auditNameOnlyFields(): array
    {
        return ['pdf_path'];
    }

    public static function folioFor(int $id): string
    {
        return 'R-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return BelongsTo<Donation, $this>
     */
    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['issued_at' => 'datetime'];
    }
}
