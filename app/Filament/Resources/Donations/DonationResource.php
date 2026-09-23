<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donations;

use App\Actions\Donations\CancelDonation;
use App\Actions\Donations\ConfirmDonation;
use App\Enums\CampaignStatus;
use App\Enums\DonationKind;
use App\Enums\DonationOrigin;
use App\Enums\DonationStatus;
use App\Enums\ManualPaymentMethod;
use App\Enums\ProgramStatus;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Exports\DonationExporter;
use App\Filament\Resources\Cfdis\CfdiResource;
use App\Filament\Resources\Donations\Pages\CreateDonation;
use App\Filament\Resources\Donations\Pages\EditDonation;
use App\Filament\Resources\Donations\Pages\ListDonations;
use App\Filament\Resources\Donations\Pages\ViewDonation;
use App\Filament\Resources\Donors\DonorResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Program;
use App\Models\User;
use App\Support\Money;
use App\Support\Search;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class DonationResource extends Resource
{
    use ReportsActionErrors;

    protected static ?string $model = Donation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $modelLabel = 'donativo';

    protected static ?string $pluralModelLabel = 'donativos';

    protected static string|\UnitEnum|null $navigationGroup = 'Donativos';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        $inKind = fn (Get $get): bool => self::kindOf($get) === DonationKind::InKind;
        $monetary = fn (Get $get): bool => self::kindOf($get) === DonationKind::Monetary;

        return $schema->components([
            Section::make('Donativo')
                ->description('Todo donativo se registra "Por confirmar". Lo confirma el Contador o el Administrador.')
                ->columns(2)
                ->schema([
                    Select::make('donor_id')->label('Donante')->required()->searchable()->columnSpanFull()
                        ->getSearchResultsUsing(fn (string $search): array => Search::unaccent(Donor::query()->whereNull('archived_at'), 'display_name', $search)
                            ->orderBy('display_name')->limit(20)->pluck('display_name', 'id')->all())
                        ->getOptionLabelUsing(fn (mixed $value): ?string => self::donorLabel($value))
                        ->helperText('Escribe parte del nombre o razón social. Los donantes archivados no aparecen.'),
                    Radio::make('kind')->label('Tipo de donativo')->options(DonationKind::class)
                        ->default(DonationKind::Monetary->value)->required()->inline()->live(),
                    Select::make('manual_payment_method')->label('Forma de pago')->options(ManualPaymentMethod::class)
                        ->required($monetary)->visible($monetary)->native(false),
                    TextInput::make('amount')->label(fn (Get $get): string => $inKind($get) ? 'Valor asignado (MXN)' : 'Importe (MXN)')
                        ->required()->prefix('$')->inputMode('decimal')
                        ->helperText('Ejemplo: 1500 o 1500.50. Máximo dos decimales.'),
                    DatePicker::make('received_on')->label('Fecha de recepción')->required()->native(false)
                        ->displayFormat('d/m/Y')->default(now())->maxDate(now()),
                    Textarea::make('in_kind_description')->label('Descripción de lo donado')->rows(3)->maxLength(2000)
                        ->required($inKind)->visible($inKind)->columnSpanFull()
                        ->helperText('Qué se recibió, cantidad y estado. La valuación fiscal de especie está pendiente de confirmar con el contador.'),
                    TextInput::make('reference')->label('Referencia')->maxLength(100)
                        ->helperText('Folio de transferencia, número de cheque o de recibo físico.'),
                    Toggle::make('tax_receipt_requested')->label('Solicitó recibo deducible (CFDI)')
                        ->helperText('Se usará en la fase de CFDI. El donante debe tener datos fiscales.'),
                ]),
            Section::make('Destino')->columns(2)->schema([
                Radio::make('destination')->label('¿A qué se destina?')->required()->live()->dehydrated(false)
                    ->options(['campaign' => 'Campaña', 'program' => 'Programa (sin campaña)', 'general' => 'Fondo general'])
                    ->default('general')->inline()->columnSpanFull()
                    ->afterStateHydrated(function (Radio $component, ?Donation $record): void {
                        if ($record !== null) {
                            $component->state($record->campaign_id !== null ? 'campaign' : ($record->program_id !== null ? 'program' : 'general'));
                        }
                    }),
                Select::make('campaign_id')->label('Campaña')->required()
                    ->options(fn (): array => Campaign::query()->where('status', '!=', CampaignStatus::Archived->value)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->visible(fn (Get $get): bool => $get('destination') === 'campaign')
                    ->helperText('El programa se toma automáticamente de la campaña.'),
                Select::make('program_id')->label('Programa')->required()
                    ->options(fn (): array => Program::query()->where('status', ProgramStatus::Active->value)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->visible(fn (Get $get): bool => $get('destination') === 'program'),
            ]),
            Section::make('Notas')->schema([
                Textarea::make('notes')->label('Notas internas')->rows(3)->maxLength(5000),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Donativo')->columns(3)->schema([
                TextEntry::make('id')->label('Folio interno'),
                TextEntry::make('status')->label('Estado')->badge(),
                TextEntry::make('received_on')->label('Fecha de recepción')->date('d/m/Y'),
                TextEntry::make('donor.display_name')->label('Donante')
                    ->url(fn (Donation $record): string => DonorResource::getUrl('view', ['record' => $record->donor_id])),
                TextEntry::make('kind')->label('Tipo')->badge(),
                TextEntry::make('origin')->label('Origen')->badge(),
                TextEntry::make('manual_payment_method')->label('Forma de pago')
                    ->placeholder(fn (Donation $record): string => $record->isOnline() ? 'Pago en línea' : 'No aplica (especie)'),
                TextEntry::make('payment_id')->label('Pago en línea')->placeholder('—')
                    ->visible(fn (Donation $record): bool => $record->isOnline())
                    ->formatStateUsing(fn (int $state): string => "Ver pago #{$state}")
                    ->url(fn (Donation $record): ?string => $record->payment_id !== null
                        ? PaymentResource::getUrl('view', ['record' => $record->payment_id]) : null),
                TextEntry::make('amount')->label('Importe o valor')->formatStateUsing(fn (string $state): string => Money::format($state).' MXN'),
                TextEntry::make('campaign.name')->label('Campaña')->placeholder('Sin campaña'),
                TextEntry::make('effective_program')->label('Programa')
                    ->state(fn (Donation $record): ?string => $record->effectiveProgram()?->name)->placeholder('Fondo general'),
                TextEntry::make('reference')->label('Referencia')->placeholder('—'),
                TextEntry::make('cfdi')->label('CFDI')->placeholder('Sin CFDI')
                    ->state(fn (Donation $record): ?string => $record->activeCfdi()?->status->getLabel())
                    ->url(fn (Donation $record): ?string => ($cfdi = $record->activeCfdi()) !== null && Gate::allows('view', $cfdi)
                        ? CfdiResource::getUrl('view', ['record' => $cfdi]) : null),
                IconEntry::make('tax_receipt_requested')->label('Solicitó recibo deducible')->boolean(),
                TextEntry::make('in_kind_description')->label('Descripción de lo donado')->columnSpanFull()
                    ->visible(fn (Donation $record): bool => $record->kind === DonationKind::InKind),
                TextEntry::make('notes')->label('Notas internas')->placeholder('Sin notas')->columnSpanFull(),
            ]),
            Section::make('Trazabilidad')->columns(3)->schema([
                TextEntry::make('registeredBy.name')->label('Registrado por')
                    ->placeholder('Automático: pago en línea exitoso'),
                TextEntry::make('created_at')->label('Registrado el')->dateTime('d/m/Y H:i'),
                TextEntry::make('confirmedBy.name')->label('Confirmado por')
                    ->placeholder(fn (Donation $record): string => $record->isOnline() ? 'Automático: confirmado por el proveedor de pago' : '—'),
                TextEntry::make('confirmed_at')->label('Confirmado el')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('cancelledBy.name')->label('Cancelado por')->placeholder('—'),
                TextEntry::make('cancelled_at')->label('Cancelado el')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('cancellation_reason')->label('Motivo de cancelación')->columnSpanFull()
                    ->visible(fn (Donation $record): bool => $record->cancellation_reason !== null),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['donor', 'campaign.program', 'program']))
            ->columns([
                TextColumn::make('id')->label('Folio')->sortable(),
                TextColumn::make('received_on')->label('Fecha')->date('d/m/Y')->sortable(),
                TextColumn::make('donor.display_name')->label('Donante')->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $inner): Builder => $inner
                        ->whereHas('donor', fn (Builder $donor): Builder => Search::unaccent($donor, 'display_name', $search))
                        ->orWhere('reference', 'ilike', '%'.addcslashes(trim($search), '%_\\').'%'))),
                TextColumn::make('kind')->label('Tipo')->badge()->toggleable(),
                TextColumn::make('origin')->label('Origen')->badge()->toggleable(),
                TextColumn::make('manual_payment_method')->label('Forma de pago')->placeholder('—')->toggleable(),
                TextColumn::make('amount')->label('Importe o valor')->alignEnd()->sortable()
                    ->formatStateUsing(fn (string $state): string => Money::format($state)),
                TextColumn::make('destination')->label('Destino')
                    ->state(fn (Donation $record): string => $record->campaign->name ?? $record->program->name ?? 'Fondo general'),
                TextColumn::make('status')->label('Estado')->badge()->sortable(),
                TextColumn::make('reference')->label('Referencia')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('received_on', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(DonationStatus::class),
                SelectFilter::make('kind')->label('Tipo')->options(DonationKind::class),
                SelectFilter::make('origin')->label('Origen')->options(DonationOrigin::class),
                SelectFilter::make('manual_payment_method')->label('Forma de pago')->options(ManualPaymentMethod::class),
                SelectFilter::make('donor_id')->label('Donante')->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Search::unaccent(Donor::query(), 'display_name', $search)
                        ->orderBy('display_name')->limit(20)->pluck('display_name', 'id')->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => self::donorLabel($value)),
                SelectFilter::make('campaign_id')->label('Campaña')->relationship('campaign', 'name')->searchable()->preload(),
                SelectFilter::make('program')->label('Programa (directo o por campaña)')
                    ->options(fn (): array => Program::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where(fn (Builder $inner) => $inner->where('program_id', (int) $data['value'])
                            ->orWhereHas('campaign', fn (Builder $campaign) => $campaign->where('program_id', (int) $data['value'])))
                        : $query),
                Filter::make('received_on')
                    ->schema([
                        DatePicker::make('from')->label('Recibido desde')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('until')->label('Recibido hasta')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('received_on', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->whereDate('received_on', '<=', $date)))
                    ->indicateUsing(fn (array $data): array => array_values(array_filter([
                        isset($data['from']) ? 'Desde '.date('d/m/Y', (int) strtotime($data['from'])) : null,
                        isset($data['until']) ? 'Hasta '.date('d/m/Y', (int) strtotime($data['until'])) : null,
                    ]))),
                Filter::make('amount')
                    ->schema([
                        TextInput::make('min')->label('Importe mínimo')->prefix('$')->inputMode('decimal'),
                        TextInput::make('max')->label('Importe máximo')->prefix('$')->inputMode('decimal'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(self::moneyOrNull($data['min'] ?? null), fn (Builder $q, string $min) => $q->where('amount', '>=', $min))
                        ->when(self::moneyOrNull($data['max'] ?? null), fn (Builder $q, string $max) => $q->where('amount', '<=', $max)))
                    ->indicateUsing(fn (array $data): array => array_values(array_filter([
                        self::moneyOrNull($data['min'] ?? null) !== null ? 'Mínimo '.Money::format(self::moneyOrNull($data['min'])) : null,
                        self::moneyOrNull($data['max'] ?? null) !== null ? 'Máximo '.Money::format(self::moneyOrNull($data['max'])) : null,
                    ]))),
                TernaryFilter::make('tax_receipt_requested')->label('Solicitó recibo deducible'),
            ])
            ->headerActions([
                ExportAction::make()->label('Exportar')->exporter(DonationExporter::class)
                    ->visible(fn (): bool => Gate::allows('export', Donation::class)),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::confirmAction(),
                self::cancelAction(),
            ])
            ->emptyStateHeading('No hay donativos que mostrar')
            ->emptyStateDescription('Registra uno con "Crear donativo" o revisa los filtros aplicados.');
    }

    public static function confirmAction(): Action
    {
        return Action::make('confirm')
            ->label('Confirmar')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Confirmar donativo')
            ->modalDescription('Confirma solo si verificaste que el donativo se recibió. Después ya no podrán cambiarse sus datos; si hubiera un error habría que cancelarlo y registrarlo de nuevo.')
            ->visible(fn (Donation $record): bool => Gate::allows('confirm', $record))
            ->action(function (Donation $record): void {
                /** @var User $actor */
                $actor = auth()->user();
                self::notifyOutcome(fn () => app(ConfirmDonation::class)->handle($record, $actor), 'Donativo confirmado');
            });
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancelar')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading('Cancelar donativo')
            ->modalDescription('El donativo no se borra: queda en el historial como "Cancelado", con el motivo, la fecha y quién lo canceló.')
            ->schema([
                Textarea::make('cancellation_reason')->label('Motivo de cancelación')->required()->minLength(5)->maxLength(1000)
                    ->helperText('Ejemplo: "Importe capturado con error; se registró de nuevo con el folio 128".'),
            ])
            ->modalSubmitActionLabel('Cancelar donativo')
            ->visible(fn (Donation $record): bool => Gate::allows('cancel', $record))
            ->action(function (Donation $record, array $data): void {
                /** @var User $actor */
                $actor = auth()->user();
                self::notifyOutcome(
                    fn () => app(CancelDonation::class)->handle($record, (string) $data['cancellation_reason'], $actor),
                    'Donativo cancelado',
                );
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDonations::route('/'),
            'create' => CreateDonation::route('/create'),
            'view' => ViewDonation::route('/{record}'),
            'edit' => EditDonation::route('/{record}/edit'),
        ];
    }

    private static function kindOf(Get $get): ?DonationKind
    {
        $kind = $get('kind');

        return $kind instanceof DonationKind ? $kind : DonationKind::tryFrom((string) $kind);
    }

    private static function donorLabel(mixed $value): ?string
    {
        $name = is_numeric($value) ? Donor::query()->whereKey((int) $value)->value('display_name') : null;

        return is_string($name) ? $name : null;
    }

    private static function moneyOrNull(mixed $value): ?string
    {
        return is_string($value) && Money::isValid($value) ? Money::normalize($value) : null;
    }
}
