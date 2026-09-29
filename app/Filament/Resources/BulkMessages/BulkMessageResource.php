<?php

declare(strict_types=1);

namespace App\Filament\Resources\BulkMessages;

use App\Communications\BulkAudience;
use App\Enums\BulkMessageStatus;
use App\Enums\DonorType;
use App\Filament\Resources\BulkMessages\Pages\CreateBulkMessage;
use App\Filament\Resources\BulkMessages\Pages\EditBulkMessage;
use App\Filament\Resources\BulkMessages\Pages\ListBulkMessages;
use App\Filament\Resources\BulkMessages\Pages\ViewBulkMessage;
use App\Filament\Resources\BulkMessages\RelationManagers\CommunicationsRelationManager;
use App\Models\BulkMessage;
use App\Models\Campaign;
use App\Models\Program;
use App\Models\Tag;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Number;

/**
 * Envíos masivos informativos (docs/tecnico/carga-y-envios-masivos.md): se
 * redacta el texto, se eligen los filtros, se manda una prueba y se envía.
 * Cada destinatario queda en el historial de envíos.
 */
class BulkMessageResource extends Resource
{
    protected static ?string $model = BulkMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $modelLabel = 'envío masivo';

    protected static ?string $pluralModelLabel = 'Envíos masivos';

    protected static ?string $navigationLabel = 'Envíos masivos';

