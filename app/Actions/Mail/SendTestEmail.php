<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Communications\ComposedMessage;
use App\Enums\AuditEvent;
use App\Enums\Permission;
use App\Mail\DonorMessage;
use App\Mail\Outgoing\OutgoingMailConfig;
use App\Mail\Outgoing\SmtpErrorTranslator;
use App\Models\Communication;
use App\Models\MailSetting;
use App\Models\User;
use App\Support\Branding;
use App\Support\SensitiveData;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Envía un correo de prueba con la configuración SMTP GUARDADA (aunque aún
 * no esté habilitada, para probar antes de activarla). No cambia la
 * configuración, no lleva datos de donantes y queda en la bitácora con el
 * resultado y la categoría del error, nunca con credenciales.
 *
 * "Aceptado" significa que el servidor SMTP recibió el mensaje (respuesta
 * 250); no garantiza que llegue al buzón.
 */
class SendTestEmail
{
    public const int MAX_ATTEMPTS = 5;

    public const int DECAY_SECONDS = 600;

    public function __construct(
        private readonly MailManager $manager,
        private readonly OutgoingMailConfig $mail,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(string $recipient, User $actor): MailSetting
    {
        if (! $actor->hasPermission(Permission::ManageMailSettings)) {
            throw new AuthorizationException('Solo el Administrador prueba el correo saliente.');
        }

        /** @var array{recipient: string} $data */
        $data = Validator::make(['recipient' => trim($recipient)], ['recipient' => ['required', 'email:rfc', 'max:255']], [], ['recipient' => 'correo de destino'])->validate();

        $key = 'mail-test:'.$actor->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['recipient' => 'Demasiados correos de prueba. Intenta de nuevo en '.max(1, (int) ceil(RateLimiter::availableIn($key) / 60)).' minutos.']);
        }
        RateLimiter::hit($key, self::DECAY_SECONDS);

        $settings = MailSetting::current();
        if (! $settings->isConfigured()) {
            throw ValidationException::withMessages(['recipient' => 'Primero guarda el servidor, el puerto y el correo del remitente.']);
        }

        $masked = (string) Communication::maskEmail($data['recipient']);
        $organization = Branding::name();

        try {
            $mailer = $this->manager->build(['transport' => OutgoingMailConfig::MAILER, 'name' => 'crm-prueba']);
            $mailer->alwaysFrom((string) $settings->from_address, $settings->from_name ?? $organization);
            if (filled($settings->reply_to_address)) {
                $mailer->alwaysReplyTo((string) $settings->reply_to_address, $settings->reply_to_name);
            }

            $mailer->to($data['recipient'])->send(new DonorMessage(new ComposedMessage(
                subject: "Correo de prueba del CRM de {$organization}",
                body: "Correo de prueba del CRM de {$organization}.\n\nSi lo estás leyendo, el envío por SMTP funciona. No contiene información de donantes.\n\nEnviado por: {$actor->name}.",
                notices: ['Mensaje automático de prueba. No respondas a este correo.'],
                attachments: [],
                usedFallback: false,
                signature: null,
                unsubscribeUrl: null,
            )));
        } catch (Throwable $exception) {
            $error = SmtpErrorTranslator::translate($exception);
            Log::warning('Falló el correo de prueba del SMTP administrativo.', [
                'category' => $error['category'],
                'error' => SensitiveData::safeText($this->mail->scrub($exception->getMessage()), 300),
            ]);
            $settings->recordAudit(AuditEvent::MailTest, [], ['result' => 'fallido', 'error_category' => $error['category'], 'recipient' => $masked]);

            throw ValidationException::withMessages(['recipient' => $error['message']]);
        }

        $settings->auditAs(AuditEvent::MailTest, ['result' => 'aceptado por el servidor', 'recipient' => $masked])->forceFill([
            'last_successful_test_at' => now(),
            'last_successful_test_by_id' => $actor->id,
        ])->save();

        return $settings;
    }
}
