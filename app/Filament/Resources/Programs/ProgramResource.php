<?php

declare(strict_types=1);

namespace App\Filament\Resources\Programs;

use App\Actions\Programs\DeleteProgram;
use App\Enums\ProgramStatus;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Exports\ProgramExporter;
use App\Filament\Resources\Programs\Pages\CreateProgram;
use App\Filament\Resources\Programs\Pages\EditProgram;
use App\Filament\Resources\Programs\Pages\ListPrograms;
use App\Filament\Resources\Programs\Pages\ViewProgram;
use App\Models\Program;
use App\Support\Search;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

class ProgramResource extends Resource
{
    use ReportsActionErrors;

    protected static ?string $model = Program::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $modelLabel = 'programa';

    protected static ?string $pluralModelLabel = 'programas';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'Destinos';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Programa')
                ->description('Destino permanente de los donativos, por ejemplo "Becas" o "Alimentación".')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label('Nombre')->required()->maxLength(150),
                    Select::make('status')->label('Estado')->options(ProgramStatus::class)
                        ->default(ProgramStatus::Active->value)->required()->native(false),
                    TextInput::make('slug')->label('Identificador para enlaces')->maxLength(160)
                        ->helperText('Opcional. Si lo dejas vacío se genera con el nombre (ejemplo: becas).'),
                    Textarea::make('description')->label('Descripción')->rows(3)->columnSpanFull()->maxLength(5000),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Programa')->columns(2)->schema([
                TextEntry::make('name')->label('Nombre'),
                TextEntry::make('status')->label('Estado')->badge(),
                TextEntry::make('slug')->label('Identificador'),
                TextEntry::make('campaigns_count')->label('Campañas')->state(fn (Program $record): int => $record->campaigns()->count()),
                TextEntry::make('description')->label('Descripción')->placeholder('Sin descripción')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nombre')->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => Search::unaccent($query, 'name', $search)),
                TextColumn::make('status')->label('Estado')->badge()->sortable(),
                TextColumn::make('campaigns_count')->label('Campañas')->counts('campaigns')->sortable(),
                TextColumn::make('updated_at')->label('Última modificación')->dateTime('d/m/Y H:i')->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(ProgramStatus::class),
            ])
            ->headerActions([
                ExportAction::make()->label('Exportar')->exporter(ProgramExporter::class)
                    ->visible(fn (): bool => Gate::allows('export', Program::class)),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::deleteAction(),
            ])
            ->emptyStateHeading('Aún no hay programas')
            ->emptyStateDescription('Crea el primero con el botón "Crear programa".');
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription('Solo se puede eliminar un programa sin campañas ni donativos. Esta acción no se puede deshacer.')
            ->using(fn (Program $record): bool => self::notifyOutcome(
                fn () => app(DeleteProgram::class)->handle($record),
                'Programa eliminado',
            ))
            ->successNotification(null);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrograms::route('/'),
            'create' => CreateProgram::route('/create'),
            'view' => ViewProgram::route('/{record}'),
            'edit' => EditProgram::route('/{record}/edit'),
        ];
    }
}
