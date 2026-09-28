<?php

declare(strict_types=1);

namespace App\Filament\Resources\BulkMessages\RelationManagers;

use App\Enums\CommunicationStatus;
use App\Filament\Resources\Communications\CommunicationResource;
use App\Models\Communication;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Resultado del envío masivo por destinatario (solo consulta).
 */
class CommunicationsRelationManager extends RelationManager
{
    protected static string $relationship = 'communications';

    protected static ?string $title = 'Destinatarios';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('donor'))
            ->columns([
                TextColumn::make('donor.display_name')->label('Donante'),
                TextColumn::make('recipient')->label('Destinatario')->placeholder('—'),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make('sent_at')->label('Enviado')->dateTime('d/m/Y H:i')->placeholder('—')->sortable(),
                TextColumn::make('skip_reason')->label('Motivo de no envío')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('id')
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(CommunicationStatus::class),
            ])
            ->recordActions([
                Action::make('open')->label('Ver')->icon(Heroicon::OutlinedEye)
                    ->url(fn (Communication $record): string => CommunicationResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading('Aún no hay destinatarios registrados');
    }
}
