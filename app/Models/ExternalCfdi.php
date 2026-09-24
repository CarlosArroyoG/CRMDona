<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * CFDI externo: comprobante fiscal que emitió contabilidad FUERA del CRM y
 * que se conserva aquí como antecedente documental del donativo. El CRM no lo
 * emite, timbra, cancela ni sustituye, ni certifica su validez fiscal.
 *
 * `crm_legacy` = CFDI que el CRM timbró antes del cambio de alcance (se
 * conservan sus archivos originales). Retirar un antecedente no borra nada:
 * queda con fecha, persona y motivo.
 *
 * @property int $id
 * @property int $donation_id
 * @property string $uuid
 * @property CarbonInterface $issued_at
 * @property CarbonInterface|null $stamped_at
 * @property string|null $total
 * @property string $xml_path
 * @property string|null $pdf_path
 * @property string|null $notes
 * @property string $source
 * @property int|null $legacy_cfdi_id
 * @property int|null $legacy_global_cfdi_id
 * @property int|null $uploaded_by_id
 * @property CarbonInterface $uploaded_at
 * @property CarbonInterface|null $removed_at
 * @property int|null $removed_by_id
 * @property string|null $removal_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Donation $donation
 * @property-read User|null $uploadedBy
 * @property-read User|null $removedBy
 */
class ExternalCfdi extends Model
{
    use Auditable;

    public const string SOURCE_UPLOAD = 'upload';

    public const string SOURCE_CRM_LEGACY = 'crm_legacy';

    protected $guarded = ['id'];

    public static function auditValueFields(): array
    {
        return ['donation_id', 'uuid', 'issued_at', 'stamped_at', 'total', 'source', 'uploaded_by_id', 'uploaded_at', 'removed_at', 'removed_by_id', 'removal_reason'];
    }

    public static function auditNameOnlyFields(): array
    {
        return ['xml_path', 'pdf_path', 'notes'];
    }

    public function isActive(): bool
    {
        return $this->removed_at === null;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    /**
     * @return BelongsTo<Donation, $this>
     */
    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'stamped_at' => 'datetime',
            'uploaded_at' => 'datetime',
            'removed_at' => 'datetime',
            'total' => 'decimal:2',
        ];
    }
}
