<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Communications\ComposedMessage;
use App\Filament\Resources\PaymentIncidents\PaymentIncidentResource;
use App\Mail\DonorMessage;
use App\Models\PaymentIncident;
use App\Models\User;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Avisa en la campana de Filament a quienes deben atender una incidencia
 * nueva (fase-2-diseno-pagos.md §17): todo Administrador; Coordinador (solo
 * incidencias operativas) y Contador si tienen activa la preferencia; nunca
 * Solo lectura. Ninguna dirección está escrita en el código.
 *
 * El aviso es operativo, sin códigos ni datos técnicos. Es idempotente: si
 * el Job se reintenta, no avisa dos veces a la misma persona. RF-01: también
 * por correo (infraestructura de la Fase 4), con solo lo necesario para
 * actuar y el enlace al CRM. Leer el aviso no resuelve la incidencia.
 */
class SendPaymentIncidentAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $incidentId) {}

    public function handle(): void
    {
        $incident = PaymentIncident::query()->with(['payment.donor', 'subscription.donor'])->find($this->incidentId);
        if ($incident === null) {
            return;
        }

        $recipients = User::query()->whereNotNull('role')->whereNull('deactivated_at')->get()
            ->filter(fn (User $user): bool => $user->receivesPaymentAlertsFor($incident->type));

        foreach ($recipients as $user) {
            $alreadySent = $user->notifications()
                ->where('data->viewData->payment_incident_id', $incident->id)
                ->exists();

            if (! $alreadySent) {
                // RF-01: correo a la misma persona (primero, para que un reintento no duplique la campana).
                if (filled($user->email)) {
                    Mail::to($user->email)->send(new DonorMessage($this->mail($incident)));
                }
                $user->notify($this->notification($incident)->toDatabase());
            }
        }
    }

    private function mail(PaymentIncident $incident): ComposedMessage
    {
        $payment = $incident->payment;
        $amount = $payment->amount ?? $incident->subscription?->amount;
        $lines = array_values(array_filter([
            'Tipo: '.$incident->type->getLabel(),
            $incident->failure_category !== null ? 'Motivo: '.$incident->failure_category->getLabel() : null,
            $incident->donor()?->display_name !== null ? 'Donante: '.$incident->donor()->display_name : null,
            $amount !== null ? 'Importe: '.Money::format($amount).' MXN' : null,
            'Detectada: '.$incident->detected_at->timezone(config()->string('app.timezone'))->format('d/m/Y H:i'),
            $payment !== null ? 'Pago en línea #'.$payment->id.' ('.$payment->kind->getLabel().')' : null,
            $payment?->campaign?->name !== null ? 'Campaña: '.$payment->campaign->name : null,
            'Qué hacer: '.$incident->type->requiredAction(),
            'Revisarla en el CRM: '.PaymentIncidentResource::getUrl('view', ['record' => $incident->id], panel: 'admin'),
        ]));

        return new ComposedMessage(
            subject: '[CRM] Incidencia de pago: '.$incident->type->getLabel(),
            body: implode("\n\n", $lines),
            notices: ['Aviso automático del CRM. Leer este correo no resuelve la incidencia: atiéndela en el CRM.'],
            attachments: [],
            usedFallback: false,
            signature: null,
            unsubscribeUrl: null,
        );
    }

    private function notification(PaymentIncident $incident): Notification
    {
        $amount = $incident->payment->amount ?? $incident->subscription?->amount;
        $details = array_filter([
            $incident->donor()?->display_name !== null ? 'Donante: '.$incident->donor()->display_name : null,
            $amount !== null ? 'Importe: '.Money::format($amount).' MXN' : null,
            $incident->type->requiredAction(),
        ]);

        return Notification::make()
            ->title($incident->type->getLabel())
            ->body(implode(' · ', $details))
            ->icon('heroicon-o-exclamation-triangle')
            ->iconColor($incident->severity->getColor())
            ->viewData(['payment_incident_id' => $incident->id])
            ->actions([
                Action::make('view')->label('Ver incidencia')->button()
                    ->url(PaymentIncidentResource::getUrl('view', ['record' => $incident->id], panel: 'admin')),
            ]);
    }
}
