<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CfdiCancellationMotive;
use App\Enums\CfdiStatus;
use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * CFDI de un donativo. No copia datos fiscales del emisor ni del receptor:
 * lo emitido es el XML timbrado, guardado en almacenamiento privado.
 *
 * @property int $id
 * @property int|null $donation_id
 * @property int|null $global_cfdi_id
 * @property int|null $substitutes_cfdi_id CFDI original al que sustituye (motivo 01).
 * @property bool $replacement_pending Sustitución timbrada o en curso cuyo original aún no se cancela.
 * @property string|null $substitution_reason
 * @property string $provider
 * @property string|null $external_id
 * @property string|null $uuid
 * @property string|null $series
 * @property string|null $folio
 * @property CfdiStatus $status
 * @property string $total
 * @property string $currency
 * @property string $idempotency_key
 * @property int|null $requested_by_id
 * @property CarbonInterface $requested_at
 * @property int $attempts
 * @property string|null $last_error_code
 * @property string|null $last_error
 * @property CarbonInterface|null $stamped_at
 * @property string|null $xml_path
 * @property string|null $pdf_path
 * @property CfdiCancellationMotive|null $cancellation_motive
 * @property string|null $cancellation_replacement_uuid
 * @property string|null $cancellation_reason
 * @property CarbonInterface|null $cancellation_requested_at
 * @property int|null $cancellation_requested_by_id
 * @property string|null $cancellation_provider_status
 * @property CarbonInterface|null $cancelled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Donation|null $donation
 * @property-read GlobalCfdi|null $globalCfdi
 * @property-read User|null $requestedBy
 * @property-read User|null $cancellationRequestedBy
 * @property-read Cfdi|null $substitutes
 */
class Cfdi extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    public static function auditValueFields(): array
    {
        return [
            'donation_id', 'global_cfdi_id', 'substitutes_cfdi_id', 'replacement_pending', 'substitution_reason', 'provider', 'external_id', 'uuid', 'series', 'folio', 'status', 'total', 'requested_by_id',
            'stamped_at', 'last_error_code', 'cancellation_motive', 'cancellation_replacement_uuid', 'cancellation_reason',
            'cancellation_requested_at', 'cancellation_requested_by_id', 'cancellation_provider_status', 'cancelled_at',
        ];
    }

    public static function auditNameOnlyFields(): array
    {
        return ['xml_path', 'pdf_path'];
    }

    /**
     * @return BelongsTo<Donation, $this>
     */
    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    /**
     * @return BelongsTo<GlobalCfdi, $this>
     */
    public function globalCfdi(): BelongsTo
    {
        return $this->belongsTo(GlobalCfdi::class);
    }

    /**
     * @return BelongsTo<Cfdi, $this>
     */
    public function substitutes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'substitutes_cfdi_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancellationRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancellation_requested_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CfdiStatus::class,
            'replacement_pending' => 'boolean',
            'total' => 'decimal:2',
            'requested_at' => 'datetime',
            'stamped_at' => 'datetime',
            'cancellation_motive' => CfdiCancellationMotive::class,
            'cancellation_requested_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
