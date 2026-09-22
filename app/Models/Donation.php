<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DonationKind;
use App\Enums\DonationStatus;
use App\Enums\PaymentMethod;
use App\Models\Concerns\Auditable;
use Database\Factories\DonationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Donativo reconocido por el CRM (ADR-005). No es un pago, un recibo simple
 * ni un CFDI. `amount` es un string decimal exacto, nunca float (ADR-004).
 * Destino único: campaña, programa o ninguno (fondo general).
 *
 * @property int $id
 * @property int $donor_id
 * @property int|null $program_id
 * @property int|null $campaign_id
 * @property DonationKind $kind
 * @property PaymentMethod|null $payment_method
 * @property string $amount
 * @property string $currency
 * @property Carbon $received_on
 * @property DonationStatus $status
 * @property string|null $reference
 * @property string|null $in_kind_description
 * @property bool $tax_receipt_requested
 * @property string|null $notes
 * @property int $registered_by_id
 * @property Carbon|null $confirmed_at
 * @property int|null $confirmed_by_id
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by_id
 * @property string|null $cancellation_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Donor $donor
 * @property-read Program|null $program
 * @property-read Campaign|null $campaign
 */
#[Fillable([
    'donor_id', 'program_id', 'campaign_id', 'kind', 'payment_method', 'amount', 'received_on',
    'reference', 'in_kind_description', 'tax_receipt_requested', 'notes',
])]
class Donation extends Model
{
    use Auditable;

    /** @use HasFactory<DonationFactory> */
    use HasFactory;

    public static function auditValueFields(): array
    {
        return [
            'donor_id', 'program_id', 'campaign_id', 'kind', 'payment_method', 'amount', 'currency',
            'received_on', 'status', 'reference', 'in_kind_description', 'tax_receipt_requested',
            'registered_by_id', 'confirmed_at', 'confirmed_by_id', 'cancelled_at', 'cancelled_by_id',
            'cancellation_reason',
        ];
    }

    public static function auditNameOnlyFields(): array
    {
        return ['notes'];
    }

    public function isPending(): bool
    {
        return $this->status === DonationStatus::Pending;
    }

    /**
     * Programa para reportes: el directo o el de su campaña.
     */
    public function effectiveProgram(): ?Program
    {
        return $this->program ?? $this->campaign?->program;
    }

    /**
     * Donativos de un programa, directos o a través de sus campañas.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForProgram(Builder $query, int $programId): void
    {
        $query->where(fn (Builder $inner) => $inner
            ->where('program_id', $programId)
            ->orWhereHas('campaign', fn (Builder $campaign) => $campaign->where('program_id', $programId)));
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
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
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
            'kind' => DonationKind::class,
            'payment_method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'received_on' => 'date',
            'status' => DonationStatus::class,
            'tax_receipt_requested' => 'boolean',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
