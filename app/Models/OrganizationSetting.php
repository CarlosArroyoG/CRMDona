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
 * @property string|null $privacy_address Domicilio del responsable (aviso publicado por el CRM).
 * @property string|null $privacy_contact_email Correo para asuntos de privacidad y derechos ARCO.
 * @property string|null $online_donation_min_amount Mínimo de negocio; nulo = solo el límite técnico del proveedor.
 * @property string|null $online_donation_max_amount Máximo de negocio; nulo = sin máximo adicional del CRM.
 * @property bool $thank_you_emails_enabled Agradecimiento automático al confirmar un donativo.
 * @property bool $birthday_emails_enabled Felicitación diaria de cumpleaños.
 */
#[Fillable([
    'legal_name', 'rfc', 'tax_regime', 'tax_postal_code', 'authorization_number', 'authorization_date',
    'donation_legend', 'logo_path', 'email_signature', 'privacy_notice_url', 'privacy_notice_version',
    'online_donation_min_amount', 'online_donation_max_amount', 'thank_you_emails_enabled', 'birthday_emails_enabled',
    'privacy_address', 'privacy_contact_email',
])]
class OrganizationSetting extends Model
{
    public const string DEFAULT_DONATION_LEGEND = 'Este comprobante ampara un donativo, el cual será destinado por la donataria a los fines propios de su objeto social. En el caso de que los bienes donados hayan sido deducidos previamente para los efectos del impuesto sobre la renta, este donativo no es deducible.';

    use Auditable;

    public static function current(): self
    {
        $settings = static::query()->find(1);
        if ($settings !== null) {
            return $settings;
        }

        // La fila única se crea una vez (sin carrera: ON CONFLICT DO NOTHING).
        static::query()->insertOrIgnore(['id' => 1, 'donation_legend' => self::DEFAULT_DONATION_LEGEND, 'created_at' => now(), 'updated_at' => now()]);

        return static::query()->findOrFail(1);
    }

    public static function auditValueFields(): array
    {
        return [
            'legal_name', 'rfc', 'tax_regime', 'tax_postal_code', 'authorization_number', 'authorization_date',
            'donation_legend', 'logo_path', 'email_signature', 'privacy_notice_url', 'privacy_notice_version',
            'online_donation_min_amount', 'online_donation_max_amount', 'thank_you_emails_enabled', 'birthday_emails_enabled',
            'privacy_address', 'privacy_contact_email',
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
            'online_donation_min_amount' => 'decimal:2',
            'online_donation_max_amount' => 'decimal:2',
            'thank_you_emails_enabled' => 'boolean',
            'birthday_emails_enabled' => 'boolean',
        ];
    }
}
