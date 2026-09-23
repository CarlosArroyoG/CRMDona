<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CfdiStatus;
use App\Enums\DonationKind;
use App\Enums\DonationOrigin;
use App\Enums\DonationStatus;
use App\Enums\ManualPaymentMethod;
use App\Models\Concerns\Auditable;
use Database\Factories\DonationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property ManualPaymentMethod|null $manual_payment_method
 * @property string $amount
 * @property string $currency
 * @property Carbon $received_on
 * @property DonationStatus $status
 * @property string|null $reference
 * @property string|null $in_kind_description
 * @property string|null $in_kind_quantity
 * @property string|null $in_kind_unit_code
 * @property string|null $in_kind_product_service_code
 * @property string|null $in_kind_unit_value
 * @property string|null $in_kind_total_value
 * @property bool $tax_receipt_requested
 * @property string|null $notes
 * @property int|null $registered_by_id Nulo solo en donativos en línea (sin actor humano).
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
 * @property-read Payment|null $payment
 * @property-read Collection<int, GlobalCfdi> $globalCfdis
 * @property-read Collection<int, FiscalIncident> $fiscalIncidents
 * @property-read DonationReceipt|null $receipt
 * @property DonationOrigin $origin
 * @property int|null $payment_id
 */
#[Fillable([
    'donor_id', 'program_id', 'campaign_id', 'kind', 'manual_payment_method', 'amount', 'received_on',
    'reference', 'in_kind_description', 'in_kind_quantity', 'in_kind_unit_code', 'in_kind_product_service_code', 'in_kind_unit_value', 'in_kind_total_value', 'tax_receipt_requested', 'notes',
])]
class Donation extends Model
{
    use Auditable;

    /** @use HasFactory<DonationFactory> */
    use HasFactory;

    public static function auditValueFields(): array
    {
        return [
            'donor_id', 'program_id', 'campaign_id', 'kind', 'manual_payment_method', 'amount', 'currency',
            'received_on', 'status', 'reference', 'in_kind_description', 'in_kind_quantity', 'in_kind_unit_code', 'in_kind_product_service_code', 'in_kind_unit_value', 'in_kind_total_value', 'tax_receipt_requested',
            'registered_by_id', 'confirmed_at', 'confirmed_by_id', 'cancelled_at', 'cancelled_by_id', 'origin', 'payment_id', 'fiscal_route', 'fiscal_block_reason', 'fiscal_late_at',
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

    public function isOnline(): bool
    {
        return $this->origin === DonationOrigin::Online;
    }

    /**
     * CFDI emitidos para el donativo (como máximo uno vigente).
     *
     * @return HasMany<Cfdi, $this>
     */
    public function cfdis(): HasMany
    {
        return $this->hasMany(Cfdi::class)->latest('id');
    }

    /**
     * @return BelongsToMany<GlobalCfdi, $this>
     */
    public function globalCfdis(): BelongsToMany
    {
        return $this->belongsToMany(GlobalCfdi::class, 'donation_global_cfdi')
            ->withPivot('operation_number')
            ->withTimestamps();
    }

    /**
     * @return HasMany<FiscalIncident, $this>
     */
    public function fiscalIncidents(): HasMany
    {
        return $this->hasMany(FiscalIncident::class);
    }

    /**
     * El CFDI vigente (no cancelado ni descartado), si existe. Durante una
     * sustitución (motivo 01) es el original hasta que se cancela.
     */
    public function activeCfdi(): ?Cfdi
    {
        return $this->cfdis()->whereNotIn('status', CfdiStatus::inactiveValues())->where('replacement_pending', false)->first();
    }

    /**
     * Recibo simple (acuse, no fiscal).
     *
     * @return HasOne<DonationReceipt, $this>
     */
    public function receipt(): HasOne
    {
        return $this->hasOne(DonationReceipt::class);
    }

    /**
     * Campaña, programa o fondo general, para textos al donante.
     */
    public function destinationLabel(): string
    {
        return $this->campaign->name ?? $this->effectiveProgram()->name ?? 'el fondo general';
    }

    /**
     * Sustitución en curso (CFDI nuevo cuyo original aún no se cancela).
     */
    public function pendingReplacementCfdi(): ?Cfdi
    {
        return $this->cfdis()->whereNotIn('status', CfdiStatus::inactiveValues())->where('replacement_pending', true)->first();
    }

    /**
     * Pago en línea que originó el donativo (solo origin = online).
     *
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
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
            'manual_payment_method' => ManualPaymentMethod::class,
            'amount' => 'decimal:2',
            'received_on' => 'date',
            'status' => DonationStatus::class,
            'origin' => DonationOrigin::class,
            'tax_receipt_requested' => 'boolean',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
