<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments;

use App\Actions\Refunds\RequestRefund;
use App\Enums\FailureCategory;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\RefundReason;
use App\Enums\RefundState;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Concerns\ResolvesActor;
use App\Filament\Exports\PaymentExporter;
use App\Filament\Exports\PaymentTechnicalExporter;
use App\Filament\Resources\Donations\DonationResource;
use App\Filament\Resources\Donors\DonorResource;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\RelationManagers\AttemptsRelationManager;
use App\Filament\Resources\Payments\RelationManagers\DisputesRelationManager;
use App\Filament\Resources\Payments\RelationManagers\RefundsRelationManager;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Donor;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Program;
use App\Models\User;
use App\Reports\PaymentReport;
use App\Support\Money;
use App\Support\Search;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Pagos en línea (solo consulta; los escribe el proveedor). La información
 * técnica (identificadores externos, códigos, intentos) solo la ven
 * Administrador y Contador; Solo lectura ve únicamente lo operativo.
 */
class PaymentResource extends Resource
{
    use ReportsActionErrors;
    use ResolvesActor;

    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $modelLabel = 'pago en línea';

    protected static ?string $pluralModelLabel = 'Pagos en línea';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Donativos';

    protected static ?int $navigationSort = 4;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Pago')->columns(3)->schema([
                TextEntry::make('id')->label('Folio interno'),
                TextEntry::make('status')->label('Estado')->badge(),
                TextEntry::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i'),
                TextEntry::make('donor.display_name')->label('Donante')
                    ->url(fn (Payment $record): string => DonorResource::getUrl('view', ['record' => $record->donor_id])),
                TextEntry::make('amount')->label('Importe')->formatStateUsing(fn (string $state): string => Money::format($state).' MXN'),
                TextEntry::make('provider')->label('Proveedor')->badge(),
                TextEntry::make('kind')->label('Tipo')->badge(),
                TextEntry::make('destination')->label('Destino')
                    ->state(fn (Payment $record): string => $record->campaign->name ?? $record->program->name ?? 'Fondo general'),
                TextEntry::make('refund_state')->label('Reembolso')
                    ->state(fn (Payment $record): string => RefundState::describe($record->amount, $record->refundedAmount())),
                TextEntry::make('succeeded_at')->label('Cobrado el')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('subscription_id')->label('Donativo mensual')->placeholder('—')
                    ->formatStateUsing(fn (int $state): string => "Ver donativo mensual #{$state}")
                    ->url(fn (Payment $record): ?string => $record->subscription_id !== null
                        ? SubscriptionResource::getUrl('view', ['record' => $record->subscription_id]) : null),
                TextEntry::make('donation.id')->label('Donativo generado')->placeholder('Aún no (solo al quedar exitoso)')
                    ->formatStateUsing(fn (int $state): string => "Ver donativo #{$state}")
                    ->url(fn (Payment $record): ?string => $record->donation !== null
                        ? DonationResource::getUrl('view', ['record' => $record->donation]) : null),
                TextEntry::make('last_failure')->label('Motivo del último rechazo')->placeholder('—')
                    ->state(fn (Payment $record): ?string => self::lastFailure($record)?->getLabel())
                    ->visible(fn (): bool => self::actorCan(Permission::ViewIncidents)),
            ]),
            Section::make('Información técnica')
                ->description('Solo Administrador y Contador. Sin datos de tarjeta ni secretos.')
                ->columns(3)
                ->visible(fn (): bool => self::canViewTechnical())
                ->schema([
                    TextEntry::make('external_id')->label('Identificador en el proveedor')->placeholder('Aún sin asignar')->copyable(),
                    TextEntry::make('provider_status')->label('Estado en el proveedor')->placeholder('—'),
                    TextEntry::make('provider_updated_at')->label('Actualizado por el proveedor')->dateTime('d/m/Y H:i:s')->placeholder('—'),
                    TextEntry::make('next_retry_owner')->label('Siguiente reintento a cargo de')->placeholder('Sin reintento pendiente'),
                    TextEntry::make('next_retry_at')->label('Siguiente reintento')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('billing_period_start')->label('Periodo')->date('m/Y')->placeholder('No aplica'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $technical = self::canViewTechnical();

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => PaymentReport::withFailedAttemptFlag(Payment::withRefundedAmount($query->with(['donor', 'campaign', 'program']))))
            ->columns([
                TextColumn::make('id')->label('Folio')->sortable(),
                TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('donor.display_name')->label('Donante')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('donor', fn (Builder $donor): Builder => Search::unaccent($donor, 'display_name', $search))),
                TextColumn::make('amount')->label('Importe')->alignEnd()->sortable()
                    ->formatStateUsing(fn (string $state): string => Money::format($state))
                    ->summarize(Summarizer::make()->label('Totales del filtro')->using(function (QueryBuilder $query): string {
                        $totals = PaymentReport::totals($query);

                        return "{$totals['count']} pagos · Importe ".Money::format($totals['amount']).' · Cobrado '.Money::format($totals['succeeded']).' · Reembolsado '.Money::format($totals['refunded']);
                    })),
                TextColumn::make('provider')->label('Proveedor')->badge(),
                TextColumn::make('kind')->label('Tipo')->badge(),
                TextColumn::make('status')->label('Estado')->badge()->sortable(),
                TextColumn::make('refund_state')->label('Reembolso')->badge()
                    ->state(fn (Payment $record): RefundState => RefundState::fromAmounts($record->amount, $record->refunds_succeeded_sum_amount)),
                IconColumn::make('had_failed_attempt')->label('Recuperado')->boolean()->toggleable()
                    ->state(fn (Payment $record): bool => $record->status === PaymentStatus::Succeeded && (bool) $record->getAttribute('had_failed_attempt'))
                    ->trueIcon('heroicon-o-arrow-path')->falseIcon('heroicon-o-minus')->falseColor('gray'),
                TextColumn::make('destination')->label('Destino')->toggleable()
                    ->state(fn (Payment $record): string => $record->campaign->name ?? $record->program->name ?? 'Fondo general'),
                TextColumn::make('external_id')->label('Id. en el proveedor')->placeholder('—')
                    ->visible($technical)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('provider')->label('Proveedor')->options(PaymentProvider::class),
                SelectFilter::make('status')->label('Estado')->options(PaymentStatus::class),
                SelectFilter::make('kind')->label('Único o mensual')->options(PaymentKind::class),
                SelectFilter::make('situation')->label('Situación')->options(PaymentReport::situations())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? PaymentReport::whereSituation($query, (string) $data['value'])
                        : $query),
                SelectFilter::make('failure_category')->label('Motivo de rechazo')->options(FailureCategory::class)
                    ->visible(fn (): bool => self::actorCan(Permission::ViewIncidents))
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('attempts', fn (Builder $attempts) => $attempts->where('failure_category', $data['value']))
                        : $query),
                SelectFilter::make('refund_state')->label('Reembolso')->options(RefundState::class)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? Payment::whereRefundState($query, RefundState::from((string) $data['value']))
                        : $query),
                TernaryFilter::make('has_incident')->label('Con incidencia')
                    ->visible(fn (): bool => self::actorCan(Permission::ViewIncidents))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('incidents'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('incidents'),
                    ),
                SelectFilter::make('donor_id')->label('Donante')->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Search::unaccent(Donor::query(), 'display_name', $search)
                        ->orderBy('display_name')->limit(20)->pluck('display_name', 'id')->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => is_numeric($value) ? Donor::query()->whereKey((int) $value)->value('display_name') : null),
                SelectFilter::make('campaign_id')->label('Campaña')->relationship('campaign', 'name')->searchable()->preload(),
                SelectFilter::make('program')->label('Programa (directo o por campaña)')
                    ->options(fn (): array => Program::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where(fn (Builder $inner) => $inner->where('program_id', (int) $data['value'])
                            ->orWhereHas('campaign', fn (Builder $campaign) => $campaign->where('program_id', (int) $data['value'])))
                        : $query),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('Desde')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('until')->label('Hasta')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->whereDate('created_at', '<=', $date))),
                Filter::make('amount')
                    ->schema([
                        TextInput::make('min')->label('Importe mínimo')->prefix('$')->inputMode('decimal'),
                        TextInput::make('max')->label('Importe máximo')->prefix('$')->inputMode('decimal'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(self::moneyOrNull($data['min'] ?? null), fn (Builder $q, string $min) => $q->where('amount', '>=', $min))
                        ->when(self::moneyOrNull($data['max'] ?? null), fn (Builder $q, string $max) => $q->where('amount', '<=', $max))),
            ])
            ->headerActions([
                ExportAction::make()->label('Exportar')
                    ->exporter($technical ? PaymentTechnicalExporter::class : PaymentExporter::class)
                    ->visible(fn (): bool => Gate::allows('export', Payment::class)),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No hay pagos en línea que mostrar')
            ->emptyStateDescription('Los pagos aparecen aquí cuando un donante dona en línea.');
    }

    /**
     * Solicitar reembolso: Administrador y Contador. Motivo obligatorio del
     * catálogo; la llave de idempotencia se genera al abrir el formulario,
     * así que un doble clic no duplica la solicitud.
     */
    public static function refundAction(): Action
    {
        return Action::make('refund')
            ->label('Solicitar reembolso')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->modalHeading('Solicitar reembolso')
            ->modalDescription('El reembolso se envía al proveedor. No borra el pago ni cancela el donativo: queda registrado quién lo pidió, cuándo, el importe y el motivo.')
            ->fillForm(fn (Payment $record): array => [
                'amount' => $record->refundableAmount(),
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->schema([
                TextInput::make('amount')->label('Importe a reembolsar (MXN)')->required()->prefix('$')->inputMode('decimal')
                    ->helperText(fn (Payment $record): string => 'Disponible para reembolso: '.Money::format($record->refundableAmount()).' MXN.'),
                Select::make('reason')->label('Motivo')->options(RefundReason::selectable())->required()->live()->native(false),
                Textarea::make('reason_comment')->label('Comentario')->rows(3)->maxLength(1000)
                    ->required(fn (Get $get): bool => $get('reason') === RefundReason::Other->value)
                    ->helperText('Obligatorio si eliges "Otro".'),
                Hidden::make('idempotency_key'),
            ])
            ->modalSubmitActionLabel('Enviar reembolso')
            ->visible(fn (Payment $record): bool => Gate::allows('refund', $record) && Money::compare($record->refundableAmount(), '0') > 0)
            ->action(function (Payment $record, array $data): void {
                /** @var User $actor */
                $actor = auth()->user();

                // Si el proveedor no responde, el reembolso queda "En proceso" y la conciliación lo reenvía.
                $refund = self::withFormErrors(fn () => app(RequestRefund::class)->handle($record, $data, $actor), 'mountedActions.0.data.');

                Notification::make()->success()
                    ->title('Reembolso solicitado')
                    ->body('Estado: '.$refund->status->getLabel().'. Si el proveedor no respondió, el sistema lo reintentará con la misma solicitud.')
                    ->send();
            });
    }

    public static function canViewTechnical(): bool
    {
        return self::actorCan(Permission::ViewPaymentTechnicalDetails);
    }

    public static function getRelations(): array
    {
        return [
            AttemptsRelationManager::class,
            RefundsRelationManager::class,
            DisputesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }

    private static function lastFailure(Payment $payment): ?FailureCategory
    {
        return PaymentAttempt::query()->where('payment_id', $payment->id)
            ->where('status', PaymentAttemptStatus::Failed->value)->orderByDesc('attempt_number')->first()?->failure_category;
    }

    private static function moneyOrNull(mixed $value): ?string
    {
        return is_string($value) && Money::isValid($value) ? Money::normalize($value) : null;
    }
}
