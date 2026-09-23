<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttemptInitiator;
use App\Enums\FailureCategory;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentProvider;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un intento real de conseguir un cobro. Solo marca y últimos 4 dígitos de
 * la tarjeta; nunca número completo, CVV ni vencimiento.
 *
 * @property int $id
 * @property int $payment_id
 * @property PaymentProvider $provider
 * @property string|null $external_id
 * @property int $attempt_number
 * @property PaymentAttemptStatus $status
 * @property AttemptInitiator $initiated_by
 * @property FailureCategory|null $failure_category
 * @property string|null $provider_code
 * @property string|null $provider_message
 * @property string|null $card_brand
 * @property string|null $card_last4
 * @property Carbon|null $provider_created_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Payment $payment
 */
class PaymentAttempt extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    public static function auditValueFields(): array
    {
        return [
            'payment_id', 'provider', 'external_id', 'attempt_number', 'status', 'initiated_by', 'failure_category',
            'provider_code',
        ];
    }

    public static function auditNameOnlyFields(): array
    {
        return ['card_brand', 'card_last4'];
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
            'status' => PaymentAttemptStatus::class,
            'initiated_by' => AttemptInitiator::class,
            'failure_category' => FailureCategory::class,
            'provider_created_at' => 'datetime',
        ];
    }
}
