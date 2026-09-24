<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\Pages;

use App\Actions\PaymentRequests\ManagePaymentRequest;
use App\Actions\PaymentRequests\SendPaymentRequest;
use App\Enums\PaymentRequestStatus;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Models\PaymentRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Js;

/**
 * Ficha de una solicitud de pago. Justo después de crearla desde "Crear
 * donativo" el usuario llega aquí con las tres acciones a la vista: abrir el
 * pago en este equipo, copiar el enlace o enviarlo por correo.
 */
class ViewPaymentRequest extends ViewRecord
{
    use ReportsActionErrors;

    protected static string $resource = PaymentRequestResource::class;

    public function getSubheading(): string
    {
        return match ($this->request()->effectiveStatus()) {
            PaymentRequestStatus::Open => 'Esperando el pago del donante. El donativo se registra solo cuando el proveedor confirma el cobro.',
            PaymentRequestStatus::Paid => 'El proveedor confirmó el pago: el donativo ya está registrado.',
            PaymentRequestStatus::Cancelled => 'Solicitud cancelada: el enlace ya no funciona.',
            PaymentRequestStatus::Expired => 'El enlace venció. Regenéralo para enviar uno nuevo.',
        };
    }

    protected function getHeaderActions(): array
    {
        $usable = fn (): bool => $this->request()->isUsable() && Gate::allows('manage', $this->request());
        $open = fn (): bool => $this->request()->status === PaymentRequestStatus::Open && Gate::allows('manage', $this->request());

        return [
            Action::make('openPayment')
                ->label('Abrir pago ahora')
                ->icon(Heroicon::OutlinedCreditCard)
                ->size(Size::Large)
                ->tooltip('El donante escribe su tarjeta en este equipo, en la página segura del proveedor.')
                ->visible($usable)
                ->url(fn (): string => $this->request()->url(), shouldOpenInNewTab: true),
            Action::make('copyLink')
                ->label('Copiar enlace')
                ->icon(Heroicon::OutlinedClipboardDocument)
                ->color('gray')
                ->visible($usable)
                ->alpineClickHandler(fn (): string => 'window.navigator.clipboard.writeText('.Js::from($this->request()->url()).'); $tooltip('.Js::from('Enlace copiado').', { theme: $store.theme, timeout: 2000 })'),
            Action::make('sendEmail')
                ->label('Enviar por correo')
                ->icon(Heroicon::OutlinedEnvelope)
                ->color('gray')
                ->visible($usable)
                ->disabled(fn (): bool => blank($this->request()->donor->email))
                ->tooltip(fn (): ?string => blank($this->request()->donor->email) ? 'El donante no tiene correo: copia el enlace y envíalo por otro medio.' : null)
                ->requiresConfirmation()
                ->modalHeading('Enviar el enlace por correo')
                ->modalDescription(fn (): string => 'Se enviará un correo individual a '.$this->request()->donor->display_name.' con el enlace de pago de esta solicitud.')
                ->modalSubmitActionLabel('Enviar')
                ->action(fn (): bool => self::notifyOutcome(
                    fn () => app(SendPaymentRequest::class)->handle($this->request(), $this->actor()),
                    'Correo en camino. Revisa su resultado en Comunicaciones → Historial de envíos.',
                )),
            Action::make('regenerate')
                ->label('Regenerar enlace')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible($open)
                ->requiresConfirmation()
                ->modalHeading('Regenerar el enlace')
                ->modalDescription('Se crea un enlace nuevo, válido 7 días. El enlace anterior deja de funcionar de inmediato.')
                ->action(function (): void {
                    self::notifyOutcome(fn () => app(ManagePaymentRequest::class)->regenerate($this->request(), $this->actor()), 'Enlace nuevo listo; el anterior ya no funciona.');
                    $this->refreshRecord();
                }),
            Action::make('cancel')
                ->label('Cancelar solicitud')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible($open)
                ->requiresConfirmation()
                ->modalHeading('Cancelar la solicitud')
                ->modalDescription('El enlace deja de funcionar. Si el donante ya estaba pagando y el proveedor confirma el cobro, el donativo se registra de todos modos.')
                ->action(function (): void {
                    self::notifyOutcome(fn () => app(ManagePaymentRequest::class)->cancel($this->request(), $this->actor()), 'Solicitud cancelada');
                    $this->refreshRecord();
                }),
        ];
    }

    private function request(): PaymentRequest
    {
        $record = $this->getRecord();
        assert($record instanceof PaymentRequest);

        return $record;
    }

    private function refreshRecord(): void
    {
        $this->record = $this->request()->fresh() ?? $this->request();
    }

    private function actor(): User
    {
        $user = auth()->user();
        assert($user instanceof User);

        return $user;
    }
}
