<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookEvents;

use App\Actions\Webhooks\RetryWebhookEvent;
use App\Enums\PaymentProvider;
use App\Enums\WebhookEventStatus;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\WebhookEvents\Pages\ListWebhookEvents;
use App\Filament\Resources\WebhookEvents\Pages\ViewWebhookEvent;
use App\Models\User;
use App\Models\WebhookEvent;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

/**
 * Bandeja técnica de notificaciones de los proveedores (solo Administrador).
 * Muestra la evidencia guardada con la lista permitida: nunca el cuerpo
 * crudo, firmas ni datos personales.
 */
class WebhookEventResource extends Resource
{
    use ReportsActionErrors;

    protected static ?string $model = WebhookEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $modelLabel = 'notificación del proveedor';

    protected static ?string $pluralModelLabel = 'notificaciones de proveedores';

    protected static ?string $navigationLabel = 'Bandeja de webhooks';

    protected static string|\UnitEnum|null $navigationGroup = 'Pagos en línea';

    protected static ?int $navigationSort = 6;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Notificación')->columns(3)->schema([
                TextEntry::make('provider')->label('Proveedor')->badge(),
                TextEntry::make('event_type')->label('Evento'),
                TextEntry::make('status')->label('Estado')->badge(),
                TextEntry::make('external_event_id')->label('Id. del evento')->copyable(),
                TextEntry::make('resource_type')->label('Recurso')->placeholder('—'),
                TextEntry::make('resource_external_id')->label('Id. del recurso')->placeholder('—')->copyable(),
                TextEntry::make('provider_created_at')->label('Creado por el proveedor')->dateTime('d/m/Y H:i:s')->placeholder('—'),
                TextEntry::make('received_at')->label('Recibido')->dateTime('d/m/Y H:i:s'),
                TextEntry::make('processed_at')->label('Procesado')->dateTime('d/m/Y H:i:s')->placeholder('—'),
                TextEntry::make('attempts')->label('Intentos de procesamiento'),
                TextEntry::make('last_error')->label('Último error')->placeholder('—')->columnSpanFull(),
                TextEntry::make('payment_id')->label('Pago')->placeholder('—')
                    ->formatStateUsing(fn (int $state): string => "Ver pago #{$state}")
                    ->url(fn (WebhookEvent $record): ?string => $record->payment_id !== null
                        ? PaymentResource::getUrl('view', ['record' => $record->payment_id]) : null),
            ]),
            Section::make('Evidencia guardada (lista permitida)')->schema([
                TextEntry::make('payload')->label('')
                    ->state(fn (WebhookEvent $record): string => (string) json_encode($record->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    ->fontFamily('mono')->extraAttributes(['style' => 'white-space: pre-wrap']),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('received_at')->label('Recibido')->dateTime('d/m/Y H:i:s')->sortable(),
                TextColumn::make('provider')->label('Proveedor')->badge(),
                TextColumn::make('event_type')->label('Evento')->searchable(),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make('attempts')->label('Intentos'),
                TextColumn::make('resource_external_id')->label('Recurso')->placeholder('—')->searchable()->limit(30),
            ])
            ->defaultSort('received_at', 'desc')
            ->filters([
                SelectFilter::make('provider')->label('Proveedor')->options(PaymentProvider::class),
                SelectFilter::make('status')->label('Estado')->options(WebhookEventStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('Sin notificaciones recibidas');
    }

    public static function retryAction(): Action
    {
        return Action::make('retry')
            ->label('Reprocesar')
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->modalDescription('Se volverá a consultar el estado actual en el proveedor. Es seguro repetirlo: no duplica pagos ni donativos.')
            ->visible(fn (WebhookEvent $record): bool => Gate::allows('retry', $record))
            ->action(function (WebhookEvent $record): void {
                /** @var User $actor */
                $actor = auth()->user();
                self::notifyOutcome(fn () => app(RetryWebhookEvent::class)->handle($record, $actor), 'Notificación enviada a procesar');
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookEvents::route('/'),
            'view' => ViewWebhookEvent::route('/{record}'),
        ];
    }
}
