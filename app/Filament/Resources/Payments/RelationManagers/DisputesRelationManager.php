<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\RelationManagers;

use App\Enums\Permission;
use App\Filament\Concerns\ResolvesActor;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Disputas y contracargos del pago (Administrador y Contador). No cambian el
 * pago, el donativo, el recibo ni el CFDI.
 */
class DisputesRelationManager extends RelationManager
{
    use ResolvesActor;

    protected static string $relationship = 'disputes';

    protected static ?string $title = 'Disputas y contracargos';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::actorCan(Permission::ViewDisputes);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('opened_at')->label('Abierta el')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make('amount')->label('Importe')->alignEnd()->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => Money::format($state)),
                TextColumn::make('provider_reason')->label('Motivo del proveedor')->placeholder('—'),
                TextColumn::make('evidence_due_at')->label('Responder antes de')->dateTime('d/m/Y')->placeholder('—'),
                TextColumn::make('provider_status')->label('Estado en el proveedor')->placeholder('—'),
                TextColumn::make('external_id')->label('Id. en el proveedor')->copyable(),
            ])
            ->defaultSort('opened_at', 'desc')
            ->emptyStateHeading('Sin disputas');
    }
}
