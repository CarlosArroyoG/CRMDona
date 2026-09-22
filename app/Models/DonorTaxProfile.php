<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CfdiUse;
use App\Enums\TaxRegime;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Datos fiscales del donante, solo si pidió comprobante deducible. El CFDI
 * futuro copiará estos datos al timbrar; aquí no hay reglas fiscales.
 *
 * @property int $id
 * @property int $donor_id
 * @property string $rfc
 * @property string $tax_name
 * @property TaxRegime $tax_regime
 * @property string $tax_postal_code
 * @property CfdiUse|null $cfdi_use
 */
#[Fillable(['rfc', 'tax_name', 'tax_regime', 'tax_postal_code', 'cfdi_use'])]
class DonorTaxProfile extends Model
{
    use Auditable;

    public static function auditValueFields(): array
    {
        return ['tax_regime', 'cfdi_use'];
    }

    public static function auditNameOnlyFields(): array
    {
        return ['rfc', 'tax_name', 'tax_postal_code'];
    }

    /**
     * Valores para precargar formularios.
     *
     * @return array<string, string|null>
     */
    public function formData(): array
    {
        return [
            'rfc' => $this->rfc,
            'tax_name' => $this->tax_name,
            'tax_regime' => $this->tax_regime->value,
            'tax_postal_code' => $this->tax_postal_code,
            'cfdi_use' => $this->cfdi_use?->value,
        ];
    }

    /**
     * @return BelongsTo<Donor, $this>
     */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tax_regime' => TaxRegime::class,
            'cfdi_use' => CfdiUse::class,
        ];
    }
}
