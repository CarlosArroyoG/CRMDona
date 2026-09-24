<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLogs;

use App\Enums\AuditEvent;
use App\Enums\AuditSource;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bitácora de solo lectura (ADR-006). Solo el Administrador la consulta.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'registro de bitácora';

    protected static ?string $pluralModelLabel = 'bitácora';

    protected static ?string $navigationLabel = 'Bitácora de cambios';

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    protected static ?int $navigationSort = 5;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Registro')->columns(3)->schema([
                TextEntry::make('created_at')->label('Fecha y hora')->dateTime('d/m/Y H:i:s'),
                TextEntry::make('user.name')->label('Usuario')->placeholder('Sin usuario (proceso automático o consola)'),
                TextEntry::make('source')->label('Procedencia')->placeholder('No registrada (antes de la Fase 2)'),
                TextEntry::make('event')->label('Evento')->badge(),
                TextEntry::make('auditable_type')->label('Tipo de registro')
                    ->formatStateUsing(fn (string $state): string => self::typeLabel($state)),
                TextEntry::make('auditable_id')->label('Número de registro'),
            ]),
            Section::make('Cambios')->schema([
                TextEntry::make('changes')->label('Detalle')
                    ->state(fn (AuditLog $record): array => self::describeChanges($record))
                    ->listWithLineBreaks()->bulleted()
                    ->placeholder('Sin campos registrados'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                TextColumn::make('created_at')->label('Fecha y hora')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('user.name')->label('Usuario')->placeholder('Automático'),
                TextColumn::make('source')->label('Procedencia')->placeholder('—')->toggleable(),
                TextColumn::make('event')->label('Evento')->badge(),
                TextColumn::make('auditable_type')->label('Tipo de registro')
                    ->formatStateUsing(fn (string $state): string => self::typeLabel($state)),
                TextColumn::make('auditable_id')->label('Número'),
                TextColumn::make('changed_fields')->label('Campos')
                    ->state(fn (AuditLog $record): string => collect($record->changed_fields)->map(fn (string $field): string => self::fieldLabel($field))->implode(', '))
                    ->limit(60)->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('event')->label('Evento')->options(AuditEvent::class),
                SelectFilter::make('source')->label('Procedencia')->options(AuditSource::class),
                SelectFilter::make('auditable_type')->label('Tipo de registro')
                    ->options(fn (): array => (array) trans('audit.types')),
                SelectFilter::make('user_id')->label('Usuario')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('Desde')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('until')->label('Hasta')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('Sin registros en la bitácora');
    }

    public static function typeLabel(string $type): string
    {
        $key = "audit.types.{$type}";
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $type;
    }

    public static function fieldLabel(string $field): string
    {
        $key = "audit.fields.{$field}";
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $field;
    }

    /**
     * "Campo: antes → después" para campos con valor; "Campo (cambió)" para
     * los que por privacidad no guardan valor.
     *
     * @return list<string>
     */
    public static function describeChanges(AuditLog $record): array
    {
        $old = $record->old_values ?? [];
        $new = $record->new_values ?? [];

        return array_map(function (string $field) use ($old, $new): string {
            $label = self::fieldLabel($field);
            if (! array_key_exists($field, $old) && ! array_key_exists($field, $new)) {
                return "{$label} (cambió; valor no registrado por privacidad)";
            }

            return $label.': '.self::display($old[$field] ?? null).' → '.self::display($new[$field] ?? null);
        }, $record->changed_fields);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
    }

    private static function display(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '' => '(vacío)',
            is_bool($value) => $value ? 'Sí' : 'No',
            is_array($value) => $value === [] ? '(ninguna)' : implode(', ', array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value)),
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
