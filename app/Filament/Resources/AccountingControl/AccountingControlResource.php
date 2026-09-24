<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccountingControl;

use App\Actions\Accounting\RetryAccountingNotice;
use App\Actions\Accounting\SetAccountingProcessed;
use App\Enums\AccountingNoticeStatus;
use App\Enums\Permission;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Concerns\ResolvesActor;
use App\Filament\Exports\AccountingControlExporter;
use App\Filament\Resources\AccountingControl\Pages\ListAccountingControl;
use App\Filament\Resources\Donations\DonationResource;
use App\Models\Donation;
use App\Models\Donor;
use App\Reports\AccountingControl;
use App\Support\Money;
use App\Support\Search;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Control contable y documental: la cola de trabajo de Contabilidad. Una fila
 * por donativo confirmado: recibo simple, CFDI solicitado, aviso a
 * Contabilidad, CFDI externo adjunto y procesamiento contable. Sin datos
 * fiscales del donante. Consultan Administrador, Coordinador y Contador
 * (cfdi.view); marcan y reenvían Administrador y Contador
 * (accounting.process). El CRM no emite CFDI ni decide la factura global.
 */
class AccountingControlResource extends Resource
{
    use ReportsActionErrors;
    use ResolvesActor;

    protected static ?string $model = Donation::class;

    protected static ?string $slug = 'control-contable';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $modelLabel = 'donativo';

    protected static ?string $pluralModelLabel = 'Control contable';

    protected static ?string $navigationLabel = 'Control contable';

