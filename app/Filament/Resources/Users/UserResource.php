<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Actions\Users\CreateUser;
use App\Actions\Users\ResetUserPassword;
use App\Actions\Users\SetUserActive;
use App\Enums\Role;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Users\Pages\CreateUserPage;
use App\Filament\Resources\Users\Pages\EditUserPage;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Support\Search;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Solo el Administrador. Los usuarios no se eliminan: se desactivan.
 * Las contraseñas nunca se muestran; cada usuario cambia la suya.
 */
class UserResource extends Resource
{
    use ReportsActionErrors;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'usuario';

    protected static ?string $pluralModelLabel = 'usuarios';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Usuario')->columns(2)->schema([
                TextInput::make('name')->label('Nombre')->required()->maxLength(255),
                TextInput::make('email')->label('Correo electrónico')->email()->required()->maxLength(255),
                Select::make('role')->label('Rol')->options(Role::class)->required()->native(false)
                    ->helperText('Determina qué puede ver y hacer. Un usuario tiene un solo rol.'),
            ]),
            Section::make('Alertas de pagos')->visibleOn('edit')->schema([
                Toggle::make('receives_payment_alerts')->label('Recibe alertas de pagos con problemas')
                    ->helperText('Aplica a Coordinador (solo incidencias operativas) y Contador. Los Administradores siempre las reciben; Solo lectura nunca.'),
            ]),
            Section::make('Contraseña inicial')
                ->description('Mínimo '.CreateUser::PASSWORD_MIN_LENGTH.' caracteres, con letras y números. Entrégala a la persona por un medio seguro; podrá cambiarla en "Cambiar contraseña".')
                ->visibleOn('create')
                ->columns(2)
                ->schema([
                    TextInput::make('password')->label('Contraseña')->password()->revealable()->required(),
                    TextInput::make('password_confirmation')->label('Confirma la contraseña')->password()->revealable()->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nombre')->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => Search::unaccent($query, 'name', $search)),
                TextColumn::make('email')->label('Correo')->searchable(),
                TextColumn::make('role')->label('Rol')->badge()->sortable(),
                TextColumn::make('deactivated_at')->label('Estado')->badge()
                    ->state(fn (User $record): string => $record->isActive() ? 'Activo' : 'Desactivado')
                    ->color(fn (User $record): string => $record->isActive() ? 'success' : 'gray'),
                TextColumn::make('created_at')->label('Alta')->date('d/m/Y')->sortable()->toggleable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('role')->label('Rol')->options(Role::class),
                TernaryFilter::make('active')->label('Estado')
                    ->trueLabel('Activos')->falseLabel('Desactivados')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('deactivated_at'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('deactivated_at'),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                self::toggleActiveAction(),
                self::resetPasswordAction(),
            ])
            ->emptyStateHeading('No hay usuarios con esos filtros');
    }

    public static function toggleActiveAction(): Action
    {
        return Action::make('toggleActive')
            ->label(fn (User $record): string => $record->isActive() ? 'Desactivar' : 'Reactivar')
            ->icon(fn (User $record): Heroicon => $record->isActive() ? Heroicon::OutlinedNoSymbol : Heroicon::OutlinedArrowUturnLeft)
            ->color(fn (User $record): string => $record->isActive() ? 'danger' : 'gray')
            ->requiresConfirmation()
            ->modalDescription(fn (User $record): string => $record->isActive()
                ? 'La persona ya no podrá entrar al panel. Sus registros e historial se conservan.'
                : 'La persona podrá volver a entrar al panel con su contraseña actual.')
            ->visible(fn (User $record): bool => Gate::allows('deactivate', $record) || Gate::allows('reactivate', $record))
            ->action(function (User $record): void {
                /** @var User $actor */
                $actor = auth()->user();
                $activate = ! $record->isActive();
                self::notifyOutcome(
                    fn () => app(SetUserActive::class)->handle($record, $activate, $actor),
                    $activate ? 'Usuario reactivado' : 'Usuario desactivado',
                );
            });
    }

    /**
     * Genera una contraseña temporal y la muestra una sola vez en una
     * notificación de pantalla (no se guarda en la base ni en la bitácora).
     */
    public static function resetPasswordAction(): Action
    {
        return Action::make('resetPassword')
            ->label('Restablecer contraseña')
            ->icon(Heroicon::OutlinedKey)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Restablecer contraseña')
            ->modalDescription(fn (User $record): string => 'Se generará una contraseña temporal para '.$record->name
                .'. Se mostrará una sola vez: entrégala por un medio seguro. La persona deberá cambiarla al entrar y vence en '
                .config()->integer('auth.temporary_password_ttl_hours').' horas. Sus demás sesiones se cerrarán. No cambia su rol ni su estado.')
            ->modalSubmitActionLabel('Generar contraseña temporal')
            ->visible(fn (User $record): bool => Gate::allows('resetPassword', $record))
            ->action(function (User $record): void {
                /** @var User $actor */
                $actor = auth()->user();

                try {
                    $temporary = app(ResetUserPassword::class)->generateTemporary($record, $actor);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('No se pudo restablecer')
                        ->body(implode(' ', $exception->validator->errors()->all()))->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Contraseña temporal de '.$record->name.': '.$temporary)
                    ->body('Cópiala ahora: no se volverá a mostrar. Vence en '
                        .config()->integer('auth.temporary_password_ttl_hours').' horas.'
                        .($record->isActive() ? '' : ' El usuario sigue desactivado: reactívalo si debe entrar.'))
                    ->persistent()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUserPage::route('/create'),
            'edit' => EditUserPage::route('/{record}/edit'),
        ];
    }
}
