<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentRequestFrequency;
use App\Enums\PaymentRequestStatus;
use App\Models\Concerns\Auditable;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Solicitud de pago (cobro asistido, docs/tecnico/solicitudes-de-pago.md).
 * El personal prepara el donativo con tarjeta y el donante lo paga en /donar
 * con el enlace; los datos de la tarjeta van directo al proveedor. No es un
 * donativo: el Donation lo crea solo el pago exitoso confirmado por el
 * proveedor.
 *
 * El token se guarda como hash SHA-256 (búsqueda) y cifrado con la llave de
 * la aplicación (volver a copiarlo o enviarlo). Nunca en la bitácora.
 *
 * @property int $id
 * @property string $token_hash
 * @property string $token
 * @property int $token_version
 * @property int $attempt Intentos de pago iniciados desde el enlace (llave de idempotencia).
 * @property int $donor_id
 * @property string $amount
 * @property PaymentRequestFrequency $frequency
 * @property int|null $campaign_id
 * @property int|null $program_id
 * @property bool $tax_receipt_requested
 * @property PaymentRequestStatus $status Solo open, paid o cancelled; "Vencida" se calcula.
 * @property CarbonInterface $expires_at
 * @property int|null $payment_id Último pago único iniciado (o el que se pagó).
 * @property int|null $subscription_id Último donativo mensual iniciado (o el que se activó).
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface|null $cancelled_at
 * @property int|null $cancelled_by_id
 * @property int $created_by_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Donor $donor
 * @property-read Campaign|null $campaign
 * @property-read Program|null $program
 * @property-read Payment|null $payment
 * @property-read Subscription|null $subscription
 * @property-read User $createdBy
 * @property-read User|null $cancelledBy
 */
class PaymentRequest extends Model
{
    use Auditable;

    /** Vigencia de un enlace nuevo o regenerado (decisión del Product Owner). */
    public const int VALID_DAYS = 7;

    /** Prefijo de la llave de idempotencia de los pagos iniciados desde un enlace. */
    public const string IDEMPOTENCY_PREFIX = 'payment-request:';

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['token', 'token_hash'];

    public static function auditValueFields(): array
    {
        return [
            'donor_id', 'amount', 'frequency', 'campaign_id', 'program_id', 'tax_receipt_requested', 'status',
            'expires_at', 'token_version', 'payment_id', 'subscription_id', 'paid_at', 'cancelled_at',
        ];
    }

    public static function auditNameOnlyFields(): array
    {
        return [];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        return self::query()->where('token_hash', self::hashToken($token))->first();
    }

    /**
     * Situación para mostrar: una abierta con la vigencia vencida es "Vencida".
     */
    public function effectiveStatus(): PaymentRequestStatus
    {
        return $this->status === PaymentRequestStatus::Open && $this->expires_at->isPast()
            ? PaymentRequestStatus::Expired
            : $this->status;
    }

    public function isUsable(): bool
    {
        return $this->effectiveStatus() === PaymentRequestStatus::Open;
    }

    /**
     * Enlace público. Solo contiene el token aleatorio: ningún ID ni importe.
     */
    public function url(): string
    {
        return route('donate.request', ['token' => $this->token]);
    }

    public function idempotencyKey(): string
    {
        return self::IDEMPOTENCY_PREFIX.$this->id.':'.$this->attempt;
    }

    /**
     * Solicitud a la que pertenece una llave de idempotencia de pago, si viene de un enlace.
     */
    public static function idFromIdempotencyKey(?string $key): ?int
    {
        return $key !== null && preg_match('/^'.preg_quote(self::IDEMPOTENCY_PREFIX, '/').'(\d+):\d+$/', $key, $match) === 1
            ? (int) $match[1]
            : null;
    }

    public function destinationLabel(): string
    {
        return $this->campaign->name ?? $this->program->name ?? 'el fondo general';
    }

    public function amountLabel(): string
    {
        return Money::format($this->amount).' MXN'.($this->frequency === PaymentRequestFrequency::Monthly ? ' al mes' : '');
    }

    /**
     * @return BelongsTo<Donor, $this>
     */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

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
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
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
            'token' => 'encrypted',
            'amount' => 'decimal:2',
            'frequency' => PaymentRequestFrequency::class,
            'tax_receipt_requested' => 'boolean',
            'status' => PaymentRequestStatus::class,
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
