<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedEnum;
use App\Enums\CfdiUse;
use App\Enums\TaxRegime;
use App\Models\Concerns\Auditable;
use App\Support\BlindIndex;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Datos fiscales del donante, solo si pidió comprobante deducible. Se envían
 * a Contabilidad en el aviso del donativo; el CRM no emite CFDI.
 *
 * Todo se guarda cifrado con APP_KEY (docs/tecnico/proteccion-de-datos.md):
 * una copia robada de la base no los revela. El RFC se busca y compara por
 * su huella `rfc_hash` (BlindIndex), nunca por el texto.
 *
 * @property int $id
 * @property int $donor_id
 * @property string $rfc
 * @property string $tax_name
 * @property TaxRegime $tax_regime
 * @property string $tax_postal_code
 * @property CfdiUse|null $cfdi_use
 * @property string|null $rfc_hash Huella HMAC del RFC para buscarlo sin descifrar.
 */
#[Fillable(['rfc', 'tax_name', 'tax_regime', 'tax_postal_code', 'cfdi_use'])]
class DonorTaxProfile extends Model
{
    use Auditable;

    public static function auditValueFields(): array
    {
        return [];
    }

    public static function auditNameOnlyFields(): array
    {
        return ['rfc', 'tax_name', 'tax_regime', 'tax_postal_code', 'cfdi_use'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $profile): void {
            if ($profile->isDirty('rfc') || $profile->rfc_hash === null) {
                $profile->rfc_hash = BlindIndex::rfc($profile->rfc);
            }
        });
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
            'rfc' => 'encrypted',
            'tax_name' => 'encrypted',
            'tax_regime' => EncryptedEnum::class.':'.TaxRegime::class,
            'tax_postal_code' => 'encrypted',
            'cfdi_use' => EncryptedEnum::class.':'.CfdiUse::class,
        ];
    }
}
