<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donations\Pages;

use App\Actions\Cfdi\RequestDonationCfdi;
use App\Actions\Communications\IssueDonationReceipt;
use App\Actions\Communications\QueueDonationThankYou;
use App\Cfdi\CfdiProviderRegistry;
use App\Enums\DonationStatus;
use App\Enums\Permission;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Donations\DonationResource;
use App\Models\Communication;
use App\Models\Donation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class ViewDonation extends ViewRecord
{
    use ReportsActionErrors;

    protected static string $resource = DonationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DonationResource::confirmAction()->after(fn () => $this->getRecord()->refresh()),
            DonationResource::cancelAction()->after(fn () => $this->getRecord()->refresh()),
            $this->issueCfdiAction(),
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
            ->label('Recibo simple')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->color('gray')
            ->visible(function (Donation $record): bool {
                $user = auth()->user();

                return $user instanceof User && $user->hasPermission(Permission::ViewDonationReceipts)
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
            ->modalDescription('Se envía al correo del donante con el recibo simple y, si ya está timbrado, el CFDI.')
            ->visible(function (Donation $record): bool {
                $user = auth()->user();

                return $user instanceof User && $user->hasPermission(Permission::ResendCommunications)
                    && $record->status === DonationStatus::Confirmed
                    && ! Communication::query()->where('dedupe_key', "thank_you:donation:{$record->id}")->exists();
            })
            ->action(function (Donation $record): void {
                /** @var User $actor */
                $actor = auth()->user();
                self::notifyOutcome(fn () => app(QueueDonationThankYou::class)->handle($record, force: true, requestedById: $actor->id), 'Agradecimiento en cola');
            });
    }

    /**
     * Emitir CFDI (Administrador y Contador): valida datos fiscales y reglas
     * antes de encolar el timbrado. Un donativo tiene como máximo un CFDI vigente.
     */
    private function issueCfdiAction(): Action
    {
        return Action::make('issueCfdi')
            ->label('Emitir CFDI')
            ->icon(Heroicon::OutlinedDocumentCheck)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Se timbrará el comprobante fiscal con el complemento de donatarias. Revisa que los datos fiscales del donante sean correctos.')
            ->visible(function (Donation $record): bool {
                $user = auth()->user();

                return $user instanceof User && $user->hasPermission(Permission::IssueCfdis)
                    && $record->status === DonationStatus::Confirmed
                    && $record->activeCfdi() === null
                    && app(CfdiProviderRegistry::class)->isConfigured();
            })
            ->action(function (Donation $record): void {
                /** @var User $actor */
                $actor = auth()->user();
                self::notifyOutcome(
                    fn () => app(RequestDonationCfdi::class)->handle($record, $actor, 'manual:'.$record->id.':'.Str::uuid()),
                    'CFDI en cola de timbrado',
                );
            });
    }
}