    protected static string|\UnitEnum|null $navigationGroup = 'Comunicaciones';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Mensaje')
                ->description('Texto simple. Separa párrafos con una línea en blanco. El sistema agrega la firma y el enlace para darse de baja.')
                ->schema([
                    TextInput::make('subject')->label('Asunto')->required()->maxLength(200),
                    Textarea::make('body')->label('Texto')->required()->rows(12)->maxLength(5000)
                        ->helperText('Variables disponibles: '.collect(BulkMessage::VARIABLES)
                            ->map(fn (string $description, string $name): string => "{{ {$name} }} ({$description})")->implode('; ').'.'),
                ]),
            Section::make('A quién se envía')->columns(2)
                ->description('Solo lo reciben donantes activos, con correo y que aceptan comunicaciones. Los filtros se combinan: el donante debe cumplir todos los que elijas. Sin filtros, se envía a todos los que cumplen esas condiciones.')
                ->schema([
                    Select::make('donor_type')->label('Tipo de persona')->options(DonorType::class)->placeholder('Todos')->live(),
                    Select::make('tag_ids')->label('Etiquetas')->multiple()->live()
                        ->options(fn (): array => Tag::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->helperText('Tiene al menos una de las elegidas.'),
                    Select::make('campaign_ids')->label('Donó a la campaña')->multiple()->live()->searchable()
                        ->options(fn (): array => Campaign::query()->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('program_ids')->label('Donó al programa')->multiple()->live()->searchable()
                        ->options(fn (): array => Program::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->helperText('Directo o a través de sus campañas.'),
                    DatePicker::make('donated_from')->label('Donó desde')->native(false)->displayFormat('d/m/Y')->live(),
                    DatePicker::make('donated_until')->label('Donó hasta')->native(false)->displayFormat('d/m/Y')->live(),
                    TextEntry::make('audience_summary')->label('Destinatarios con estos filtros')->columnSpanFull()
                        ->state(fn (Get $get): string => self::summaryText(BulkAudience::fromArray([
                            'donor_type' => $get('donor_type'),
                            'tag_ids' => $get('tag_ids'),
                            'campaign_ids' => $get('campaign_ids'),
                            'program_ids' => $get('program_ids'),
                            'donated_from' => $get('donated_from'),
                            'donated_until' => $get('donated_until'),
                        ]))),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Mensaje')->schema([
                TextEntry::make('subject')->label('Asunto'),
                TextEntry::make('body')->label('Texto')->extraAttributes(['style' => 'white-space: pre-line']),
            ]),
            Section::make('A quién se envía')->columns(2)->schema([
                TextEntry::make('audience_filters')->label('Filtros')->listWithLineBreaks()
                    ->state(fn (BulkMessage $record): array => self::describeAudience($record->audience())),
                TextEntry::make('audience_summary')->label('Hoy cumplen los filtros')
                    ->visible(fn (BulkMessage $record): bool => $record->isDraft())
                    ->state(fn (BulkMessage $record): string => self::summaryText($record->audience())),
            ]),
            Section::make('Estado')->columns(3)->schema([
                TextEntry::make('status')->label('Estado')->badge(),
                TextEntry::make('recipients_count')->label('Destinatarios')->placeholder('Se calculan al enviar'),
                TextEntry::make('progress')->label('Resultado')
                    ->visible(fn (BulkMessage $record): bool => ! $record->isDraft())
                    ->state(fn (BulkMessage $record): string => self::progressText($record)),
                TextEntry::make('createdBy.name')->label('Preparado por'),
                TextEntry::make('tested_at')->label('Última prueba')->dateTime('d/m/Y H:i')->placeholder('Sin prueba del texto actual'),
                TextEntry::make('sent_at')->label('Enviado')->dateTime('d/m/Y H:i')->placeholder('—')
                    ->helperText(fn (BulkMessage $record): ?string => $record->sentBy !== null ? 'Por '.$record->sentBy->name : null),
                TextEntry::make('stopped_at')->label('Detenido')->dateTime('d/m/Y H:i')
                    ->visible(fn (BulkMessage $record): bool => $record->stopped_at !== null)
                    ->helperText(fn (BulkMessage $record): ?string => $record->stoppedBy !== null ? 'Por '.$record->stoppedBy->name : null),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subject')->label('Asunto')->limit(60)->searchable(),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make('recipients_count')->label('Destinatarios')->placeholder('—'),
                TextColumn::make('createdBy.name')->label('Preparado por'),
                TextColumn::make('sent_at')->label('Enviado')->dateTime('d/m/Y H:i')->placeholder('—')->sortable(),
                TextColumn::make('created_at')->label('Creado')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(BulkMessageStatus::class),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('Sin envíos masivos')
            ->emptyStateDescription('Crea uno para escribir a varios donantes a la vez. Solo lo reciben quienes aceptan comunicaciones.');
    }

    /**
     * @return list<string>
     */
    public static function describeAudience(BulkAudience $audience): array
    {
        $names = fn (string $model, array $ids): string => $model::query()->whereKey($ids)->orderBy('name')->pluck('name')->implode(', ');

        $lines = array_values(array_filter([
            $audience->donorType !== null ? 'Tipo: '.$audience->donorType->getLabel() : null,
            $audience->tagIds !== [] ? 'Con alguna etiqueta: '.$names(Tag::class, $audience->tagIds) : null,
            $audience->campaignIds !== [] ? 'Donó a la campaña: '.$names(Campaign::class, $audience->campaignIds) : null,
            $audience->programIds !== [] ? 'Donó al programa: '.$names(Program::class, $audience->programIds) : null,
            $audience->donatedFrom !== null ? 'Donó desde el '.date('d/m/Y', (int) strtotime($audience->donatedFrom)) : null,
            $audience->donatedUntil !== null ? 'Donó hasta el '.date('d/m/Y', (int) strtotime($audience->donatedUntil)) : null,
        ]));

        return $lines === [] ? ['Todos los donantes activos'] : $lines;
    }

    public static function summaryText(BulkAudience $audience): string
    {
        $summary = $audience->summary();

        return Number::format($summary['recipients']).' recibirán el correo. De '.Number::format($summary['matching']).' donantes activos que cumplen los filtros, '
            .Number::format($summary['without_email']).' no tienen correo y '.Number::format($summary['without_consent']).' no aceptan comunicaciones o no las tienen verificadas (registrados en la página pública sin donativo confirmado).';
    }

    public static function progressText(BulkMessage $message): string
    {
        $counts = $message->communications()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $count = fn (string ...$statuses): string => (string) Number::format((int) collect($statuses)->sum(fn (string $status): int => (int) ($counts[$status] ?? 0)));

        return "Enviados: {$count('sent')} · En cola: {$count('queued', 'sending')} · Fallidos: {$count('failed', 'bounced')} · No enviados: {$count('skipped')}";
    }

    public static function getRelations(): array
    {
        return [
            CommunicationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBulkMessages::route('/'),
            'create' => CreateBulkMessage::route('/create'),
            'view' => ViewBulkMessage::route('/{record}'),
            'edit' => EditBulkMessage::route('/{record}/edit'),
        ];
    }
}
