<?php

declare(strict_types=1);

namespace App\Filament\Resources\Communications;

use App\Actions\Communications\ResendCommunication;
use App\Enums\CommunicationKind;
use App\Enums\CommunicationStatus;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Communications\Pages\ListCommunications;
use App\Filament\Resources\Communications\Pages\ViewCommunication;
use App\Filament\Resources\Donations\DonationResource;
use App\Models\Communication;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
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

/**
 * Registro de correos a donantes (Fase 4): qué se envió, cuándo y con qué
 * resultado. No guarda el cuerpo ni el correo completo del destinatario.
 */
class CommunicationResource extends Resource
{
    use ReportsActionErrors;

    protected static ?string $model = Communication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $modelLabel = 'envío';

    protected static ?string $pluralModelLabel = 'Historial de envíos';

    protected static ?string $navigationLabel = 'Historial de envíos';

    protected static string|\UnitEnum|null $navigationGroup = 'Comunicaciones';

    protected static ?int $navigationSort = 1;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Envío')->columns(3)->schema([
                TextEntry::make('kind')->label('Tipo'),
                TextEntry::make('status')->label('Estado')->badge(),
                TextEntry::make('donor.display_name')->label('Donante'),
                TextEntry::make('recipient')->label('Destinatario')->placeholder('Sin correo'),
                TextEntry::make('subject')->label('Asunto')->placeholder('—'),
                TextEntry::make('attachments')->label('Adjuntos')->placeholder('Sin adjuntos')
                    ->formatStateUsing(fn (mixed $state): string => is_array($state) ? implode(', ', $state) : (string) $state),
                TextEntry::make('donation_id')->label('Donativo')->placeholder('—')
                    ->formatStateUsing(fn (int $state): string => "Ver donativo #{$state}")
                    ->url(fn (Communication $record): ?string => $record->donation_id !== null ? DonationResource::getUrl('view', ['record' => $record->donation_id]) : null),
                TextEntry::make('created_at')->label('Registrado')->dateTime('d/m/Y H:i'),
                TextEntry::make('sent_at')->label('Enviado')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('attempts')->label('Intentos'),
                TextEntry::make('requestedBy.name')->label('Pedido por')->placeholder('Automático'),
                IconEntry::make('used_fallback_template')->label('Usó el texto predeterminado')->boolean(),
                TextEntry::make('skip_reason')->label('Motivo de no envío')->placeholder('—')->columnSpanFull(),
                TextEntry::make('last_error')->label('Último error')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['donor']))
            ->columns([
                TextColumn::make('created_at')->label('Registrado')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('kind')->label('Tipo'),
                TextColumn::make('donor.display_name')->label('Donante'),
                TextColumn::make('recipient')->label('Destinatario')->placeholder('—'),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make('sent_at')->label('Enviado')->dateTime('d/m/Y H:i')->placeholder('—')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('kind')->label('Tipo')->options(CommunicationKind::class),
                SelectFilter::make('status')->label('Estado')->options(CommunicationStatus::class),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('Sin envíos')
            ->emptyStateDescription('Aquí aparecen los agradecimientos, CFDI y felicitaciones enviados a donantes.');
    }

    public static function resendAction(): Action
    {
        return Action::make('resend')->label('Reenviar')->icon(Heroicon::OutlinedArrowPath)->color('warning')
            ->requiresConfirmation()
            ->modalDescription('Se envía de nuevo al correo actual del donante. El registro original se conserva.')
            ->visible(fn (Communication $record): bool => Gate::allows('resend', $record))
            ->action(function (Communication $record): void {
                /** @var User $actor */
                $actor = auth()->user();
                self::notifyOutcome(fn () => app(ResendCommunication::class)->handle($record, $actor), 'Reenvío en cola');
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommunications::route('/'),
            'view' => ViewCommunication::route('/{record}'),
        ];
    }
}
