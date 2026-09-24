<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions;

use App\Actions\Subscriptions\CancelSubscription;
use App\Actions\Subscriptions\PauseSubscription;
use App\Actions\Subscriptions\ResumeSubscription;
use App\Enums\PaymentProvider;
use App\Enums\Permission;
use App\Enums\SubscriptionStatus;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Concerns\ResolvesActor;
use App\Filament\Resources\Donors\DonorResource;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Filament\Resources\Subscriptions\RelationManagers\PaymentsRelationManager;
use App\Models\Subscription;
use App\Models\User;
use App\Payments\Exceptions\PaymentProviderException;
use App\Support\Money;
use App\Support\Search;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Donativos mensuales. Pausar, reanudar y cancelar (Administrador y
 * Coordinador) siempre piden motivo y confirmación, y solo se reflejan en el
 * CRM cuando el proveedor los confirma. Un cobro fallido nunca cancela.
 */
class SubscriptionResource extends Resource
{
    use ReportsActionErrors;
    use ResolvesActor;

    protected static ?string $model = Subscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $modelLabel = 'donativo mensual';

    protected static ?string $pluralModelLabel = 'donativos mensuales';

    protected static string|\UnitEnum|null $navigationGroup = 'Pagos en línea';

    protected static ?int $navigationSort = 2;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Donativo mensual')->columns(3)->schema([
                TextEntry::make('id')->label('Folio interno'),
                TextEntry::make('status')->label('Estado')->badge(),
                TextEntry::make('donor.display_name')->label('Donante')
                    ->url(fn (Subscription $record): string => DonorResource::getUrl('view', ['record' => $record->donor_id])),
                TextEntry::make('amount')->label('Importe mensual')->formatStateUsing(fn (string $state): string => Money::format($state).' MXN'),
                TextEntry::make('provider')->label('Proveedor')->badge(),
                TextEntry::make('destination')->label('Destino')
                    ->state(fn (Subscription $record): string => $record->campaign->name ?? $record->program->name ?? 'Fondo general'),
                TextEntry::make('started_at')->label('Activo desde')->dateTime('d/m/Y')->placeholder('—'),
                TextEntry::make('next_charge_at')->label('Próximo cobro')->dateTime('d/m/Y')->placeholder('—'),
                TextEntry::make('retry_owner')->label('Reintentos de cobro a cargo de'),
            ]),
            Section::make('Cambios')->columns(3)->schema([
                TextEntry::make('paused_at')->label('Pausado el')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('pausedBy.name')->label('Pausado por')->placeholder('—'),
                TextEntry::make('resumed_at')->label('Reanudado el')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('cancelled_at')->label('Cancelado el')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('cancellation_source')->label('Cancelado por')->placeholder('—')
                    ->formatStateUsing(fn (Subscription $record): string => $record->cancelledBy->name ?? $record->cancellation_source?->getLabel() ?? '—'),
                TextEntry::make('cancellation_reason')->label('Motivo de cancelación')->placeholder('—'),
            ]),
            Section::make('Información técnica')->columns(3)
                ->visible(fn (): bool => self::actorCan(Permission::ViewPaymentTechnicalDetails))
                ->schema([
                    TextEntry::make('external_id')->label('Identificador en el proveedor')->placeholder('Aún sin asignar')->copyable(),
                    TextEntry::make('provider_status')->label('Estado en el proveedor')->placeholder('—'),
                    TextEntry::make('provider_updated_at')->label('Actualizado por el proveedor')->dateTime('d/m/Y H:i:s')->placeholder('—'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['donor', 'campaign', 'program']))
            ->columns([
                TextColumn::make('id')->label('Folio')->sortable(),
                TextColumn::make('donor.display_name')->label('Donante')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('donor', fn (Builder $donor): Builder => Search::unaccent($donor, 'display_name', $search))),
                TextColumn::make('amount')->label('Importe mensual')->alignEnd()->sortable()
                    ->formatStateUsing(fn (string $state): string => Money::format($state)),
                TextColumn::make('provider')->label('Proveedor')->badge(),
                TextColumn::make('status')->label('Estado')->badge()->sortable(),
                TextColumn::make('next_charge_at')->label('Próximo cobro')->date('d/m/Y')->placeholder('—')->sortable(),
                TextColumn::make('started_at')->label('Activo desde')->date('d/m/Y')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(SubscriptionStatus::class),
                SelectFilter::make('provider')->label('Proveedor')->options(PaymentProvider::class),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No hay donativos mensuales');
    }

    public static function pauseAction(): Action
    {
        return self::changeAction('pause', 'Pausar', Heroicon::OutlinedPause, 'warning',
            'El proveedor dejará de cobrar hasta que se reanude. Queda registrado quién lo pausó y por qué.',
            fn (Subscription $record, string $reason, User $actor) => app(PauseSubscription::class)->handle($record, $reason, $actor),
            'Donativo mensual pausado');
    }

    public static function resumeAction(): Action
    {
        return self::changeAction('resume', 'Reanudar', Heroicon::OutlinedPlay, 'success',
            'El proveedor volverá a cobrar en la fecha que corresponda.',
            fn (Subscription $record, string $reason, User $actor) => app(ResumeSubscription::class)->handle($record, $reason, $actor),
            'Donativo mensual reanudado');
    }

    public static function cancelAction(): Action
    {
        return self::changeAction('cancel', 'Cancelar', Heroicon::OutlinedXCircle, 'danger',
            'Se cancela en el proveedor y ya no habrá más cobros. Los pagos y donativos anteriores no cambian. Esta acción no se puede deshacer.',
            fn (Subscription $record, string $reason, User $actor) => app(CancelSubscription::class)->handle($record, $reason, $actor),
            'Donativo mensual cancelado');
    }

    public static function getRelations(): array
    {
        return [PaymentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptions::route('/'),
            'view' => ViewSubscription::route('/{record}'),
        ];
    }

    /**
     * @param  callable(Subscription, string, User): mixed  $handler
     */
    private static function changeAction(string $name, string $label, Heroicon $icon, string $color, string $description, callable $handler, string $success): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->modalHeading("{$label} donativo mensual")
            ->modalDescription($description)
            ->schema([
                Textarea::make('reason')->label('Motivo')->required()->minLength(5)->maxLength(1000),
            ])
            ->modalSubmitActionLabel($label)
            ->visible(fn (Subscription $record): bool => Gate::allows($name, $record))
            ->action(function (Subscription $record, array $data) use ($handler, $success): void {
                /** @var User $actor */
                $actor = auth()->user();

                try {
                    $handler($record, (string) $data['reason'], $actor);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('No se pudo completar la acción')
                        ->body(implode(' ', $exception->validator->errors()->all()))->send();

                    return;
                } catch (PaymentProviderException $exception) {
                    Notification::make()->danger()->title('El proveedor no respondió')->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title($success)->send();
            });
    }
}
