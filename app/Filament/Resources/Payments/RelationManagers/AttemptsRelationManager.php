<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\RelationManagers;

use App\Enums\Permission;
use App\Filament\Concerns\ResolvesActor;
use App\Models\PaymentAttempt;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Historial de intentos del pago (información técnica: Administrador y
 * Contador). Nunca muestra más que marca y últimos 4 dígitos de la tarjeta.
 */
class AttemptsRelationManager extends RelationManager
{
    use ResolvesActor;

    protected static string $relationship = 'attempts';

    protected static ?string $title = 'Intentos de cobro';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::actorCan(Permission::ViewPaymentTechnicalDetails);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('attempt_number')->label('#'),
                TextColumn::make('provider_created_at')->label('Fecha')->dateTime('d/m/Y H:i:s')->placeholder('—'),
                TextColumn::make('status')->label('Resultado')->badge(),
                TextColumn::make('initiated_by')->label('Iniciado por'),
                TextColumn::make('failure_category')->label('Motivo')->placeholder('—'),
                TextColumn::make('provider_code')->label('Código del proveedor')->placeholder('—'),
                TextColumn::make('card')->label('Tarjeta')
                    ->state(fn (PaymentAttempt $record): string => $record->card_last4 !== null
                        ? trim(($record->card_brand ?? '').' •••• '.$record->card_last4)
                        : '—'),
                TextColumn::make('external_id')->label('Id. en el proveedor')->placeholder('—')->copyable(),
            ])
            ->defaultSort('attempt_number')
            ->emptyStateHeading('Sin intentos registrados');
    }
}
