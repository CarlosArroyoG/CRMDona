<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donations\RelationManagers;

use App\Actions\ExternalCfdi\AttachExternalCfdi;
use App\Actions\ExternalCfdi\RemoveExternalCfdi;
use App\Enums\DonationStatus;
use App\Enums\Permission;
use App\Filament\Concerns\ReportsActionErrors;
use App\Models\Donation;
use App\Models\ExternalCfdi;
use App\Models\User;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * "CFDI externo / antecedentes fiscales" del donativo: el CFDI que
 * Contabilidad emitió fuera del CRM, adjunto como evidencia. Aquí no se
 * emite, timbra, cancela ni sustituye nada, y el CRM no certifica la validez
 * fiscal del documento. Consultar: Administrador, Coordinador y Contador;
 * adjuntar, reemplazar y retirar: Administrador y Contador.
 */
class ExternalCfdisRelationManager extends RelationManager
{
    use ReportsActionErrors;

    protected static string $relationship = 'externalCfdis';

    protected static ?string $title = 'CFDI externo / antecedentes fiscales';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::actor()?->hasPermission(Permission::ViewCfdis) ?? false;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['uploadedBy', 'removedBy']))
            ->description('El CFDI lo emite Contabilidad fuera del CRM. Aquí solo se guarda como antecedente; el CRM no valida su vigencia ante el SAT.')
            ->columns([
                TextColumn::make('uuid')->label('UUID')->formatStateUsing(fn (string $state): string => strtoupper($state))->copyable(),
                TextColumn::make('issued_at')->label('Fecha de emisión')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('total')->label('Total del XML')->alignEnd()->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => $state !== null ? Money::format($state) : '—'),
                TextColumn::make('source')->label('Origen')
                    ->formatStateUsing(fn (string $state): string => $state === ExternalCfdi::SOURCE_UPLOAD ? 'Adjuntado' : 'Registro anterior del CRM'),
                TextColumn::make('uploadedBy.name')->label('Adjuntado por')->placeholder('—'),
                TextColumn::make('uploaded_at')->label('Adjuntado el')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('state')->label('Estado')->badge()
                    ->state(fn (ExternalCfdi $record): string => $record->isActive() ? 'Vigente en el CRM' : 'Retirado')
                    ->color(fn (ExternalCfdi $record): string => $record->isActive() ? 'success' : 'gray'),
                TextColumn::make('removal_reason')->label('Motivo del retiro')->placeholder('—')->limit(60)->wrap(),
                TextColumn::make('notes')->label('Notas internas')->placeholder('—')->limit(60)->wrap(),
            ])
            ->headerActions([$this->attachAction()])
            ->recordActions([
                Action::make('downloadXml')->label('XML')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->visible(fn (ExternalCfdi $record): bool => Gate::allows('download', $record))
                    ->url(fn (ExternalCfdi $record): string => route('external-cfdi.files', [$record, 'xml'])),
                Action::make('downloadPdf')->label('PDF')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->visible(fn (ExternalCfdi $record): bool => $record->pdf_path !== null && Gate::allows('download', $record))
                    ->url(fn (ExternalCfdi $record): string => route('external-cfdi.files', [$record, 'pdf'])),
                $this->replaceAction(),
                $this->removeAction(),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Sin CFDI externo adjunto')
            ->emptyStateDescription('Cuando Contabilidad emita el CFDI fuera del CRM, puede adjuntar aquí su XML (y el PDF, si lo tiene).');
    }

    private function attachAction(): Action
    {
        return Action::make('attach')
            ->label('Adjuntar CFDI externo')
            ->icon(Heroicon::OutlinedPaperClip)
            ->modalDescription('Adjunta el XML del CFDI que Contabilidad emitió fuera del CRM. El UUID y las fechas se leen del XML. Esto no emite ni timbra nada.')
            ->visible(function (): bool {
                /** @var Donation $donation */
                $donation = $this->getOwnerRecord();

                return (self::actor()?->hasPermission(Permission::ManageExternalCfdis) ?? false) && $donation->status === DonationStatus::Confirmed;
            })
            ->schema($this->fileFields())
            ->action(function (array $data): void {
                /** @var Donation $donation */
                $donation = $this->getOwnerRecord();
                $this->attach($donation, $data, null);
            });
    }

    private function replaceAction(): Action
    {
        return Action::make('replace')
            ->label('Reemplazar')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->modalDescription('El CFDI actual queda como retirado (no se borra) y se adjunta el nuevo. Esto no cancela nada ante el SAT.')
            ->visible(fn (ExternalCfdi $record): bool => Gate::allows('manage', $record))
            ->schema($this->fileFields())
            ->action(function (ExternalCfdi $record, array $data): void {
                /** @var Donation $donation */
                $donation = $this->getOwnerRecord();
                $this->attach($donation, $data, $record);
            });
    }

    private function removeAction(): Action
    {
        return Action::make('remove')
            ->label('Retirar')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalDescription('El CFDI deja de estar vigente en el CRM, pero el registro y sus archivos se conservan con el motivo. Esto no cancela nada ante el SAT.')
            ->visible(fn (ExternalCfdi $record): bool => Gate::allows('manage', $record))
            ->schema([
                Textarea::make('reason')->label('Motivo')->required()->minLength(5)->maxLength(1000)
                    ->helperText('Ejemplo: "Se adjuntó el XML de otro donativo".'),
            ])
            ->action(function (ExternalCfdi $record, array $data): void {
                $actor = self::actor() ?? abort(403);
                self::notifyOutcome(fn () => app(RemoveExternalCfdi::class)->handle($record, (string) $data['reason'], $actor), 'CFDI externo retirado');
            });
    }

    /**
     * @return list<FileUpload|Textarea>
     */
    private function fileFields(): array
    {
        return [
            FileUpload::make('xml')->label('XML del CFDI')->required()->storeFiles(false)
                ->acceptedFileTypes(['application/xml', 'text/xml', 'text/plain'])->maxSize(2048)
                ->helperText('Obligatorio. Máximo 2 MB.'),
            FileUpload::make('pdf')->label('PDF del CFDI (opcional)')->storeFiles(false)
                ->acceptedFileTypes(['application/pdf'])->maxSize(10240)
                ->helperText('Máximo 10 MB.'),
            Textarea::make('notes')->label('Notas internas (opcional)')->rows(2)->maxLength(1000),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function attach(Donation $donation, array $data, ?ExternalCfdi $replaces): void
    {
        $actor = self::actor() ?? abort(403);

        self::notifyOutcome(function () use ($donation, $data, $replaces, $actor): ExternalCfdi {
            $xml = self::contents($data['xml'] ?? null) ?? throw ValidationException::withMessages(['xml' => 'Adjunta el XML del CFDI.']);

            return app(AttachExternalCfdi::class)->handle(
                $donation,
                $xml,
                self::contents($data['pdf'] ?? null),
                is_string($data['notes'] ?? null) ? $data['notes'] : null,
                $actor,
                $replaces,
            );
        }, $replaces !== null ? 'CFDI externo reemplazado' : 'CFDI externo adjuntado');
    }

    /**
     * Contenido del archivo subido; el nombre original nunca se usa.
     */
    private static function contents(mixed $state): ?string
    {
        if (is_array($state)) {
            $state = array_values($state)[0] ?? null;
        }

        if (! $state instanceof TemporaryUploadedFile) {
            return null;
        }

        $contents = $state->get();

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    private static function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
