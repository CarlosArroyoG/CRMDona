<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FailureCategory;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\PaymentProvider;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Incidencia de pagos (RF-01). `dedupe_key` identifica el hecho concreto:
 * el mismo hecho no se duplica, un hecho distinto sobre el mismo pago abre
 * otra. Leer la notificación no cambia su estado.
 *
 * @property int $id
 * @property IncidentType $type
 * @property IncidentSeverity $severity
 * @property FailureCategory|null $failure_category
 * @property PaymentProvider|null $provider
 * @property int|null $payment_id
 * @property int|null $payment_attempt_id
 * @property int|null $subscription_id
 * @property int|null $refund_id
 * @property int|null $dispute_id
 * @property int|null $webhook_event_id
 * @property IncidentStatus $status
 * @property Carbon $detected_at
 * @property int|null $reviewing_by_id
 * @property Carbon|null $reviewing_started_at
 * @property int|null $resolved_by_id
 * @property Carbon|null $resolved_at
 * @property string|null $resolution
 * @property string $dedupe_key
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Payment|null $payment
 * @property-read Subscription|null $subscription
 */
class PaymentIncident extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    public static function auditValueFields(): array
    {
        return [
            'type', 'severity', 'failure_category', 'provider', 'payment_id', 'payment_attempt_id', 'subscription_id',
            'refund_id', 'dispute_id', 'webhook_event_id', 'status', 'detected_at', 'reviewing_by_id',
            'reviewing_started_at', 'resolved_by_id', 'resolved_at', 'resolution',
        ];
    }

    public static function auditNameOnlyFields(): array
    {
        return [];
    }

    /**
     * Solo los tipos operativos, para quien no atiende incidencias técnicas.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOperational(Builder $query): void
    {
        $query->whereIn('type', array_map(fn (IncidentType $type): string => $type->value, IncidentType::operational()));
    }

    /**
     * Donante afectado, a partir del pago o la suscripción relacionados.
     */
    public function donor(): ?Donor
    {
        return $this->payment->donor ?? $this->subscription?->donor;
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<PaymentAttempt, $this>
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
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
     * @return BelongsTo<WebhookEvent, $this>
     */
    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewingBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewing_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    /**
     * @return HasMany<PaymentIncidentNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(PaymentIncidentNote::class)->orderBy('created_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => IncidentType::class,
            'severity' => IncidentSeverity::class,
            'failure_category' => FailureCategory::class,
            'provider' => PaymentProvider::class,
            'status' => IncidentStatus::class,
            'detected_at' => 'datetime',
            'reviewing_started_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
