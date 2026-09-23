<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\RelationManagers;

use App\Enums\Permission;
use App\Models\User;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Reembolsos del pago con su motivo, quién los pidió y el resultado
 * (Administrador y Contador).
 */
class RefundsRelationManager extends RelationManager
{
    protected static string $relationship = 'refunds';

    protected static ?string $title = 'Reembolsos';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasPermission(Permission::RequestRefunds);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('requestedBy'))
            ->columns([
                TextColumn::make('requested_at')->label('Solicitado el')->dateTime('d/m/Y H:i'),
                TextColumn::make('amount')->label('Importe')->alignEnd()->formatStateUsing(fn (string $state): string => Money::format($state)),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make('reason')->label('Motivo'),
                TextColumn::make('reason_comment')->label('Comentario')->placeholder('—')->limit(60)->wrap(),
                TextColumn::make('requestedBy.name')->label('Solicitado por')->placeholder('Proveedor'),
                TextColumn::make('processed_at')->label('Resultado el')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('failure_reason')->label('Motivo del fallo')->placeholder('—')->limit(60)->wrap(),
                TextColumn::make('external_id')->label('Id. en el proveedor')->placeholder('Sin respuesta aún')
                    ->visible(fn (): bool => ($user = auth()->user()) instanceof User && $user->hasPermission(Permission::ViewPaymentTechnicalDetails)),
            ])
            ->defaultSort('requested_at', 'desc')
            ->emptyStateHeading('Este pago no tiene reembolsos');
    }
}
