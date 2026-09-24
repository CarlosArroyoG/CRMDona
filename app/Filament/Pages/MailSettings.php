<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Mail\SendTestEmail;
use App\Actions\Mail\UpdateMailSettings;
use App\Enums\MailEncryption;
use App\Enums\Permission;
use App\Filament\Concerns\ReportsActionErrors;
use App\Mail\Outgoing\OutgoingMailConfig;
use App\Models\MailSetting;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * Correo saliente (solo Administrador): SMTP estándar, sin campos de un
 * proveedor concreto. La contraseña nunca vuelve al navegador: el campo
 * siempre aparece vacío y vacío significa "conservar la actual". Toda la
 * lógica vive en UpdateMailSettings y SendTestEmail.
 *
 * @property-read Schema $form
 */
class MailSettings extends Page
{
    use ReportsActionErrors;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $navigationLabel = 'Correo saliente';

    protected static ?string $title = 'Correo saliente';

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'correo-saliente';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return self::actor()?->hasPermission(Permission::ManageMailSettings) ?? false;
    }

    public function mount(): void
    {
        $this->fillForm();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Servidor SMTP')
                    ->description('Funciona con cualquier proveedor SMTP estándar. El servidor, el puerto y la seguridad exactos los indica tu proveedor de correo.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('enabled')->label('Usar este servidor SMTP para todos los correos del CRM')->columnSpanFull()
                            ->helperText('Apagado: el CRM usa la configuración del entorno (MAIL_*). Puedes probar la configuración antes de activarla.'),
                        TextInput::make('host')->label('Servidor SMTP')->maxLength(255)->placeholder('smtp.ejemplo.com')
                            ->helperText('Solo el nombre del servidor o su IP, sin "smtp://" ni puerto.'),
                        TextInput::make('port')->label('Puerto')->integer()->minValue(1)->maxValue(65535)->placeholder('587')
                            ->helperText('Normalmente 587 (STARTTLS) o 465 (SSL/TLS).'),
                        Select::make('encryption')->label('Seguridad de la conexión')->options(MailEncryption::class)->required()->native(false)
                            ->helperText('Debe coincidir con el puerto. "Ninguno" solo para servidores internos de confianza.'),
                        TextInput::make('timeout')->label('Tiempo de espera (segundos)')->integer()->minValue(1)->maxValue(120)->required()
                            ->helperText('Cuánto esperar la respuesta del servidor antes de marcar el envío como fallido.'),
                        TextInput::make('username')->label('Usuario')->maxLength(255)->autocomplete('off')
                            ->helperText('Normalmente la cuenta de correo o el usuario que te dio el proveedor. Vacío si el servidor no pide autenticación.'),
                        TextInput::make('password')->label('Contraseña')->password()->revealable(false)->maxLength(500)->autocomplete('new-password')
                            ->placeholder(fn (): string => MailSetting::current()->hasPassword() ? 'Guardada — escribe solo para cambiarla' : 'Sin contraseña guardada')
                            ->helperText('Nunca se muestra. Vacía = se conserva la guardada.'),
                        Toggle::make('remove_password')->label('Eliminar la contraseña guardada')
                            ->visible(fn (): bool => MailSetting::current()->hasPassword())
                            ->helperText('Solo si el servidor ya no pide autenticación (deja también el usuario vacío).'),
                    ]),
                Section::make('Remitente')
                    ->description('Usa un correo que tu proveedor permita enviar (normalmente de tu propio dominio).')
                    ->columns(2)
                    ->schema([
                        TextInput::make('from_address')->label('Correo del remitente')->email()->maxLength(255)->placeholder('donativos@ejemplo.org'),
                        TextInput::make('from_name')->label('Nombre del remitente')->maxLength(255)
                            ->helperText('Lo que ve el destinatario, por ejemplo el nombre de la Fundación.'),
                        TextInput::make('reply_to_address')->label('Responder a (opcional)')->email()->maxLength(255)
                            ->helperText('Si las respuestas deben llegar a otro buzón.'),
                        TextInput::make('reply_to_name')->label('Nombre para respuestas (opcional)')->maxLength(255),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        $settings = MailSetting::current()->load('lastSuccessfulTestBy');

        return $schema->components([
            Section::make('Estado')->columns(4)->schema([
                TextEntry::make('configured')->label('Configuración')->badge()
                    ->state($settings->isConfigured() ? 'Configurado' : 'No configurado')
                    ->color($settings->isConfigured() ? 'success' : 'gray'),
                TextEntry::make('enabled_state')->label('Servidor del panel')->badge()
                    ->state($settings->isActive() ? 'Habilitado' : 'Deshabilitado')
                    ->color($settings->isActive() ? 'success' : 'gray'),
                TextEntry::make('in_use')->label('El CRM envía con')
                    ->state(app(OutgoingMailConfig::class)->usesAdministrativeSmtp() ? 'Este servidor SMTP' : 'La configuración del entorno (MAIL_*)'),
                TextEntry::make('last_test')->label('Última prueba aceptada')
                    ->state($settings->last_successful_test_at !== null
                        ? $settings->last_successful_test_at->timezone(config()->string('app.timezone'))->format('d/m/Y H:i').' — '.($settings->lastSuccessfulTestBy->name ?? '—')
                        : 'Sin pruebas aceptadas'),
                Text::make('"Aceptado" significa que el servidor SMTP recibió el mensaje; no garantiza que llegue al buzón del destinatario.')->columnSpanFull(),
            ]),
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Guardar')->submit('save'),
                    ]),
                ]),
            Section::make('Configuración recomendada del dominio')
                ->description('Informativo. El CRM no modifica ni verifica el DNS: esto se configura con tu proveedor de correo y en el DNS de tu dominio.')
                ->collapsible()
                ->schema([
                    Text::make('SPF: registro TXT del dominio que autoriza a los servidores de tu proveedor a enviar en nombre del dominio.'),
                    Text::make('DKIM: firma criptográfica de los correos; el proveedor te da uno o más registros DNS para publicar.'),
                    Text::make('DMARC: registro TXT (_dmarc.tu-dominio) que indica qué hacer con correos que fallan SPF o DKIM y a dónde enviar reportes. Empieza con p=none y endurécelo después.'),
                    Text::make('Sin SPF, DKIM y DMARC, los correos del CRM pueden llegar a spam aunque el servidor SMTP los acepte.'),
                ]),
        ]);
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [$this->testAction()];
    }

    public function save(): void
    {
        $actor = self::actor() ?? abort(403);
        abort_unless($actor->hasPermission(Permission::ManageMailSettings), 403);

        self::withFormErrors(fn () => app(UpdateMailSettings::class)->handle($this->form->getState(), $actor));

        $this->fillForm();
        Notification::make()->success()->title('Correo saliente guardado')->send();
    }

    private function testAction(): Action
    {
        return Action::make('sendTest')
            ->label('Enviar correo de prueba')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->modalDescription('Se envía con la configuración GUARDADA (guarda antes tus cambios). No lleva información de donantes.')
            ->schema([
                TextInput::make('recipient')->label('Enviar a')->email()->required()->maxLength(255)
                    ->default(fn (): ?string => self::actor()?->email),
            ])
            ->action(function (array $data): void {
                $actor = self::actor() ?? abort(403);
                try {
                    app(SendTestEmail::class)->handle((string) $data['recipient'], $actor);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('No se pudo enviar el correo de prueba')
                        ->body(implode(' ', $exception->validator->errors()->all()))->persistent()->send();

                    return;
                }

                Notification::make()->success()->title('Correo de prueba aceptado por el servidor SMTP')
                    ->body('Revisa la bandeja de entrada (y spam) del destinatario.')->send();
            });
    }

    private function fillForm(): void
    {
        $settings = MailSetting::current();

        // La contraseña nunca se envía al navegador.
        $this->form->fill([
            ...$settings->only(['enabled', 'host', 'port', 'username', 'from_address', 'from_name', 'reply_to_address', 'reply_to_name', 'timeout']),
            'encryption' => $settings->encryption->value,
            'password' => null,
            'remove_password' => false,
        ]);
    }

    private static function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
