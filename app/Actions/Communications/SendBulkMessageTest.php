<?php

declare(strict_types=1);

namespace App\Actions\Communications;

use App\Communications\MessageComposer;
use App\Enums\AuditEvent;
use App\Enums\Permission;
use App\Mail\DonorMessage;
use App\Mail\Outgoing\OutgoingMailConfig;
use App\Mail\Outgoing\SmtpErrorTranslator;
use App\Models\BulkMessage;
use App\Models\Communication;
use App\Models\User;
use App\Support\SensitiveData;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Manda el borrador al correo de quien lo prepara, con datos de ejemplo.
 * Es obligatorio antes de enviar: así se revisa cómo se ve el correo real
 * con el servidor de correo vigente. Queda en la bitácora del envío.
 */
class SendBulkMessageTest
{
    public const int MAX_ATTEMPTS = 5;

    public const int DECAY_SECONDS = 600;

    public function __construct(
        private readonly MessageComposer $composer,
        private readonly OutgoingMailConfig $mail,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(BulkMessage $message, User $actor): BulkMessage
    {
        if (! $actor->hasPermission(Permission::SendBulkMessages)) {
            throw new AuthorizationException('No tienes permiso para preparar envíos masivos.');
        }

        if (! $message->isDraft()) {
            throw ValidationException::withMessages(['message' => 'Solo se prueba un envío en borrador.']);
        }

        $key = 'bulk-message-test:'.$actor->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['message' => 'Demasiados correos de prueba. Intenta de nuevo en '.max(1, (int) ceil(RateLimiter::availableIn($key) / 60)).' minutos.']);
        }
        RateLimiter::hit($key, self::DECAY_SECONDS);

        $masked = (string) Communication::maskEmail($actor->email);

        try {
            Mail::to($actor->email)->send(new DonorMessage($this->composer->bulkTest($message)));
        } catch (Throwable $exception) {
            $error = SmtpErrorTranslator::translate($exception);
            Log::warning('Falló el correo de prueba de un envío masivo.', [
                'bulk_message_id' => $message->id,
                'category' => $error['category'],
                'error' => SensitiveData::safeText($this->mail->scrub($exception->getMessage()), 300),
            ]);

            throw ValidationException::withMessages(['message' => $error['message']]);
        }

        $message->auditAs(AuditEvent::MailTest, ['result' => 'aceptado por el servidor', 'recipient' => $masked])
            ->forceFill(['tested_at' => now(), 'tested_by_id' => $actor->id])
            ->save();

        return $message;
    }
}
