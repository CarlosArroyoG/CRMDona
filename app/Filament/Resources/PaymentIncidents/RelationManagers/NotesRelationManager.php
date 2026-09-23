<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentIncidents\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Notas de seguimiento (solo inserción). Se agregan con la acción
 * "Agregar nota" de la incidencia.
 */
class NotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';

    protected static ?string $title = 'Notas de seguimiento';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i'),
                TextColumn::make('user.name')->label('Autor'),
                TextColumn::make('body')->label('Nota')->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Sin notas');
    }
}
