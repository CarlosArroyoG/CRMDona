<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cfdis;

use App\Actions\Cfdi\RequestCfdiCancellation;
use App\Actions\Cfdi\RetryCfdi;
use App\Enums\CfdiStatus;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Cfdis\Pages\ListCfdis;
use App\Filament\Resources\Cfdis\Pages\ViewCfdi;
use App\Filament\Resources\Donations\DonationResource;
use App\Models\Cfdi;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
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
                TextEntry::make('donation_id')->label('Donativo')->formatStateUsing(fn (int $state): string => "Ver donativo #{$state}")
                    ->url(fn (Cfdi $record): string => DonationResource::getUrl('view', ['record' => $record->donation_id])),
                TextEntry::make('donation.donor.display_name')->label('Donante'),
                TextEntry::make('stamped_at')->label('Timbrado el')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('requestedBy.name')->label('Solicitado por')->placeholder('Automático'),
                TextEntry::make('provider')->label('PAC'),
                TextEntry::make('last_error')->label('Último error')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Cancelación')->columns(3)
                ->visible(fn (Cfdi $record): bool => $record->cancellation_requested_at !== null)
                ->schema([
                    TextEntry::make('cancellation_motive')->label('Motivo SAT'),
                    TextEntry::make('cancellationRequestedBy.name')->label('Solicitada por'),
                    TextEntry::make('cancellation_requested_at')->label('Solicitada el')->dateTime('d/m/Y H:i'),
                    TextEntry::make('cancellation_reason')->label('Razón')->columnSpanFull(),
                    TextEntry::make('cancellation_provider_status')->label('Respuesta del PAC')->placeholder('—'),
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
            Action::make('cancel')->label('Cancelar CFDI')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->modalHeading('Cancelar CFDI')
                ->modalDescription('La cancelación se envía al SAT por medio del PAC y puede requerir la aceptación del receptor. No cancela el donativo ni el pago.')
                ->schema([
                    Select::make('motive')->label('Motivo SAT')->required()->native(false)
                        ->options(['02' => '02 — Comprobante emitido con errores sin relación', '03' => '03 — No se llevó a cabo la operación']),
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
