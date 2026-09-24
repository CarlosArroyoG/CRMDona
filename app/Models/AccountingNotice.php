<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountingNoticeStatus;
use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Aviso a Contabilidad de un donativo confirmado (uno por donativo) y su
 * procesamiento contable. No guarda datos fiscales: el correo se arma al
 * enviarlo con los datos vigentes (docs/tecnico/cfdi-externo.md).
 *
 * @property int $id
 * @property int $donation_id
 * @property AccountingNoticeStatus $status
 * @property int $attempts
 * @property list<int> $delivered_to Usuarios que ya recibieron el correo (un reintento no les repite el aviso).
 * @property string|null $skip_reason
 * @property string|null $last_error
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $processed_at
 * @property int|null $processed_by_id
 * @property string|null $processing_note
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Donation $donation
 * @property-read User|null $processedBy
 */
class AccountingNotice extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    public static function auditValueFields(): array
    {
        return ['donation_id', 'status', 'skip_reason', 'sent_at', 'processed_at', 'processed_by_id'];
    }

    public static function auditNameOnlyFields(): array
    {
        return ['processing_note'];
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    /**
     * @return BelongsTo<Donation, $this>
     */
    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AccountingNoticeStatus::class,
            'delivered_to' => 'array',
            'sent_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
