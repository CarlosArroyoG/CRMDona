<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DisputeStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\RefundState;
use App\Enums\RefundStatus;
use App\Enums\RetryOwner;
use App\Models\Concerns\Auditable;
use App\Support\Money;
use Carbon\CarbonInterface;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Un cobro concreto (pago único o mensualidad). Sus intentos, reembolsos y
 * disputas viven en tablas propias. Como máximo origina un donativo, y solo
 * cuando queda `succeeded`. Solo lo escriben las Actions de pagos.
 *
 * @property int $id
 * @property PaymentProvider $provider
 * @property string|null $external_id
 * @property PaymentKind $kind
 * @property int|null $subscription_id
 * @property CarbonInterface|null $billing_period_start
 * @property int $donor_id
 * @property int|null $program_id
 * @property int|null $campaign_id
 * @property string $amount
 * @property string $currency
 * @property PaymentStatus $status
 * @property string|null $provider_status
 * @property CarbonInterface|null $provider_updated_at
 * @property RetryOwner|null $next_retry_owner
 * @property CarbonInterface|null $next_retry_at
 * @property CarbonInterface|null $succeeded_at
 * @property CarbonInterface|null $failed_at
 * @property string $idempotency_key
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property string|null $refunds_succeeded_sum_amount Solo con withRefundedAmount().
 * @property-read Donor $donor
 * @property-read Program|null $program
 * @property-read Campaign|null $campaign
 * @property-read Subscription|null $subscription
 * @property-read Donation|null $donation
 */
class Payment extends Model
{
    use Auditable;

    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    public static function auditValueFields(): array
    {
        return [
            'provider', 'external_id', 'kind', 'subscription_id', 'billing_period_start', 'donor_id', 'program_id',
            'campaign_id', 'amount', 'currency', 'status', 'provider_status', 'next_retry_owner', 'next_retry_at',
            'succeeded_at', 'failed_at',
        ];
    }

    public static function auditNameOnlyFields(): array
    {
        return [];
    }

    /**
     * Importe reembolsado con éxito (los pendientes todavía no cuentan).
     *
     * @return numeric-string
     */
    public function refundedAmount(): string
    {
        $sum = $this->refunds_succeeded_sum_amount
            ?? $this->refunds()->where('status', RefundStatus::Succeeded->value)->sum('amount');

        return bcadd(is_numeric($sum) ? (string) $sum : '0', '0', 2);
    }

    /**
     * Importe que todavía puede reembolsarse: descuenta los reembolsos
     * pendientes y exitosos (los que reservan saldo).
     *
     * @return numeric-string
     */
    public function refundableAmount(): string
    {
        $reserved = $this->refunds()
            ->whereIn('status', [RefundStatus::Pending->value, RefundStatus::Succeeded->value])
            ->sum('amount');

        return Money::subtract($this->amount, (string) $reserved);
    }

    public function refundState(): RefundState
    {
        return RefundState::fromAmounts($this->amount, $this->refundedAmount());
    }

    public function hasOpenDispute(): bool
    {
        return $this->disputes()
            ->whereIn('status', [DisputeStatus::Open->value, DisputeStatus::UnderReview->value])
            ->exists();
    }

    /**
     * Precarga la suma de reembolsos exitosos para listas y exportaciones.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function withRefundedAmount(Builder $query): Builder
    {
        return $query->withSum(['refunds as refunds_succeeded_sum_amount' => fn (Builder $refunds) => $refunds
            ->where('status', RefundStatus::Succeeded->value)], 'amount');
    }

    /**
     * Filtra por situación de reembolso (se calcula, no se guarda).
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function whereRefundState(Builder $query, RefundState $state): Builder
    {
        $sum = '(select coalesce(sum(amount), 0) from refunds where refunds.payment_id = payments.id and refunds.status = ?)';
        $succeeded = RefundStatus::Succeeded->value;

        return match ($state) {
            RefundState::None => $query->whereRaw("{$sum} = 0", [$succeeded]),
            RefundState::Full => $query->whereRaw("{$sum} >= payments.amount", [$succeeded]),
            RefundState::Partial => $query->whereRaw("{$sum} > 0 and {$sum} < payments.amount", [$succeeded, $succeeded]),
        };
    }

    /**
     * Programa para reportes: el directo o el de su campaña.
     */
    public function effectiveProgram(): ?Program
    {
        return $this->program ?? $this->campaign?->program;
    }

    /**
     * @return BelongsTo<Donor, $this>
     */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return HasMany<PaymentAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class)->orderBy('attempt_number');
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * @return HasMany<PaymentDispute, $this>
     */
    public function disputes(): HasMany
    {
        return $this->hasMany(PaymentDispute::class);
    }

    /**
     * @return HasMany<PaymentIncident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(PaymentIncident::class);
    }

    /**
     * @return HasOne<Donation, $this>
     */
    public function donation(): HasOne
    {
        return $this->hasOne(Donation::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'kind' => PaymentKind::class,
            'billing_period_start' => 'date',
            'amount' => 'decimal:2',
            'status' => PaymentStatus::class,
            'provider_updated_at' => 'datetime',
            'next_retry_owner' => RetryOwner::class,
            'next_retry_at' => 'datetime',
            'succeeded_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
