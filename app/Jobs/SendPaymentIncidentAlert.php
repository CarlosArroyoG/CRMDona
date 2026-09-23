<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Filament\Resources\PaymentIncidents\PaymentIncidentResource;
use App\Models\PaymentIncident;
use App\Models\User;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Avisa en la campana de Filament a quienes deben atender una incidencia
 * nueva (fase-2-diseno-pagos.md §17): todo Administrador; Coordinador (solo
 * incidencias operativas) y Contador si tienen activa la preferencia; nunca
 * Solo lectura. Ninguna dirección está escrita en el código.
 *
 * El aviso es operativo, sin códigos ni datos técnicos. Es idempotente: si
 * el Job se reintenta, no avisa dos veces a la misma persona. El correo se
 * agregará en la fase de comunicaciones.
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
                $user->notify($this->notification($incident)->toDatabase());
            }
        }
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
