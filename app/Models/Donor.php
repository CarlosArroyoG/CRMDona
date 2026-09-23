<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DonorOrigin;
use App\Enums\DonorType;
use App\Models\Concerns\Auditable;
use Database\Factories\DonorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Donante: persona física o moral (ADR-003). `display_name` lo calcula
 * PostgreSQL. Se archiva; solo se elimina si no tiene donativos.
 *
 * @property int $id
 * @property DonorType $type
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $second_last_name
 * @property Carbon|null $birth_date
 * @property string|null $legal_name
 * @property string|null $contact_name
 * @property string $display_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $notes
 * @property string|null $privacy_notice_version
 * @property Carbon|null $privacy_notice_accepted_at
 * @property bool $accepts_communications
 * @property Carbon|null $communications_consent_updated_at
 * @property Carbon|null $archived_at
 * @property string|null $communications_token Enlace de baja (64 hex aleatorios).
 * @property int|null $registered_by_id Nulo solo si se registró desde la página pública.
 * @property DonorOrigin $origin
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read DonorTaxProfile|null $taxProfile
 */
#[Fillable([
    'type', 'first_name', 'last_name', 'second_last_name', 'birth_date', 'legal_name', 'contact_name',
    'email', 'phone', 'notes', 'privacy_notice_version', 'privacy_notice_accepted_at',
    'accepts_communications', 'communications_consent_updated_at',
])]
class Donor extends Model
{
    use Auditable;

    /** @use HasFactory<DonorFactory> */
    use HasFactory;

    public static function auditValueFields(): array
    {
        return [
            'type', 'archived_at', 'accepts_communications', 'communications_consent_updated_at',
            'privacy_notice_version', 'privacy_notice_accepted_at', 'registered_by_id', 'origin',
        ];
    }

    public static function auditNameOnlyFields(): array
    {
        return [
            'first_name', 'last_name', 'second_last_name', 'legal_name', 'contact_name',
            'email', 'phone', 'birth_date', 'notes',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Nombre para saludar en los correos: nombre de pila o razón social.
     */
    public function greetingName(): string
    {
        return $this->first_name ?? $this->legal_name ?? $this->display_name;
    }

    /**
     * Token no predecible del enlace de baja (se crea al primer uso). No es
     * un cambio de negocio: se guarda sin bitácora.
     */
    public function communicationsToken(): string
    {
        if ($this->communications_token === null) {
            $this->forceFill(['communications_token' => bin2hex(random_bytes(32))])->saveQuietly();
        }

        return (string) $this->communications_token;
    }

    /**
     * @return HasOne<DonorTaxProfile, $this>
     */
    public function taxProfile(): HasOne
    {
        return $this->hasOne(DonorTaxProfile::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * @return HasMany<Donation, $this>
     */
    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DonorType::class,
            'origin' => DonorOrigin::class,
            'birth_date' => 'date',
            'privacy_notice_accepted_at' => 'datetime',
            'accepts_communications' => 'boolean',
            'communications_consent_updated_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }
}
