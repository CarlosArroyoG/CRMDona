<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentIncidents;

use App\Actions\Incidents\AddIncidentNote;
use App\Actions\Incidents\ResolveIncident;
use App\Actions\Incidents\TakeIncidentForReview;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\PaymentProvider;
use App\Enums\Permission;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\PaymentIncidents\Pages\ListPaymentIncidents;
use App\Filament\Resources\PaymentIncidents\Pages\ViewPaymentIncident;
use App\Filament\Resources\PaymentIncidents\RelationManagers\NotesRelationManager;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Resources\WebhookEvents\WebhookEventResource;
use App\Models\PaymentIncident;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
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
 * Incidencias de pagos (RF-01): Nueva → En revisión → Resuelta. El
 * Coordinador ve y atiende solo las operativas y sin detalle técnico;
 * Administrador y Contador, todas. Solo lectura no tiene acceso.
 */
class PaymentIncidentResource extends Resource
{
    use ReportsActionErrors;

    protected static ?string $model = PaymentIncident::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $modelLabel = 'incidencia';

    protected static ?string $pluralModelLabel = 'incidencias';

    protected static string|\UnitEnum|null $navigationGroup = 'Pagos en línea';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $actor = self::actor();

        return $actor !== null && ! $actor->hasPermission(Permission::HandleTechnicalIncidents)
            ? $query->whereIn('type', array_map(fn (IncidentType $type): string => $type->value, IncidentType::operational()))
            : $query;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = self::getEloquentQuery()->where('status', '!=', IncidentStatus::Resolved->value)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Incidencia')->columns(3)->schema([
                TextEntry::make('type')->label('Tipo'),
                TextEntry::make('severity')->label('Severidad')->badge(),
                TextEntry::make('status')->label('Estado')->badge(),
                TextEntry::make('required_action')->label('Qué hacer')->columnSpanFull()
                    ->state(fn (PaymentIncident $record): string => $record->type->requiredAction()),
                TextEntry::make('detected_at')->label('Detectada el')->dateTime('d/m/Y H:i'),
                TextEntry::make('donor')->label('Donante')->placeholder('—')
                    ->state(fn (PaymentIncident $record): ?string => $record->donor()?->display_name),
                TextEntry::make('amount')->label('Importe')->placeholder('—')
                    ->state(fn (PaymentIncident $record): ?string => ($amount = $record->payment->amount ?? $record->subscription?->amount) !== null
                        ? Money::format($amount).' MXN' : null),
                TextEntry::make('failure_category')->label('Motivo')->placeholder('—'),
                TextEntry::make('payment_id')->label('Pago')->placeholder('—')
                    ->formatStateUsing(fn (int $state): string => "Ver pago #{$state}")
                    ->url(fn (PaymentIncident $record): ?string => $record->payment_id !== null
                        ? PaymentResource::getUrl('view', ['record' => $record->payment_id]) : null),
                TextEntry::make('subscription_id')->label('Donativo mensual')->placeholder('—')
                    ->formatStateUsing(fn (int $state): string => "Ver donativo mensual #{$state}")
                    ->url(fn (PaymentIncident $record): ?string => $record->subscription_id !== null
                        ? SubscriptionResource::getUrl('view', ['record' => $record->subscription_id]) : null),
            ]),
            Section::make('Seguimiento')->columns(3)->schema([
                TextEntry::make('reviewingBy.name')->label('En revisión por')->placeholder('Nadie la ha tomado'),
                TextEntry::make('reviewing_started_at')->label('Tomada el')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('resolvedBy.name')->label('Resuelta por')->placeholder('—'),
                TextEntry::make('resolved_at')->label('Resuelta el')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('resolution')->label('Resolución')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Información técnica')->columns(3)
                ->description('Solo Administrador y Contador.')
                ->visible(fn (): bool => Gate::allows('viewTechnical', PaymentIncident::class))
                ->schema([
                    TextEntry::make('provider')->label('Proveedor')->placeholder('—'),
                    TextEntry::make('attempt.provider_code')->label('Código del proveedor')->placeholder('—'),
                    TextEntry::make('attempt.external_id')->label('Intento en el proveedor')->placeholder('—'),
                    TextEntry::make('refund.external_id')->label('Reembolso en el proveedor')->placeholder('—'),
                    TextEntry::make('dispute.external_id')->label('Disputa en el proveedor')->placeholder('—'),
                    TextEntry::make('webhook_event_id')->label('Notificación')->placeholder('—')
                        ->formatStateUsing(fn (int $state): string => "Ver notificación #{$state}")
                        ->url(fn (PaymentIncident $record): ?string => $record->webhook_event_id !== null && Gate::allows('viewAny', WebhookEvent::class)
                            ? WebhookEventResource::getUrl('view', ['record' => $record->webhook_event_id]) : null),
                    TextEntry::make('dedupe_key')->label('Hecho (clave única)')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['payment.donor', 'subscription.donor', 'reviewingBy']))
            ->columns([
                TextColumn::make('detected_at')->label('Detectada')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('type')->label('Tipo')->wrap(),
                TextColumn::make('severity')->label('Severidad')->badge(),
                TextColumn::make('status')->label('Estado')->badge()->sortable(),
                TextColumn::make('donor')->label('Donante')->placeholder('—')
                    ->state(fn (PaymentIncident $record): ?string => $record->donor()?->display_name),
                TextColumn::make('reviewingBy.name')->label('En revisión por')->placeholder('—'),
            ])
            ->defaultSort('detected_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(IncidentStatus::class),
                SelectFilter::make('type')->label('Tipo')
                    ->options(fn (): array => collect(self::actor()?->hasPermission(Permission::HandleTechnicalIncidents) ? IncidentType::cases() : IncidentType::operational())
                        ->mapWithKeys(fn (IncidentType $type): array => [$type->value => $type->getLabel()])->all()),
                SelectFilter::make('severity')->label('Severidad')->options(IncidentSeverity::class),
                SelectFilter::make('provider')->label('Proveedor')->options(PaymentProvider::class),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('Sin incidencias')
            ->emptyStateDescription('Aquí aparecen los problemas de pagos que requieren atención.');
    }

    public static function takeAction(): Action
    {
        return Action::make('take')
            ->label('Tomar para revisión')
            ->icon(Heroicon::OutlinedHandRaised)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('Quedará registrado que tú la estás revisando.')
            ->visible(fn (PaymentIncident $record): bool => Gate::allows('take', $record))
            ->action(function (PaymentIncident $record): void {
                /** @var User $actor */
                $actor = auth()->user();
                self::notifyOutcome(fn () => app(TakeIncidentForReview::class)->handle($record, $actor), 'Incidencia en revisión');
            });
    }

    public static function resolveAction(): Action
    {
        return Action::make('resolve')
            ->label('Marcar como resuelta')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalHeading('Resolver incidencia')
            ->modalDescription('Describe qué se hizo. La resolución queda registrada y no se puede editar.')
            ->schema([
                Textarea::make('resolution')->label('Resolución')->required()->minLength(5)->maxLength(2000)
                    ->helperText('Ejemplo: "Se contactó al donante; actualizará su tarjeta."'),
            ])
            ->modalSubmitActionLabel('Resolver')
            ->visible(fn (PaymentIncident $record): bool => Gate::allows('resolve', $record))
            ->action(function (PaymentIncident $record, array $data): void {
                /** @var User $actor */
                $actor = auth()->user();
                self::notifyOutcome(fn () => app(ResolveIncident::class)->handle($record, (string) $data['resolution'], $actor), 'Incidencia resuelta');
            });
    }

    public static function addNoteAction(): Action
    {
        return Action::make('addNote')
            ->label('Agregar nota')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->modalHeading('Agregar nota de seguimiento')
            ->modalDescription('Las notas no se pueden editar ni borrar.')
            ->schema([
                Textarea::make('body')->label('Nota')->required()->maxLength(5000)->rows(4),
            ])
            ->modalSubmitActionLabel('Agregar')
            ->visible(fn (PaymentIncident $record): bool => Gate::allows('addNote', $record))
            ->action(function (PaymentIncident $record, array $data): void {
                /** @var User $actor */
                $actor = auth()->user();
                self::notifyOutcome(fn () => app(AddIncidentNote::class)->handle($record, (string) $data['body'], $actor), 'Nota agregada');
            });
    }

    public static function getRelations(): array
    {
        return [NotesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentIncidents::route('/'),
            'view' => ViewPaymentIncident::route('/{record}'),
        ];
    }

    private static function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
