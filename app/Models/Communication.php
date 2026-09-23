<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommunicationKind;
use App\Enums\CommunicationStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un correo a un donante y su resultado. Es el registro de envíos: no guarda
 * el cuerpo del mensaje, y el destinatario queda enmascarado (el correo
 * completo vive solo en `donors`). `dedupe_key` única = un solo
 * agradecimiento por donativo, un solo envío por CFDI y una felicitación por
 * donante y año; un reenvío manual usa otra llave.
 *
 * @property int $id
 * @property CommunicationKind $kind
 * @property int $donor_id
 * @property int|null $donation_id
 * @property int|null $cfdi_id
 * @property string $dedupe_key
 * @property CommunicationStatus $status
 * @property string|null $recipient Correo enmascarado.
 * @property string|null $subject
 * @property list<string> $attachments
 * @property bool $used_fallback_template
 * @property int $attempts
 * @property string|null $skip_reason
 * @property string|null $last_error
 * @property int|null $requested_by_id Nulo = envío automático.
 * @property CarbonInterface|null $sent_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Donor $donor
 * @property-read Donation|null $donation
 * @property-read Cfdi|null $cfdi
 * @property-read User|null $requestedBy
 */
class Communication extends Model
{
    protected $guarded = ['id'];

    public static function maskEmail(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).str_repeat('*', max(mb_strlen($local) - 1, 1)).'@'.$domain;
    }

    /**
     * @return BelongsTo<Donor, $this>
     */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    /**
     * @return BelongsTo<Donation, $this>
     */
    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    /**
     * @return BelongsTo<Cfdi, $this>
     */
    public function cfdi(): BelongsTo
    {
        return $this->belongsTo(Cfdi::class);
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
            'kind' => CommunicationKind::class,
            'status' => CommunicationStatus::class,
            'attachments' => 'array',
            'used_fallback_template' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }
}
