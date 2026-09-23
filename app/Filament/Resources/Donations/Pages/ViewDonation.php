<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donations\Pages;

use App\Actions\Cfdi\RequestDonationCfdi;
use App\Cfdi\CfdiProviderRegistry;
use App\Enums\DonationStatus;
use App\Enums\Permission;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Donations\DonationResource;
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
        ];
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
