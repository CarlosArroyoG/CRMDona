<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentProvider;
use App\Enums\WebhookEventStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Notificación recibida de un proveedor (bandeja de entrada). `payload`
 * contiene solo los campos de la lista permitida del proveedor; nunca el
 * cuerpo crudo, firmas ni datos personales.
 *
 * @property int $id
 * @property PaymentProvider $provider
 * @property string $external_event_id
 * @property string $event_type
 * @property string|null $resource_type
 * @property string|null $resource_external_id
 * @property Carbon|null $provider_created_at
 * @property array<string, mixed> $payload
 * @property Carbon $received_at
 * @property WebhookEventStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $processed_at
 * @property int|null $payment_id
 * @property int|null $subscription_id
 * @property int|null $refund_id
 * @property int|null $dispute_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class WebhookEvent extends Model
{
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<Refund, $this>
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    /**
     * @return BelongsTo<PaymentDispute, $this>
     */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(PaymentDispute::class, 'dispute_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'provider_created_at' => 'datetime',
            'payload' => 'array',
            'received_at' => 'datetime',
            'status' => WebhookEventStatus::class,
            'processed_at' => 'datetime',
        ];
    }
}
