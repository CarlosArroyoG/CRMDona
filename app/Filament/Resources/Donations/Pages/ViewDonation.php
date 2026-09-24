<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donations\Pages;

use App\Actions\Communications\IssueDonationReceipt;
use App\Actions\Communications\QueueDonationThankYou;
use App\Enums\DonationStatus;
use App\Enums\Permission;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Concerns\ResolvesActor;
use App\Filament\Resources\Donations\DonationResource;
use App\Models\Communication;
use App\Models\Donation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewDonation extends ViewRecord
{
    use ReportsActionErrors;
    use ResolvesActor;

    protected static string $resource = DonationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DonationResource::confirmAction()->after(fn () => $this->getRecord()->refresh()),
            DonationResource::cancelAction()->after(fn () => $this->getRecord()->refresh()),
            $this->receiptAction(),
            $this->thankYouAction(),
        ];
    }

    /**
     * Recibo simple (no fiscal): se genera si aún no existe y se descarga del
     * disco privado.
     */
    private function receiptAction(): Action
    {
        return Action::make('receipt')
            ->label('Descargar recibo')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->color('gray')
            ->visible(function (Donation $record): bool {
                return self::actorCan(Permission::ViewDonationReceipts)
                    && $record->status === DonationStatus::Confirmed;
            })
            ->action(function (Donation $record) {
                return redirect()->route('receipts.file', ['receipt' => app(IssueDonationReceipt::class)->handle($record)]);
            });
    }

    /**
     * Enviar el agradecimiento a mano (por ejemplo, si el automático estaba
     * apagado). Si ya existe, se reenvía desde Historial de envíos.
     */
    private function thankYouAction(): Action
    {
        return Action::make('sendThankYou')
            ->label('Enviar agradecimiento')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Se envía al correo del donante con el recibo simple adjunto.')
            ->visible(function (Donation $record): bool {
                return self::actorCan(Permission::ResendCommunications)
                    && $record->status === DonationStatus::Confirmed
                    && ! Communication::query()->where('dedupe_key', "thank_you:donation:{$record->id}")->exists();
            })
            ->action(function (Donation $record): void {
                /** @var User $actor */
                $actor = auth()->user();
                self::notifyOutcome(fn () => app(QueueDonationThankYou::class)->handle($record, force: true, requestedById: $actor->id), 'Agradecimiento en cola');
            });
    }
}
