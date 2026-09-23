<?php

declare(strict_types=1);

namespace App\Support;

use App\Communications\ComposedMessage;
use App\Enums\Permission;
use App\Mail\DonorMessage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisos operativos al personal (Fase 7): campana de Filament y correo, con
 * la infraestructura de correo de la Fase 4. Destinatarios: usuarios activos
 * con el permiso indicado (nunca direcciones escritas en el código).
 *
 * Una sola vez por persona y `$key`: la notificación guardada es la marca.
 * Leer el aviso no resuelve nada; el registro de origen (incidencia, CFDI,
 * job) conserva su propio estado. Sin datos de tarjeta, secretos ni payloads.
 */
final class OperationalAlerts
{
    /**
     * @param  list<string>  $details
     */
    public static function send(string $key, Permission $permission, string $title, array $details, ?string $url = null, string $color = 'danger'): void
    {
        $recipients = User::query()->whereNotNull('role')->whereNull('deactivated_at')->get()
            ->filter(fn (User $user): bool => $user->hasPermission($permission));

        self::sendTo($recipients->values()->all(), $key, $title, $details, $url, $color);
    }

    /**
     * @param  array<int, User>  $recipients
     * @param  list<string>  $details
     */
    public static function sendTo(array $recipients, string $key, string $title, array $details, ?string $url = null, string $color = 'danger'): void
    {
        foreach ($recipients as $user) {
            if ($user->notifications()->where('data->viewData->alert_key', $key)->exists()) {
                continue;
            }

            // Primero el correo: si falla, el reintento lo vuelve a intentar sin duplicar la campana.
            if (filled($user->email)) {
                try {
                    Mail::to($user->email)->send(new DonorMessage(new ComposedMessage(
                        subject: "[CRM] {$title}",
                        body: implode("\n\n", [...$details, $url !== null ? "Revisarlo en el CRM: {$url}" : '']),
                        notices: ['Aviso automático del CRM. Leer este correo no resuelve el asunto: atiéndelo en el CRM.'],
                        attachments: [],
                        usedFallback: false,
                        signature: null,
                        unsubscribeUrl: null,
                    )));
                } catch (Throwable $exception) {
                    Log::error('No se pudo enviar el aviso operativo por correo.', ['key' => $key, 'user_id' => $user->id, 'error' => SensitiveData::safeText($exception->getMessage())]);

                    throw $exception;
                }
            }

            $notification = Notification::make()->title($title)->body(implode(' · ', $details))
                ->icon('heroicon-o-exclamation-triangle')->iconColor($color)
                ->viewData(['alert_key' => $key]);

            if ($url !== null) {
                $notification->actions([Action::make('view')->label('Revisar')->button()->url($url)]);
            }

            $user->notify($notification->toDatabase());
        }
    }
}
