<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CancellationSource;
use App\Enums\PaymentProvider;
use App\Enums\RetryOwner;
use App\Enums\SubscriptionInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Donativo mensual en un proveedor. Cada cobro es un Payment propio. Un
 * cobro fallido nunca la cancela por sí mismo.
 *
 * @property int $id
 * @property PaymentProvider $provider
 * @property string|null $external_id
 * @property int $donor_id
 * @property int|null $program_id
 * @property int|null $campaign_id
 * @property string $amount
 * @property string $currency
 * @property SubscriptionInterval $interval
 * @property SubscriptionStatus $status
 * @property string|null $provider_status
 * @property CarbonInterface|null $provider_updated_at
 * @property RetryOwner $retry_owner
 * @property CarbonInterface|null $next_charge_at
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $paused_at
 * @property int|null $paused_by_id
 * @property CarbonInterface|null $resumed_at
 * @property int|null $resumed_by_id
 * @property CarbonInterface|null $cancelled_at
 * @property int|null $cancelled_by_id
 * @property CancellationSource|null $cancellation_source
 * @property string|null $cancellation_reason
 * @property string $idempotency_key
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Donor $donor
 * @property-read Program|null $program
 * @property-read Campaign|null $campaign
 */
class Subscription extends Model
{
    use Auditable;

    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    public static function auditValueFields(): array
    {
        return [
            'provider', 'external_id', 'donor_id', 'program_id', 'campaign_id', 'amount', 'currency', 'interval',
            'status', 'provider_status', 'retry_owner', 'next_charge_at', 'started_at', 'paused_at', 'paused_by_id',
            'resumed_at', 'resumed_by_id', 'cancelled_at', 'cancelled_by_id', 'cancellation_source', 'cancellation_reason',
        ];
    }

    public static function auditNameOnlyFields(): array
    {
        return [];
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
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pausedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paused_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resumedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resumed_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'amount' => 'decimal:2',
            'interval' => SubscriptionInterval::class,
            'status' => SubscriptionStatus::class,
            'provider_updated_at' => 'datetime',
            'retry_owner' => RetryOwner::class,
            'next_charge_at' => 'datetime',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'resumed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancellation_source' => CancellationSource::class,
        ];
    }
}
