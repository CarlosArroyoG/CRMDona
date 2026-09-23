<?php

declare(strict_types=1);

namespace App\Filament\Resources\Refunds;

use App\Enums\PaymentProvider;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Refunds\Pages\ListRefunds;
use App\Models\Refund;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Todos los reembolsos (Administrador y Contador): quién, cuándo, importe,
 * motivo, proveedor y resultado. Se solicitan desde el pago.
 */
class RefundResource extends Resource
{
    protected static ?string $model = Refund::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static ?string $modelLabel = 'reembolso';

    protected static ?string $pluralModelLabel = 'reembolsos';

    protected static string|\UnitEnum|null $navigationGroup = 'Pagos en línea';

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['payment.donor', 'requestedBy']))
            ->columns([
                TextColumn::make('requested_at')->label('Solicitado el')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('payment.donor.display_name')->label('Donante'),
                TextColumn::make('amount')->label('Importe')->alignEnd()->sortable()
                    ->formatStateUsing(fn (string $state): string => Money::format($state)),
                TextColumn::make('provider')->label('Proveedor')->badge(),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make('reason')->label('Motivo'),
                TextColumn::make('requestedBy.name')->label('Solicitado por')->placeholder('Proveedor'),
                TextColumn::make('failure_reason')->label('Motivo del fallo')->placeholder('—')->limit(40)->toggleable(),
            ])
            ->defaultSort('requested_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(RefundStatus::class),
                SelectFilter::make('reason')->label('Motivo')->options(RefundReason::class),
                SelectFilter::make('provider')->label('Proveedor')->options(PaymentProvider::class),
                Filter::make('requested_at')
                    ->schema([
                        DatePicker::make('from')->label('Desde')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('until')->label('Hasta')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('requested_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->whereDate('requested_at', '<=', $date))),
            ])
            ->recordActions([
                Action::make('payment')->label('Ver pago')->icon('heroicon-o-eye')
                    ->url(fn (Refund $record): string => PaymentResource::getUrl('view', ['record' => $record->payment_id])),
            ])
            ->emptyStateHeading('Sin reembolsos');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRefunds::route('/'),
        ];
    }
}
