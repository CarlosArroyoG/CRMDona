<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donors;

use App\Actions\Donors\DeleteDonor;
use App\Actions\Donors\FindDonorDuplicates;
use App\Actions\Donors\SaveDonorTaxProfile;
use App\Actions\Donors\SetDonorArchived;
use App\Enums\CfdiUse;
use App\Enums\DonorType;
use App\Enums\TaxRegime;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Exports\DonorExporter;
use App\Filament\Resources\Donors\Pages\CreateDonor;
use App\Filament\Resources\Donors\Pages\EditDonor;
use App\Filament\Resources\Donors\Pages\ListDonors;
use App\Filament\Resources\Donors\Pages\ViewDonor;
use App\Filament\Resources\Donors\RelationManagers\DonationsRelationManager;
use App\Models\Donor;
use App\Models\OrganizationSetting;
use App\Models\Tag;
use App\Support\Search;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class DonorResource extends Resource
{
    use ReportsActionErrors;

    protected static ?string $model = Donor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $modelLabel = 'donante';

    protected static ?string $pluralModelLabel = 'donantes';

    protected static ?string $recordTitleAttribute = 'display_name';

    protected static string|\UnitEnum|null $navigationGroup = 'Donativos';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        $isIndividual = fn (Get $get): bool => self::typeOf($get) === DonorType::Individual;
        $isOrganization = fn (Get $get): bool => self::typeOf($get) === DonorType::Organization;

        return $schema->components([
            Section::make('Datos generales')->columns(3)->schema([
                Select::make('type')->label('Tipo de persona')->options(DonorType::class)
                    ->default(DonorType::Individual->value)->required()->live()->native(false),
                TextInput::make('first_name')->label('Nombre(s)')->maxLength(100)
                    ->required($isIndividual)->visible($isIndividual),
                TextInput::make('last_name')->label('Apellido paterno')->maxLength(100)
                    ->required($isIndividual)->visible($isIndividual),
                TextInput::make('second_last_name')->label('Apellido materno')->maxLength(100)->visible($isIndividual),
                DatePicker::make('birth_date')->label('Fecha de nacimiento')->native(false)->displayFormat('d/m/Y')
                    ->maxDate(now()->subDay())->visible($isIndividual)
                    ->helperText('Opcional. Se usa para la felicitación de cumpleaños.'),
                TextInput::make('legal_name')->label('Razón social')->maxLength(255)
                    ->required($isOrganization)->visible($isOrganization)->columnSpan(2),
                TextInput::make('contact_name')->label('Persona de contacto')->maxLength(150)->visible($isOrganization),
            ]),
            Section::make('Contacto')->columns(2)->schema([
                TextInput::make('email')->label('Correo electrónico')->email()->maxLength(255)->live(onBlur: true)
                    ->hint(fn (Get $get, ?Donor $record): ?string => self::duplicateHint($get('email'), null, $record)),
                TextInput::make('phone')->label('Teléfono')->tel()->maxLength(30),
                Select::make('tag_ids')->label('Etiquetas')->multiple()
                    ->options(fn (): array => Tag::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->createOptionForm([TextInput::make('name')->label('Nueva etiqueta')->required()->maxLength(50)])
                    ->createOptionUsing(fn (array $data): int => Tag::query()->firstOrCreate(['name' => trim((string) $data['name'])])->id)
                    ->createOptionAction(fn (Action $action): Action => $action->visible(fn (): bool => Gate::allows('create', Tag::class)))
                    ->columnSpanFull(),
                Textarea::make('notes')->label('Notas')->rows(3)->maxLength(5000)->columnSpanFull(),
            ]),
            Section::make('Consentimientos')->columns(2)
                ->description('Son independientes: aceptar el aviso de privacidad no implica aceptar comunicaciones.')
                ->schema([
                    Toggle::make('privacy_notice_accepted')->label('Aceptó el aviso de privacidad')
                        ->disabled(fn (): bool => ! OrganizationSetting::current()->hasPrivacyNotice())
                        ->helperText(fn (): string => OrganizationSetting::current()->hasPrivacyNotice()
                            ? 'Actívalo solo si existe evidencia de la aceptación. Se guardará la versión vigente ('.OrganizationSetting::current()->privacy_notice_version.') y la fecha.'
                            : 'No hay aviso de privacidad configurado: el Administrador debe registrarlo en Organización.'),
                    Toggle::make('accepts_communications')->label('Acepta recibir comunicaciones')
                        ->helperText('Correos informativos y de campañas. Se registra la fecha del cambio.'),
                ]),
            Section::make('Datos fiscales')
                ->description('Solo si el donante pidió recibo deducible de impuestos. Se valida la estructura; las reglas fiscales se confirmarán con el contador antes de emitir CFDI.')
                ->visible(fn (): bool => Gate::allows('viewTaxProfile', Donor::class))
                ->columns(2)
                ->schema([
                    Toggle::make('has_tax_profile')->label('Tiene datos fiscales')->live()->columnSpanFull(),
                    ...self::taxProfileFields('tax_profile.', fn (Get $get): bool => (bool) $get('has_tax_profile')),
                ]),
        ]);
    }

    /**
     * @return list<Component|Field>
     */
    public static function taxProfileFields(string $prefix, ?\Closure $visible = null): array
    {
        $visible ??= fn (): bool => true;

        return [
            TextInput::make($prefix.'rfc')->label('RFC')->maxLength(13)->required($visible)->visible($visible)
                ->extraInputAttributes(['style' => 'text-transform: uppercase'])->live(onBlur: true)
                ->hint(fn (Get $get, ?Donor $record): ?string => self::duplicateHint(null, $get($prefix.'rfc'), $record)),
            TextInput::make($prefix.'tax_name')->label('Nombre o razón social fiscal')->maxLength(255)
                ->helperText('Exactamente como aparece en la constancia de situación fiscal.')->required($visible)->visible($visible),
            Select::make($prefix.'tax_regime')->label('Régimen fiscal')->options(TaxRegime::class)
                ->searchable()->required($visible)->visible($visible),
            TextInput::make($prefix.'tax_postal_code')->label('Código postal fiscal')->length(5)
                ->required($visible)->visible($visible),
            Select::make($prefix.'cfdi_use')->label('Uso de CFDI (opcional)')->options(CfdiUse::class)->searchable()
                ->helperText('Por confirmar con el contador.')->visible($visible),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos generales')->columns(3)->schema([
                TextEntry::make('display_name')->label('Nombre o razón social'),
                TextEntry::make('type')->label('Tipo de persona')->badge(),
                TextEntry::make('archived_at')->label('Estado')
                    ->formatStateUsing(fn (): string => 'Archivado')->badge()->color('gray')->placeholder('Activo'),
                TextEntry::make('contact_name')->label('Persona de contacto')->visible(fn (Donor $record): bool => $record->type === DonorType::Organization)->placeholder('—'),
                TextEntry::make('birth_date')->label('Fecha de nacimiento')->date('d/m/Y')->visible(fn (Donor $record): bool => $record->type === DonorType::Individual)->placeholder('—'),
                TextEntry::make('email')->label('Correo electrónico')->placeholder('—')->copyable(),
                TextEntry::make('phone')->label('Teléfono')->placeholder('—'),
                TextEntry::make('tags.name')->label('Etiquetas')->badge()->placeholder('Sin etiquetas'),
                TextEntry::make('notes')->label('Notas')->placeholder('Sin notas')->columnSpanFull(),
            ]),
            Section::make('Consentimientos')->columns(2)->schema([
                TextEntry::make('privacy_notice_accepted_at')->label('Aviso de privacidad')
                    ->formatStateUsing(fn (Donor $record): string => 'Aceptó la versión '.$record->privacy_notice_version.' el '.$record->privacy_notice_accepted_at?->format('d/m/Y H:i'))
                    ->placeholder('Sin aceptación registrada'),
                IconEntry::make('accepts_communications')->label('Acepta comunicaciones')->boolean(),
            ]),
            Section::make('Datos fiscales')->columns(2)
                ->visible(fn (): bool => Gate::allows('viewTaxProfile', Donor::class))
                ->schema([
                    TextEntry::make('taxProfile.rfc')->label('RFC')->placeholder('Sin datos fiscales'),
                    TextEntry::make('taxProfile.tax_name')->label('Nombre fiscal')->placeholder('—'),
                    TextEntry::make('taxProfile.tax_regime')->label('Régimen fiscal')->placeholder('—'),
                    TextEntry::make('taxProfile.tax_postal_code')->label('CP fiscal')->placeholder('—'),
                    TextEntry::make('taxProfile.cfdi_use')->label('Uso de CFDI')->placeholder('Sin definir'),
                ]),
            Section::make('Registro')->columns(2)->collapsed()->schema([
                TextEntry::make('registeredBy.name')->label('Registrado por')->placeholder('El propio donante (página pública)'),
                TextEntry::make('created_at')->label('Registrado el')->dateTime('d/m/Y H:i'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('tags'))
            ->columns([
                TextColumn::make('display_name')->label('Nombre o razón social')->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $inner): Builder => self::searchDonors($inner, $search))),
                TextColumn::make('type')->label('Tipo')->badge()->sortable(),
                TextColumn::make('email')->label('Correo')->placeholder('—')->toggleable(),
                TextColumn::make('taxProfile.rfc')->label('RFC')->placeholder('—')->toggleable()
                    ->visible(fn (): bool => Gate::allows('viewTaxProfile', Donor::class)),
                TextColumn::make('tags.name')->label('Etiquetas')->badge()->toggleable(),
                TextColumn::make('donations_count')->label('Donativos')->counts('donations')->sortable(),
                TextColumn::make('archived_at')->label('Archivado')->date('d/m/Y')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('display_name')
            ->filters([
                SelectFilter::make('type')->label('Tipo de persona')->options(DonorType::class),
                SelectFilter::make('tags')->label('Etiquetas')->relationship('tags', 'name')->multiple()->preload(),
                TernaryFilter::make('archived')->label('Archivados')
                    ->placeholder('Solo activos')->trueLabel('Solo archivados')->falseLabel('Todos')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('archived_at'),
                        false: fn (Builder $query): Builder => $query,
                        blank: fn (Builder $query): Builder => $query->whereNull('archived_at'),
                    ),
                TernaryFilter::make('tax_profile')->label('Datos fiscales')
                    ->visible(fn (): bool => Gate::allows('viewTaxProfile', Donor::class))
                    ->trueLabel('Con datos fiscales')->falseLabel('Sin datos fiscales')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('taxProfile'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('taxProfile'),
                    ),
            ])
            ->headerActions([
                ExportAction::make()->label('Exportar')->exporter(DonorExporter::class)
                    ->visible(fn (): bool => Gate::allows('export', Donor::class)),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading('No hay donantes que mostrar')
            ->emptyStateDescription('Registra uno con "Crear donante" o revisa los filtros: por defecto solo se muestran los activos.');
    }

    /**
     * Busca por nombre o razón social (sin acentos), correo y, con permiso, RFC.
     *
     * @param  Builder<Donor>  $query
     * @return Builder<Donor>
     */
    public static function searchDonors(Builder $query, string $search): Builder
    {
        Search::unaccent($query, 'display_name', $search);
        Search::unaccent($query, 'email', $search, 'or');

        if (Gate::allows('viewTaxProfile', Donor::class)) {
            $query->orWhereHas('taxProfile', fn (Builder $profile): Builder => $profile->where('rfc', 'ilike', '%'.addcslashes(trim($search), '%_\\').'%'));
        }

        return $query;
    }

    public static function archiveAction(): Action
    {
        return Action::make('archive')
            ->label(fn (Donor $record): string => $record->isArchived() ? 'Reactivar' : 'Archivar')
            ->icon(fn (Donor $record): Heroicon => $record->isArchived() ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedArchiveBox)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(fn (Donor $record): string => $record->isArchived()
                ? 'El donante volverá a aparecer en los listados y se le podrán registrar donativos.'
                : 'El donante dejará de aparecer en los listados habituales y no se le podrán registrar donativos. No se borra nada.')
            ->visible(fn (Donor $record): bool => Gate::allows('archive', $record))
            ->action(fn (Donor $record): bool => self::notifyOutcome(
                fn () => app(SetDonorArchived::class)->handle($record, ! $record->isArchived()),
                $record->isArchived() ? 'Donante reactivado' : 'Donante archivado',
            ));
    }

    public static function taxProfileAction(): Action
    {
        return Action::make('taxProfile')
            ->label('Datos fiscales')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('gray')
            ->visible(fn (Donor $record): bool => Gate::allows('updateTaxProfile', $record) && ! Gate::allows('update', $record))
            ->fillForm(fn (Donor $record): array => [
                'has_tax_profile' => $record->taxProfile !== null,
                ...($record->taxProfile?->formData() ?? []),
            ])
            ->schema([
                Toggle::make('has_tax_profile')->label('Tiene datos fiscales')->live(),
                ...self::taxProfileFields('', fn (Get $get): bool => (bool) $get('has_tax_profile')),
            ])
            ->action(fn (Donor $record, array $data): mixed => self::withFormErrors(
                fn () => app(SaveDonorTaxProfile::class)->handle($record, (bool) $data['has_tax_profile'] ? $data : null),
                'mountedActions.0.data.',
            ))
            ->successNotificationTitle('Datos fiscales guardados');
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription('Solo se puede eliminar un donante sin donativos. Se borran también sus datos fiscales y etiquetas. Esta acción no se puede deshacer.')
            ->using(fn (Donor $record): bool => self::notifyOutcome(
                fn () => app(DeleteDonor::class)->handle($record),
                'Donante eliminado',
            ))
            ->successNotification(null);
    }

    public static function getRelations(): array
    {
        return [
            DonationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDonors::route('/'),
            'create' => CreateDonor::route('/create'),
            'view' => ViewDonor::route('/{record}'),
            'edit' => EditDonor::route('/{record}/edit'),
        ];
    }

    private static function typeOf(Get $get): ?DonorType
    {
        $type = $get('type');

        return $type instanceof DonorType ? $type : DonorType::tryFrom((string) $type);
    }

    private static function duplicateHint(mixed $email, mixed $rfc, ?Donor $record): ?string
    {
        $duplicates = app(FindDonorDuplicates::class)->handle(
            is_string($email) ? $email : null,
            is_string($rfc) ? $rfc : null,
            $record?->id,
        );

        if ($duplicates->isEmpty()) {
            return null;
        }

        return 'Aviso: ya existe '.$duplicates->pluck('display_name')->implode(', ').'. Puedes continuar si es otra persona.';
    }
}
