<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions\RelationManagers;

use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Payment;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Mensualidades del donativo mensual: un pago por periodo.
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Mensualidades';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('billing_period_start')->label('Periodo')->date('m/Y')->sortable(),
                TextColumn::make('amount')->label('Importe')->alignEnd()->formatStateUsing(fn (string $state): string => Money::format($state)),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make('succeeded_at')->label('Cobrado el')->dateTime('d/m/Y')->placeholder('—'),
            ])
            ->defaultSort('billing_period_start', 'desc')
            ->recordActions([
                Action::make('open')->label('Ver')->icon('heroicon-o-eye')
                    ->url(fn (Payment $record): string => PaymentResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading('Aún no hay mensualidades');
    }
}
