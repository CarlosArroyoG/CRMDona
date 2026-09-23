<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentDisputes;

use App\Enums\DisputeStatus;
use App\Enums\PaymentProvider;
use App\Filament\Resources\PaymentDisputes\Pages\ListPaymentDisputes;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\PaymentDispute;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Disputas y contracargos (Administrador y Contador). Se atienden en el
 * panel del proveedor; aquí se da seguimiento. No cambian el pago, el
 * donativo, el recibo ni el CFDI.
 */
class PaymentDisputeResource extends Resource
{
    protected static ?string $model = PaymentDispute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $modelLabel = 'disputa';

    protected static ?string $pluralModelLabel = 'disputas y contracargos';

    protected static string|\UnitEnum|null $navigationGroup = 'Pagos en línea';

    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('payment.donor'))
            ->columns([
                TextColumn::make('opened_at')->label('Abierta el')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('payment.donor.display_name')->label('Donante'),
                TextColumn::make('amount')->label('Importe')->alignEnd()->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => Money::format($state)),
                TextColumn::make('provider')->label('Proveedor')->badge(),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make('provider_reason')->label('Motivo del proveedor')->placeholder('—'),
                TextColumn::make('evidence_due_at')->label('Responder antes de')->date('d/m/Y')->placeholder('—'),
            ])
            ->defaultSort('opened_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(DisputeStatus::class),
                SelectFilter::make('provider')->label('Proveedor')->options(PaymentProvider::class),
            ])
            ->recordActions([
                Action::make('payment')->label('Ver pago')->icon('heroicon-o-eye')
                    ->url(fn (PaymentDispute $record): string => PaymentResource::getUrl('view', ['record' => $record->payment_id])),
            ])
            ->emptyStateHeading('Sin disputas');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentDisputes::route('/'),
        ];
    }
}
