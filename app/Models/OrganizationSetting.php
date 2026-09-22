<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaxRegime;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Configuración de la organización: una sola fila (id = 1). Sin secretos.
 *
 * @property int $id
 * @property string|null $legal_name
 * @property string|null $rfc
 * @property TaxRegime|null $tax_regime
 * @property string|null $tax_postal_code
 * @property string|null $authorization_number
 * @property Carbon|null $authorization_date
 * @property string|null $donation_legend
 * @property string|null $logo_path
 * @property string|null $email_signature
 * @property string|null $privacy_notice_url
 * @property string|null $privacy_notice_version
 */
#[Fillable([
    'legal_name', 'rfc', 'tax_regime', 'tax_postal_code', 'authorization_number', 'authorization_date',
    'donation_legend', 'logo_path', 'email_signature', 'privacy_notice_url', 'privacy_notice_version',
])]
class OrganizationSetting extends Model
{
    use Auditable;

    public static function current(): self
    {
        $settings = static::query()->find(1);
        if ($settings !== null) {
            return $settings;
        }

        // La fila única se crea una vez (sin carrera: ON CONFLICT DO NOTHING).
        static::query()->insertOrIgnore(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return static::query()->findOrFail(1);
    }

    public static function auditValueFields(): array
    {
        return [
            'legal_name', 'rfc', 'tax_regime', 'tax_postal_code', 'authorization_number', 'authorization_date',
            'donation_legend', 'logo_path', 'email_signature', 'privacy_notice_url', 'privacy_notice_version',
        ];
    }

    public static function auditNameOnlyFields(): array
    {
        return [];
    }

    /**
     * Sin URL y versión configuradas no se puede registrar la aceptación del
     * aviso de privacidad: no hay evidencia de qué aceptó el donante.
     */
    public function hasPrivacyNotice(): bool
    {
        return filled($this->privacy_notice_url) && filled($this->privacy_notice_version);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tax_regime' => TaxRegime::class,
            'authorization_date' => 'date',
        ];
    }
}
