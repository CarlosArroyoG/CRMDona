<?php

declare(strict_types=1);

namespace App\Actions\Donors;

use App\Communications\MessageComposer;
use App\Enums\AuditEvent;
use App\Enums\Permission;
use App\Models\Donor;
use App\Models\User;
use App\Support\WhatsAppPhone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Prepara la felicitación de cumpleaños por WhatsApp: arma el enlace oficial
 * wa.me con el texto de la plantilla de cumpleaños. El CRM NO envía nada: la
 * persona revisa el mensaje y lo envía ella misma en WhatsApp. Por eso se
 * registra como "WhatsApp preparado", nunca como enviado, y no entra al
 * historial de envíos. En la bitácora no queda el teléfono ni el texto.
 *
 * Mismo consentimiento que la felicitación por correo: donante no archivado
 * y que acepta comunicaciones (decisión del Product Owner, sin consentimiento
 * separado por ahora).
 */
class PrepareBirthdayWhatsApp
{
    public function __construct(private readonly MessageComposer $composer) {}

    public static function unavailableReason(Donor $donor): ?string
    {
        return match (true) {
            $donor->isArchived() => 'El donante está archivado.',
            ! $donor->accepts_communications => 'El donante no acepta recibir comunicaciones.',
            WhatsAppPhone::normalize($donor->phone) === null => 'El donante no tiene un teléfono utilizable para WhatsApp.',
            default => null,
        };
    }

    public static function canPrepare(Donor $donor, ?User $actor): bool
    {
        return ($actor?->hasPermission(Permission::ResendCommunications) ?? false) && self::unavailableReason($donor) === null;
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Donor $donor, User $actor): string
    {
        if (! $actor->hasPermission(Permission::ResendCommunications)) {
            throw new AuthorizationException('No tienes permiso para preparar felicitaciones.');
        }

        $reason = self::unavailableReason($donor);
        if ($reason !== null) {
            throw ValidationException::withMessages(['donor' => $reason]);
        }

        $donor->recordAudit(AuditEvent::WhatsAppPrepared, [], ['canal' => 'WhatsApp (envío manual)', 'mensaje' => 'Felicitación de cumpleaños']);

        return WhatsAppPhone::url((string) WhatsAppPhone::normalize($donor->phone), $this->composer->birthdayText($donor));
    }
}
