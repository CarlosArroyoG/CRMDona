<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donors\RelationManagers;

use App\Enums\Permission;
use App\Filament\Concerns\ResolvesActor;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Historial de responsables (procuradores) del donante. Solo consulta: la
 * asignación se cambia con "Reasignar responsable" en la ficha.
 */
class AssignmentsRelationManager extends RelationManager
{
    use ResolvesActor;

    protected static string $relationship = 'assignmentHistory';

    protected static ?string $title = 'Historial de responsables';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::actorCan(Permission::ViewDonorActivities);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'assignedBy']))
            ->columns([
                TextColumn::make('user.name')->label('Responsable'),
                TextColumn::make('started_at')->label('Desde')->dateTime('d/m/Y H:i'),
                TextColumn::make('ended_at')->label('Hasta')->dateTime('d/m/Y H:i')->placeholder('Vigente'),
                TextColumn::make('assignedBy.name')->label('Asignado por'),
                TextColumn::make('note')->label('Nota')->placeholder('—')->limit(60)->wrap(),
            ])
            ->defaultSort('started_at', 'desc')
            ->emptyStateHeading('Este donante no tiene responsable asignado todavía');
    }
}
