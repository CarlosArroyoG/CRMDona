<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests;

use App\Enums\PaymentRequestFrequency;
use App\Enums\PaymentRequestStatus;
use App\Filament\Resources\Donors\DonorResource;
use App\Filament\Resources\PaymentRequests\Pages\ListPaymentRequests;
use App\Filament\Resources\PaymentRequests\Pages\ViewPaymentRequest;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\PaymentRequest;
use BackedEnum;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Solicitudes de pago (cobro asistido): trazabilidad de los cobros con
 * tarjeta preparados desde "Crear donativo". Se crean ahí; aquí se abren,
 * copian, envían, cancelan o regeneran. Sin datos técnicos del proveedor.
 */
class PaymentRequestResource extends Resource
{
    protected static ?string $model = PaymentRequest::class;

    protected static ?string $slug = 'solicitudes-de-pago';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $modelLabel = 'solicitud de pago';

    protected static ?string $pluralModelLabel = 'Solicitudes de pago';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Donativos';

    protected static ?int $navigationSort = 4;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Enlace para el donante')
                ->description('El donante escribe su tarjeta en la página segura del proveedor de pago; el CRM nunca ve esos datos. El donativo se registra solo cuando el proveedor confirma el pago.')
                ->visible(fn (PaymentRequest $record): bool => $record->isUsable() && Gate::allows('manage', $record))
                ->schema([
                    TextEntry::make('link')->label('Enlace de pago')
                        ->state(fn (PaymentRequest $record): string => $record->url())
                        ->copyable()->copyMessage('Enlace copiado')->fontFamily('mono')
                        ->helperText(fn (PaymentRequest $record): string => 'Válido hasta el '.$record->expires_at->timezone(config()->string('app.timezone'))->format('d/m/Y H:i').'. Envíalo solo a este donante.'),
                ]),
            Section::make('Solicitud')->columns(3)->schema([
                TextEntry::make('status')->label('Situación')->badge()
                    ->state(fn (PaymentRequest $record): PaymentRequestStatus => $record->effectiveStatus()),
                TextEntry::make('donor.display_name')->label('Donante')
                    ->url(fn (PaymentRequest $record): string => DonorResource::getUrl('view', ['record' => $record->donor_id])),
                TextEntry::make('amount')->label('Importe')
                    ->state(fn (PaymentRequest $record): string => $record->amountLabel()),
                TextEntry::make('frequency')->label('Frecuencia')->badge(),
                TextEntry::make('destination')->label('Destino')
                    ->state(fn (PaymentRequest $record): string => ucfirst($record->destinationLabel())),
                IconEntry::make('tax_receipt_requested')->label('CFDI solicitado')->boolean(),
                TextEntry::make('expires_at')->label('Vigente hasta')->dateTime('d/m/Y H:i'),
                TextEntry::make('createdBy.name')->label('Preparada por'),
                TextEntry::make('created_at')->label('Preparada el')->dateTime('d/m/Y H:i'),
                TextEntry::make('paid_at')->label('Pagada el')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('payment_id')->label('Pago en línea')->placeholder('—')
                    ->visible(fn (PaymentRequest $record): bool => $record->payment_id !== null)
                    ->formatStateUsing(fn (int $state): string => "Ver pago #{$state}")
                    ->url(fn (PaymentRequest $record): ?string => $record->payment_id !== null ? PaymentResource::getUrl('view', ['record' => $record->payment_id]) : null),
                TextEntry::make('subscription_id')->label('Donativo mensual')->placeholder('—')
                    ->visible(fn (PaymentRequest $record): bool => $record->subscription_id !== null)
                    ->formatStateUsing(fn (int $state): string => "Ver donativo mensual #{$state}")
                    ->url(fn (PaymentRequest $record): ?string => $record->subscription_id !== null ? SubscriptionResource::getUrl('view', ['record' => $record->subscription_id]) : null),
                TextEntry::make('cancelledBy.name')->label('Cancelada por')->placeholder('—')
                    ->visible(fn (PaymentRequest $record): bool => $record->cancelled_at !== null),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['donor', 'campaign', 'program', 'createdBy']))
            ->columns([
                TextColumn::make('created_at')->label('Preparada el')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('donor.display_name')->label('Donante'),
                TextColumn::make('amount')->label('Importe')->alignEnd()->sortable()
                    ->state(fn (PaymentRequest $record): string => $record->amountLabel()),
                TextColumn::make('frequency')->label('Frecuencia')->badge(),
                TextColumn::make('destination')->label('Destino')->toggleable()
                    ->state(fn (PaymentRequest $record): string => ucfirst($record->destinationLabel())),
                TextColumn::make('status')->label('Situación')->badge()
                    ->state(fn (PaymentRequest $record): PaymentRequestStatus => $record->effectiveStatus()),
                TextColumn::make('expires_at')->label('Vigente hasta')->date('d/m/Y')->sortable(),
                TextColumn::make('createdBy.name')->label('Preparada por')->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Situación')
                    ->options(collect(PaymentRequestStatus::cases())->mapWithKeys(fn (PaymentRequestStatus $status): array => [$status->value => $status->getLabel()])->all())
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        PaymentRequestStatus::Open->value => $query->where('status', PaymentRequestStatus::Open->value)->where('expires_at', '>', now()),
                        PaymentRequestStatus::Expired->value => $query->where('status', PaymentRequestStatus::Open->value)->where('expires_at', '<=', now()),
                        PaymentRequestStatus::Paid->value, PaymentRequestStatus::Cancelled->value => $query->where('status', $data['value']),
                        default => $query,
                    }),
                SelectFilter::make('frequency')->label('Frecuencia')->options(PaymentRequestFrequency::class),
            ])
            ->emptyStateHeading('Aún no hay solicitudes de pago')
            ->emptyStateDescription('Se preparan desde "Crear donativo" eligiendo "Cobrar con tarjeta en línea".');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentRequests::route('/'),
            'view' => ViewPaymentRequest::route('/{record}'),
        ];
    }
}
