<?php

declare(strict_types=1);

namespace App\Filament\Resources\BulkMessages\Pages;

use App\Actions\Communications\DeleteBulkMessage;
use App\Actions\Communications\SendBulkMessage;
use App\Actions\Communications\SendBulkMessageTest;
use App\Actions\Communications\StopBulkMessage;
use App\Enums\BulkMessageStatus;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\BulkMessages\BulkMessageResource;
use App\Models\BulkMessage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;

/**
 * Ficha de un envío masivo. Orden obligatorio: guardar el borrador, mandar
 * el correo de prueba (a quien lo prepara), revisarlo y enviar. Mientras
 * queden correos en cola, el envío se puede detener.
 */
class ViewBulkMessage extends ViewRecord
{
    use ReportsActionErrors;

    protected static string $resource = BulkMessageResource::class;

    public function getSubheading(): string
    {
        return match ($this->message()->status) {
            BulkMessageStatus::Draft => $this->message()->tested_at === null
                ? 'Borrador: manda el correo de prueba a tu correo, revísalo y luego envía.'
                : 'Borrador probado: ya se puede enviar. Si cambias el texto, tendrás que volver a probarlo.',
            BulkMessageStatus::Preparing => 'Registrando a los destinatarios en la cola de correo.',
            BulkMessageStatus::Sent => 'Los correos salen poco a poco para respetar el límite del servidor de correo. Revisa el resultado de cada uno abajo.',
            BulkMessageStatus::Stopped => 'Envío detenido: los correos que seguían en cola ya no se mandan.',
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (): bool => Gate::allows('update', $this->message())),
            Action::make('sendTest')
                ->label('Enviarme una prueba')
                ->icon(Heroicon::OutlinedBeaker)
                ->color('gray')
                ->visible(fn (): bool => Gate::allows('send', $this->message()))
                ->requiresConfirmation()
                ->modalHeading('Correo de prueba')
                ->modalDescription(fn (): string => 'Se enviará a tu correo ('.$this->actor()->email.') con datos de ejemplo. Revisa cómo se ve antes de enviarlo a los donantes.')
                ->modalSubmitActionLabel('Enviar prueba')
                ->action(function (): void {
                    self::notifyOutcome(fn () => app(SendBulkMessageTest::class)->handle($this->message(), $this->actor()), 'Prueba enviada a tu correo. Revísala antes de enviar.');
                    $this->refreshRecord();
                }),
            Action::make('send')
                ->label('Enviar')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn (): bool => Gate::allows('send', $this->message()))
                ->disabled(fn (): bool => $this->message()->tested_at === null)
                ->tooltip(fn (): ?string => $this->message()->tested_at === null ? 'Primero envíate una prueba.' : null)
                ->requiresConfirmation()
                ->modalHeading('Enviar a los donantes')
                ->modalDescription(fn (): string => 'Se enviará a '.Number::format($this->message()->audience()->recipients()->count())
                    .' donantes. Los correos salen poco a poco y no se pueden recuperar una vez enviados.')
                ->modalSubmitActionLabel('Sí, enviar')
                ->action(function (): void {
                    self::notifyOutcome(fn () => app(SendBulkMessage::class)->handle($this->message(), $this->actor()), 'Envío iniciado');
                    $this->refreshRecord();
                }),
            Action::make('stop')
                ->label('Detener envío')
                ->icon(Heroicon::OutlinedStopCircle)
                ->color('danger')
                ->visible(fn (): bool => Gate::allows('stop', $this->message()))
                ->requiresConfirmation()
                ->modalHeading('Detener el envío')
                ->modalDescription('Los correos que ya salieron no se pueden recuperar. Los que siguen en cola quedarán como "No enviado".')
                ->action(function (): void {
                    self::notifyOutcome(fn () => app(StopBulkMessage::class)->handle($this->message(), $this->actor()), 'Envío detenido');
                    $this->refreshRecord();
                }),
            Action::make('delete')
                ->label('Eliminar borrador')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->visible(fn (): bool => Gate::allows('delete', $this->message()))
                ->requiresConfirmation()
                ->action(function (): void {
                    if (self::notifyOutcome(fn () => app(DeleteBulkMessage::class)->handle($this->message(), $this->actor()), 'Borrador eliminado')) {
                        $this->redirect(BulkMessageResource::getUrl('index'));
                    }
                }),
        ];
    }

    private function message(): BulkMessage
    {
        $record = $this->getRecord();
        assert($record instanceof BulkMessage);

        return $record;
    }

    private function refreshRecord(): void
    {
        $this->record = $this->message()->fresh() ?? $this->message();
    }

    private function actor(): User
    {
        $user = auth()->user();
        assert($user instanceof User);

        return $user;
    }
}