    protected static string|\UnitEnum|null $navigationGroup = 'Reportes';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return self::actorCan(Permission::ViewCfdis);
    }

    public static function canView(Model $record): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(AccountingControl::query())
            ->description('El CRM no emite CFDI ni decide qué donativos van a factura global: Contabilidad lo hace fuera del sistema y aquí marca lo procesado.')
            ->columns([
                TextColumn::make('receipt.folio')->label('Recibo')->placeholder('Sin recibo'),
                TextColumn::make('id')->label('Donativo')->prefix('#')->sortable(),
                TextColumn::make('received_on')->label('Fecha')->date('d/m/Y')->sortable(),
                TextColumn::make('donor.display_name')->label('Donante')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('donor', fn (Builder $donor): Builder => Search::unaccent($donor, 'display_name', $search))),
                TextColumn::make('amount')->label('Importe')->alignEnd()->sortable()->formatStateUsing(fn (string $state): string => Money::format($state)),
                TextColumn::make('destination')->label('Destino')->toggleable()
                    ->state(fn (Donation $record): string => $record->campaign->name ?? $record->program->name ?? 'Fondo general'),
                TextColumn::make('tax_receipt_requested')->label('CFDI solicitado')->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Sí' : 'No')
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),
                TextColumn::make('accountingNotice.status')->label('Aviso a Contabilidad')->badge()->placeholder('Sin aviso'),
                TextColumn::make('processing')->label('Procesamiento contable')->badge()
                    ->state(fn (Donation $record): string => AccountingControl::isProcessed($record) ? 'Procesado' : 'Pendiente')
                    ->color(fn (Donation $record): string => AccountingControl::isProcessed($record) ? 'success' : 'warning'),
                TextColumn::make('external_cfdi')->label('CFDI externo')->badge()
                    ->state(fn (Donation $record): string => AccountingControl::externalCfdi($record) !== null ? 'Adjunto' : 'No')
                    ->color(fn (Donation $record): string => AccountingControl::externalCfdi($record) !== null ? 'success' : 'gray'),
                TextColumn::make('external_cfdi_uuid')->label('UUID')->placeholder('—')->toggleable()
                    ->state(fn (Donation $record): ?string => ($uuid = AccountingControl::externalCfdi($record)?->uuid) !== null ? strtoupper($uuid) : null),
                TextColumn::make('external_cfdi_issued_at')->label('Emisión del CFDI')->placeholder('—')->toggleable()
                    ->state(fn (Donation $record): ?string => AccountingControl::externalCfdi($record)?->issued_at->format('d/m/Y H:i')),
                TextColumn::make('external_cfdi_uploaded_at')->label('Adjuntado el')->placeholder('—')->toggleable()
                    ->state(fn (Donation $record): ?string => AccountingControl::externalCfdi($record)?->uploaded_at->timezone(config()->string('app.timezone'))->format('d/m/Y H:i')),
            ])
            ->defaultSort('received_on', 'desc')
            ->filters([
                SelectFilter::make('processing')->label('Procesamiento contable')
                    ->options([AccountingControl::PENDING => 'Pendiente', AccountingControl::PROCESSED => 'Procesado'])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? AccountingControl::whereProcessing($query, (string) $data['value'])
                        : $query),
                TernaryFilter::make('tax_receipt_requested')->label('CFDI solicitado')
                    ->trueLabel('Solicitado')->falseLabel('No solicitado'),
                TernaryFilter::make('external_cfdi')->label('CFDI externo adjunto')
                    ->queries(
                        true: fn (Builder $query): Builder => AccountingControl::whereExternalCfdi($query, true),
                        false: fn (Builder $query): Builder => AccountingControl::whereExternalCfdi($query, false),
                    ),
                SelectFilter::make('notice_status')->label('Aviso a Contabilidad')->options(AccountingNoticeStatus::class)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('accountingNotice', fn (Builder $notice) => $notice->where('status', (string) $data['value']))
                        : $query),
                Filter::make('received_on')
                    ->schema([
                        DatePicker::make('from')->label('Desde')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('until')->label('Hasta')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('received_on', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->whereDate('received_on', '<=', $date))),
                SelectFilter::make('donor_id')->label('Donante')->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Search::unaccent(Donor::query(), 'display_name', $search)
                        ->orderBy('display_name')->limit(20)->pluck('display_name', 'id')->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => is_numeric($value) ? Donor::query()->whereKey((int) $value)->value('display_name') : null),
            ])
            ->headerActions([
                ExportAction::make()->label('Exportar')->exporter(AccountingControlExporter::class)
                    ->visible(fn (): bool => self::canViewAny()),
            ])
            ->recordActions([
                self::markProcessedAction(),
                self::reopenAction(),
                self::retryNoticeAction(),
            ])
            ->toolbarActions([self::bulkMarkProcessedAction()])
            ->recordUrl(fn (Donation $record): string => DonationResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('Sin donativos que mostrar');
    }

    public static function getPages(): array
    {
        return ['index' => ListAccountingControl::route('/')];
    }

    private static function markProcessedAction(): Action
    {
        return Action::make('markProcessed')
            ->label('Marcar procesado')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalDescription('Indica que Contabilidad ya dio a este donativo el tratamiento fiscal que decidió fuera del CRM.')
            ->visible(fn (Donation $record): bool => self::canProcess() && ! AccountingControl::isProcessed($record))
            ->schema([Textarea::make('note')->label('Nota (opcional)')->rows(2)->maxLength(1000)])
            ->action(function (Donation $record, array $data): void {
                $actor = self::actor() ?? abort(403);
                self::notifyOutcome(fn () => app(SetAccountingProcessed::class)->handle($record, true, self::note($data), $actor), 'Donativo marcado como procesado');
            });
    }

    private static function reopenAction(): Action
    {
        return Action::make('reopen')
            ->label('Reabrir')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Donation $record): bool => self::canProcess() && AccountingControl::isProcessed($record))
            ->schema([Textarea::make('note')->label('Motivo')->required()->minLength(5)->maxLength(1000)])
            ->action(function (Donation $record, array $data): void {
                $actor = self::actor() ?? abort(403);
                self::notifyOutcome(fn () => app(SetAccountingProcessed::class)->handle($record, false, self::note($data), $actor), 'Donativo pendiente de nuevo');
            });
    }

    private static function retryNoticeAction(): Action
    {
        return Action::make('retryNotice')
            ->label('Reenviar aviso')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Envía el aviso de este donativo a los usuarios de Contabilidad configurados. Quien ya lo recibió no lo recibe dos veces.')
            ->visible(fn (Donation $record): bool => self::canProcess() && ($record->accountingNotice === null || $record->accountingNotice->status->canRetry()))
            ->action(function (Donation $record): void {
                $actor = self::actor() ?? abort(403);
                self::notifyOutcome(fn () => app(RetryAccountingNotice::class)->handle($record, $actor), 'Aviso en cola');
            });
    }

    private static function bulkMarkProcessedAction(): BulkAction
    {
        return BulkAction::make('bulkMarkProcessed')
            ->label('Marcar procesados')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->visible(fn (): bool => self::canProcess())
            ->requiresConfirmation()
            ->modalDescription('Marca como procesados los donativos seleccionados que sigan pendientes.')
            ->action(function (Collection $records): void {
                $actor = self::actor() ?? abort(403);
                $done = 0;
                foreach ($records as $record) {
                    if ($record instanceof Donation && ! AccountingControl::isProcessed($record)) {
                        try {
                            app(SetAccountingProcessed::class)->handle($record, true, null, $actor);
                            $done++;
                        } catch (ValidationException) {
                            continue;
                        }
                    }
                }
                Notification::make()->success()->title("Donativos marcados como procesados: {$done}")->send();
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function note(array $data): ?string
    {
        return is_string($data['note'] ?? null) ? $data['note'] : null;
    }

    private static function canProcess(): bool
    {
        return self::actorCan(Permission::ProcessAccounting);
    }
}
