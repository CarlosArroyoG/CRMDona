<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DisputeStatus;
use App\Enums\PaymentProvider;
use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Disputa o contracargo de un pago. No altera el pago, el donativo, el
 * recibo ni el CFDI; abre una incidencia crítica.
 *
 * @property int $id
 * @property int $payment_id
 * @property PaymentProvider $provider
 * @property string $external_id
 * @property string|null $amount
 * @property string $currency
 * @property DisputeStatus $status
 * @property string|null $provider_status
 * @property string|null $provider_reason
 * @property CarbonInterface|null $opened_at
 * @property CarbonInterface|null $evidence_due_at
 * @property CarbonInterface|null $closed_at
 * @property CarbonInterface|null $provider_updated_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Payment $payment
 */
class PaymentDispute extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    public static function auditValueFields(): array
    {
        return [
            'payment_id', 'provider', 'external_id', 'amount', 'currency', 'status', 'provider_status', 'provider_reason',
            'opened_at', 'evidence_due_at', 'closed_at',
        ];
    }

    public static function auditNameOnlyFields(): array
    {
        return [];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'amount' => 'decimal:2',
            'status' => DisputeStatus::class,
            'opened_at' => 'datetime',
            'evidence_due_at' => 'datetime',
            'closed_at' => 'datetime',
            'provider_updated_at' => 'datetime',
        ];
    }
}
