<?php

declare(strict_types=1);

namespace App\Mail\Outgoing;

use App\Models\MailSetting;
use Illuminate\Database\QueryException;
use Illuminate\Mail\MailManager;

/**
 * Única pieza que decide cómo sale el correo (docs/tecnico/correo-saliente.md).
 * Ningún Mailable ni Notification conoce credenciales.
 *
 * Prioridad:
 * 1. configuración SMTP administrativa habilitada y completa → mailer `crm`;
 * 2. si no, el mailer del entorno (MAIL_MAILER y compañía), sin cambios.
 *
 * Ciclo de vida:
 * - web y comandos: se aplica la primera vez que el proceso resuelve el
 *   gestor de correo (cada petición o `schedule:run` es un proceso nuevo);
 * - worker de la cola: antes de cada Job se compara la huella de la
 *   configuración (`version`) y, si cambió, se olvidan los mailers ya
 *   creados. Así un cambio del Administrador se usa sin redeploy ni reinicio;
 * - al guardar en el panel se aplica de inmediato en ese mismo proceso.
 */
final class OutgoingMailConfig
{
    public const string MAILER = 'crm';

    private ?string $baselineMailer = null;

    private ?string $fingerprint = null;

    private bool $applied = false;

    public function __construct(private readonly MailManager $manager) {}

    /**
     * Aplica la configuración vigente. Si cambió desde la última vez, descarta
     * los mailers en memoria para que el siguiente envío use la nueva.
     */
    public function refresh(): void
    {
        $this->baselineMailer ??= (string) config('mail.default');
        $settings = $this->currentSettings();
        $fingerprint = $settings === null ? 'none' : (string) $settings->version;

        if ($this->applied && $fingerprint === $this->fingerprint) {
            return;
        }

        if ($settings !== null && $settings->isActive()) {
            config([
                'mail.mailers.'.self::MAILER => [
                    'transport' => self::MAILER,
                    'from' => ['address' => $settings->from_address, 'name' => $settings->from_name ?? config('app.name')],
                    'reply_to' => filled($settings->reply_to_address)
                        ? ['address' => $settings->reply_to_address, 'name' => $settings->reply_to_name]
                        : null,
                ],
                'mail.default' => self::MAILER,
            ]);
        } else {
            config(['mail.default' => $this->baselineMailer]);
        }

        $this->manager->forgetMailers();
        $this->fingerprint = $fingerprint;
        $this->applied = true;
    }

    /**
     * El mailer que usa hoy el CRM: `crm` (panel) o el del entorno.
     */
    public function currentMailer(): string
    {
        $this->refresh();

        return (string) config('mail.default');
    }

    public function usesAdministrativeSmtp(): bool
    {
        return $this->currentMailer() === self::MAILER;
    }

    /**
     * Quita del texto de un error la contraseña y el usuario SMTP (también en
     * base64, como viajan en AUTH LOGIN/PLAIN). Complementa SensitiveData.
     */
    public function scrub(string $text): string
    {
        $settings = $this->currentSettings();
        if ($settings === null) {
            return $text;
        }

        $secrets = array_filter([(string) $settings->password, (string) $settings->username]);
        foreach ($secrets as $secret) {
            $text = str_replace([$secret, base64_encode($secret)], '[oculto]', $text);
        }

        return $text;
    }

    private function currentSettings(): ?MailSetting
    {
        try {
            return MailSetting::query()->find(1);
        } catch (QueryException) {
            // Antes de migrar (instalación nueva) no hay tabla: se usa el entorno.
            return null;
        }
    }
}
