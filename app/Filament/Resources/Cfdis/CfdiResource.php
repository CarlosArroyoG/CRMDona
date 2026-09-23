<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cfdis;

use App\Actions\Cfdi\DiscardCfdi;
use App\Actions\Cfdi\RequestCfdiCancellation;
use App\Actions\Cfdi\RequestCfdiSubstitution;
use App\Actions\Cfdi\RetryCfdi;
use App\Enums\CfdiCancellationMotive;
use App\Enums\CfdiStatus;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Cfdis\Pages\ListCfdis;
use App\Filament\Resources\Cfdis\Pages\ViewCfdi;
use App\Filament\Resources\Donations\DonationResource;
use App\Models\Cfdi;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
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
 * CFDI de donativos (Fase 3). Se emiten desde el donativo; aquí se da
 * seguimiento, se reintentan, se descargan y se cancelan.
 */
class CfdiResource extends Resource
{
    use ReportsActionErrors;

    protected static ?string $model = Cfdi::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $modelLabel = 'CFDI';

    protected static ?string $pluralModelLabel = 'CFDI';

    protected static ?string $navigationLabel = 'CFDI';

    protected static string|\UnitEnum|null $navigationGroup = 'Donativos';

    protected static ?int $navigationSort = 3;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('CFDI')->columns(3)->schema([
                TextEntry::make('status')->label('Estado')->badge(),
                TextEntry::make('uuid')->label('Folio fiscal (UUID)')->placeholder('Aún no timbrado')->copyable(),
                TextEntry::make('series_folio')->label('Serie y folio')->placeholder('—')
                    ->state(fn (Cfdi $record): ?string => $record->folio !== null ? "{$record->series}-{$record->folio}" : null),
                TextEntry::make('total')->label('Total')->formatStateUsing(fn (string $state): string => Money::format($state).' MXN'),
                TextEntry::make('donation_id')->label('Donativo')->placeholder('Factura global')
                    ->formatStateUsing(fn (?int $state): ?string => $state !== null ? "Ver donativo #{$state}" : null)
                    ->url(fn (Cfdi $record): ?string => $record->donation_id !== null ? DonationResource::getUrl('view', ['record' => $record->donation_id]) : null),
                TextEntry::make('donation.donor.display_name')->label('Donante')->placeholder('Público en general'),
                TextEntry::make('globalCfdi')->label('Periodo global')->placeholder('Individual')
                    ->state(fn (Cfdi $record): ?string => $record->globalCfdi !== null ? "{$record->globalCfdi->periodicity} · ".Carbon::parse($record->globalCfdi->period_start)->format('d/m/Y').'–'.Carbon::parse($record->globalCfdi->period_end)->format('d/m/Y')." · {$record->globalCfdi->donations()->count()} donativos" : null),
                TextEntry::make('stamped_at')->label('Timbrado el')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('requestedBy.name')->label('Solicitado por')->placeholder('Automático'),
                TextEntry::make('substitutes.uuid')->label('Sustituye a (motivo 01)')->placeholder('—')
                    ->visible(fn (Cfdi $record): bool => $record->substitutes_cfdi_id !== null),
                TextEntry::make('replacement_pending')->label('Sustitución')
                    ->visible(fn (Cfdi $record): bool => $record->replacement_pending)
                    ->state('El CFDI original aún no se cancela; ambos siguen vigentes.'),
            ]),
            Section::make('Detalle técnico')->columns(3)
                ->visible(fn (Cfdi $record): bool => Gate::allows('viewTechnical', $record))
                ->schema([
                    TextEntry::make('provider')->label('PAC'),
                    TextEntry::make('external_id')->label('Identificador en el PAC')->placeholder('—'),
                    TextEntry::make('attempts')->label('Intentos de timbrado'),
                    TextEntry::make('idempotency_key')->label('Llave de idempotencia'),
                    TextEntry::make('last_error_code')->label('Código de error')->placeholder('—'),
                    TextEntry::make('last_error')->label('Último error')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('substitution_reason')->label('Razón de la sustitución')->placeholder('—')->columnSpanFull(),
                ]),
            Section::make('Cancelación')->columns(3)
                ->visible(fn (Cfdi $record): bool => $record->cancellation_requested_at !== null)
                ->schema([
                    TextEntry::make('cancellation_motive')->label('Motivo SAT'),
                    TextEntry::make('cancellationRequestedBy.name')->label('Solicitada por'),
                    TextEntry::make('cancellation_requested_at')->label('Solicitada el')->dateTime('d/m/Y H:i'),
                    TextEntry::make('cancellation_reason')->label('Razón')->columnSpanFull(),
                    TextEntry::make('cancellation_replacement_uuid')->label('UUID que lo sustituye')->placeholder('—'),
                    TextEntry::make('cancellation_provider_status')->label('Respuesta del PAC')->placeholder('—')
                        ->visible(fn (Cfdi $record): bool => Gate::allows('viewTechnical', $record)),
                    TextEntry::make('cancelled_at')->label('Cancelado el')->dateTime('d/m/Y H:i')->placeholder('—'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('donation.donor'))
            ->columns([
                TextColumn::make('id')->label('Folio')->sortable(),
                TextColumn::make('requested_at')->label('Solicitado')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('donation.donor.display_name')->label('Donante'),
                TextColumn::make('total')->label('Total')->alignEnd()->formatStateUsing(fn (string $state): string => Money::format($state)),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make('uuid')->label('UUID')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('requested_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(CfdiStatus::class),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('Sin CFDI')
            ->emptyStateDescription('Los CFDI se emiten desde el detalle de un donativo confirmado.');
    }

    /**
     * @return list<Action>
     */
    public static function recordActions(): array
    {
        return [
            Action::make('downloadXml')->label('XML')->icon(Heroicon::OutlinedArrowDownTray)
                ->url(fn (Cfdi $record): string => route('cfdi.files', ['cfdi' => $record, 'format' => 'xml']))
                ->visible(fn (Cfdi $record): bool => Gate::allows('download', $record)),
            Action::make('downloadPdf')->label('PDF')->icon(Heroicon::OutlinedArrowDownTray)
                ->url(fn (Cfdi $record): string => route('cfdi.files', ['cfdi' => $record, 'format' => 'pdf']))
                ->visible(fn (Cfdi $record): bool => Gate::allows('download', $record) && $record->pdf_path !== null),
            Action::make('retry')->label('Reintentar timbrado')->icon(Heroicon::OutlinedArrowPath)->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Se vuelve a enviar al PAC con la misma solicitud; si ya se había timbrado, no se duplica.')
                ->visible(fn (Cfdi $record): bool => Gate::allows('retry', $record))
                ->action(function (Cfdi $record): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    self::notifyOutcome(fn () => app(RetryCfdi::class)->handle($record, $actor), 'Timbrado enviado a la cola');
                }),
            Action::make('substitute')->label('Sustituir CFDI (motivo 01)')->icon(Heroicon::OutlinedDocumentDuplicate)->color('warning')
                ->modalHeading('Sustituir CFDI')
                ->modalDescription('Corrige primero los datos (donante, datos fiscales u organización). Se timbra un CFDI nuevo relacionado con este (tipo de relación 04) y, al timbrarse, este se cancela con motivo 01. Si el receptor rechaza la cancelación, ambos siguen vigentes.')
                ->schema([Textarea::make('reason')->label('Razón (interna)')->required()->minLength(5)->maxLength(1000)])
                ->modalSubmitActionLabel('Sustituir')
                ->visible(fn (Cfdi $record): bool => Gate::allows('substitute', $record))
                ->action(function (Cfdi $record, array $data): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    self::notifyOutcome(fn () => app(RequestCfdiSubstitution::class)->handle($record, (string) $data['reason'], $actor), 'Sustitución en cola de timbrado');
                }),
            Action::make('discard')->label('Descartar')->icon(Heroicon::OutlinedTrash)->color('gray')
                ->modalHeading('Descartar CFDI rechazado')
                ->modalDescription('Solo para un CFDI que el PAC rechazó y nunca se timbró. Deja de contar como el CFDI del donativo.')
                ->schema([Textarea::make('reason')->label('Razón (interna)')->required()->minLength(5)->maxLength(1000)])
                ->modalSubmitActionLabel('Descartar')
                ->visible(fn (Cfdi $record): bool => Gate::allows('discard', $record))
                ->action(function (Cfdi $record, array $data): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    self::notifyOutcome(fn () => app(DiscardCfdi::class)->handle($record, (string) $data['reason'], $actor), 'CFDI descartado');
                }),
            Action::make('cancel')->label('Cancelar CFDI')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->modalHeading('Cancelar CFDI')
                ->modalDescription('La cancelación se envía al SAT por medio del PAC y puede requerir la aceptación del receptor. No cancela el donativo ni el pago.')
                ->schema([
                    Select::make('motive')->label('Motivo SAT')->required()->native(false)
                        ->options(collect(CfdiCancellationMotive::direct())->mapWithKeys(fn (CfdiCancellationMotive $motive): array => [$motive->value => $motive->getLabel()])->all())
                        ->helperText('02: el CFDI no debió emitirse o el RFC es totalmente erróneo (después se emite el correcto). 03: el donativo no se recibió. Si la operación subsiste y solo hay que corregir datos, usa "Sustituir CFDI" (motivo 01).'),
                    Textarea::make('reason')->label('Razón (interna)')->required()->minLength(5)->maxLength(1000),
                ])
                ->modalSubmitActionLabel('Solicitar cancelación')
                ->visible(fn (Cfdi $record): bool => Gate::allows('cancel', $record))
                ->action(function (Cfdi $record, array $data): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    self::notifyOutcome(
                        fn () => app(RequestCfdiCancellation::class)->handle($record, (string) $data['motive'], (string) $data['reason'], $actor),
                        'Cancelación solicitada',
                    );
                }),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCfdis::route('/'),
            'view' => ViewCfdi::route('/{record}'),
        ];
    }
}
