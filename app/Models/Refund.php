<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentProvider;
use App\Enums\RefundReason;
use App\Enums\RefundSource;
use App\Enums\RefundStatus;
use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Reembolso de un pago. Nunca borra ni cancela el pago ni el donativo. La
 * suma de reembolsos pendientes y exitosos no supera el importe del pago
 * (Action + trigger `refunds_within_payment_amount`).
 *
 * @property int $id
 * @property int $payment_id
 * @property PaymentProvider $provider
 * @property string|null $external_id
 * @property string $amount
 * @property RefundStatus $status
 * @property RefundReason $reason
 * @property string|null $reason_comment
 * @property string|null $provider_reason
 * @property string|null $failure_reason
 * @property RefundSource $source
 * @property int|null $requested_by_id
 * @property CarbonInterface $requested_at
 * @property CarbonInterface|null $processed_at
 * @property CarbonInterface|null $provider_updated_at
 * @property string $idempotency_key
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Payment $payment
 * @property-read User|null $requestedBy
 */
class Refund extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    public static function auditValueFields(): array
    {
        return [
            'payment_id', 'provider', 'external_id', 'amount', 'status', 'reason', 'reason_comment', 'provider_reason',
            'failure_reason', 'source', 'requested_by_id', 'requested_at', 'processed_at',
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
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'amount' => 'decimal:2',
            'status' => RefundStatus::class,
            'reason' => RefundReason::class,
            'source' => RefundSource::class,
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
            'provider_updated_at' => 'datetime',
        ];
    }
}
