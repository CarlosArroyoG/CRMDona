<?php

declare(strict_types=1);

namespace App\Filament\Resources\MessageTemplates;

use App\Filament\Resources\MessageTemplates\Pages\EditMessageTemplate;
use App\Filament\Resources\MessageTemplates\Pages\ListMessageTemplates;
use App\Models\MessageTemplate;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Plantillas de correo: una por tipo, texto simple con variables. Sin
 * constructor visual: el diseño del correo es fijo.
 */
class MessageTemplateResource extends Resource
{
    protected static ?string $model = MessageTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $modelLabel = 'plantilla';

    protected static ?string $pluralModelLabel = 'Plantillas';

    protected static ?string $navigationLabel = 'Plantillas';

    protected static string|\UnitEnum|null $navigationGroup = 'Comunicaciones';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (?MessageTemplate $record): string => $record?->kind->getLabel() ?? 'Plantilla')
                ->description('Texto simple. Separa párrafos con una línea en blanco. Los avisos obligatorios (por ejemplo, que el recibo no es comprobante fiscal) y la firma los agrega el sistema.')
                ->schema([
                    TextInput::make('subject')->label('Asunto')->required()->maxLength(200),
                    Textarea::make('body')->label('Texto')->required()->rows(12)->maxLength(5000)
                        ->helperText(fn (?MessageTemplate $record): string => 'Variables disponibles: '.collect($record?->kind->variables() ?? [])
                            ->map(fn (string $description, string $name): string => "{{ {$name} }} ({$description})")->implode('; ').'.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')->label('Correo'),
                TextColumn::make('subject')->label('Asunto'),
                TextColumn::make('updatedBy.name')->label('Editada por')->placeholder('Texto original'),
                TextColumn::make('updated_at')->label('Actualizada')->dateTime('d/m/Y H:i'),
            ])
            ->recordActions([EditAction::make()])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMessageTemplates::route('/'),
            'edit' => EditMessageTemplate::route('/{record}/edit'),
        ];
    }
}
