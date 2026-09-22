<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donors\RelationManagers;

use App\Filament\Resources\Donations\DonationResource;
use App\Models\Donation;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Historial de donativos del donante (solo consulta).
 */
class DonationsRelationManager extends RelationManager
{
    protected static string $relationship = 'donations';

    protected static ?string $title = 'Donativos';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('received_on')->label('Fecha')->date('d/m/Y')->sortable(),
                TextColumn::make('kind')->label('Tipo')->badge(),
                TextColumn::make('amount')->label('Importe o valor')->alignEnd()
                    ->formatStateUsing(fn (string $state): string => Money::format($state)),
                TextColumn::make('campaign.name')->label('Campaña')->placeholder('—'),
                TextColumn::make('status')->label('Estado')->badge(),
            ])
            ->defaultSort('received_on', 'desc')
            ->recordActions([
                Action::make('open')->label('Ver')->icon('heroicon-o-eye')
                    ->url(fn (Donation $record): string => DonationResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading('Este donante aún no tiene donativos');
    }
}
