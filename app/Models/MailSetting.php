<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MailEncryption;
use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Correo saliente (SMTP estándar) administrado desde el panel. Una sola fila.
 *
 * La contraseña usa el cast `encrypted` de Laravel (APP_KEY): en la base solo
 * existe cifrada, está oculta al serializar y nunca entra en la bitácora (ni
 * como valor ni como nombre de campo; su cambio se registra como contexto
 * "reemplazada" o "eliminada").
 *
 * @property int $id
 * @property bool $enabled
 * @property string|null $host
 * @property int|null $port
 * @property MailEncryption $encryption
 * @property string|null $username
 * @property string|null $password
 * @property string|null $from_address
 * @property string|null $from_name
 * @property string|null $reply_to_address
 * @property string|null $reply_to_name
 * @property int $timeout
 * @property CarbonInterface|null $last_successful_test_at
 * @property int|null $last_successful_test_by_id
 * @property int $version Sube en cada guardado (huella para los workers).
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $lastSuccessfulTestBy
 */
class MailSetting extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['password'];

    public static function current(): self
    {
        $settings = static::query()->find(1);
        if ($settings !== null) {
            return $settings;
        }

        static::query()->insertOrIgnore(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return static::query()->findOrFail(1);
    }

    public static function auditValueFields(): array
    {
        return [
            'enabled', 'host', 'port', 'encryption', 'from_address', 'from_name', 'reply_to_address', 'reply_to_name',
            'timeout', 'last_successful_test_at', 'last_successful_test_by_id',
        ];
    }

    public static function auditNameOnlyFields(): array
    {
        return ['username'];
    }

    /**
     * Tiene lo mínimo para enviar: servidor, puerto y remitente.
     */
    public function isConfigured(): bool
    {
        return filled($this->host) && $this->port !== null && filled($this->from_address);
    }

    /**
     * El CRM usa esta configuración en lugar de MAIL_* del entorno.
     */
    public function isActive(): bool
    {
        return $this->enabled && $this->isConfigured();
    }

    public function hasPassword(): bool
    {
        return filled($this->getRawOriginal('password'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lastSuccessfulTestBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_successful_test_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'port' => 'integer',
            'encryption' => MailEncryption::class,
            'password' => 'encrypted',
            'timeout' => 'integer',
            'version' => 'integer',
            'last_successful_test_at' => 'datetime',
        ];
    }
}
