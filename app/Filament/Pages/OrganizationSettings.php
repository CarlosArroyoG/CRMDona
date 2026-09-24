<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Organization\UpdateOrganizationSettings;
use App\Enums\Permission;
use App\Enums\TaxRegime;
use App\Filament\Concerns\ReportsActionErrors;
use App\Models\OrganizationSetting;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Datos de la organización: los ve el Contador, solo los edita el
 * Administrador. No contiene secretos ni configuración técnica.
 *
 * @property-read Schema $form
 */
class OrganizationSettings extends Page
{
    use ReportsActionErrors;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Organización';

    protected static ?string $title = 'Configuración de la organización';

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'organizacion';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return self::actor()?->hasPermission(Permission::ViewOrganizationSettings) ?? false;
    }

    public static function canEdit(): bool
    {
        return self::actor()?->hasPermission(Permission::UpdateOrganizationSettings) ?? false;
    }

    public function mount(): void
    {
        $settings = OrganizationSetting::current();

        $this->form->fill([
            ...$settings->only([
                'legal_name', 'rfc', 'tax_postal_code', 'authorization_number', 'donation_legend',
                'logo_path', 'email_signature', 'privacy_notice_url', 'privacy_notice_version',
            ]),
            'tax_regime' => $settings->tax_regime?->value,
            'authorization_date' => $settings->authorization_date?->toDateString(),
            'online_donation_min_amount' => $settings->online_donation_min_amount,
            'online_donation_max_amount' => $settings->online_donation_max_amount,
            'thank_you_emails_enabled' => $settings->thank_you_emails_enabled,
            'birthday_emails_enabled' => $settings->birthday_emails_enabled,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->disabled(! self::canEdit())
            ->components([
                Section::make('Datos fiscales de la organización')->columns(2)->schema([
                    TextInput::make('legal_name')->label('Razón social')->maxLength(255)->columnSpanFull(),
                    TextInput::make('rfc')->label('RFC')->maxLength(12)->extraInputAttributes(['style' => 'text-transform: uppercase']),
                    Select::make('tax_regime')->label('Régimen fiscal')->options(TaxRegime::class)->searchable(),
                    TextInput::make('tax_postal_code')->label('Código postal fiscal')->length(5),
                ]),
                Section::make('Autorización como donataria')->columns(2)->schema([
                    TextInput::make('authorization_number')->label('Número de oficio o carta de autorización')->maxLength(100),
                    DatePicker::make('authorization_date')->label('Fecha de autorización')->native(false)->displayFormat('d/m/Y')->maxDate(now()),
                    Textarea::make('donation_legend')->label('Leyenda de donativo')->rows(3)->maxLength(2000)->columnSpanFull()
                        ->helperText('Texto que acompañará a los recibos en fases posteriores.'),
                ]),
                Section::make('Aviso de privacidad')
                    ->description('Sin URL y versión no se puede registrar que un donante aceptó el aviso.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('privacy_notice_url')->label('URL del aviso de privacidad')->url()->maxLength(255),
                        TextInput::make('privacy_notice_version')->label('Versión vigente')->maxLength(50)
                            ->helperText('Ejemplo: 2026-09. Cámbiala cuando se publique un aviso nuevo.'),
                    ]),
                Section::make('Donativos en línea')
                    ->description(fn (): string => 'Página pública: '.route('donate.create').' (cada campaña tiene la suya en su ficha). Reglas de la organización: además siempre aplica el límite técnico de cada proveedor; se usa el más restrictivo (consulta "Pasarelas de pago").')
                    ->columns(2)
                    ->schema([
                        TextInput::make('online_donation_min_amount')->label('Importe mínimo por donativo (MXN)')->prefix('$')->inputMode('decimal')
                            ->helperText('Vacío: sin mínimo propio; solo aplica el del proveedor.'),
                        TextInput::make('online_donation_max_amount')->label('Importe máximo por donativo (MXN)')->prefix('$')->inputMode('decimal')
                            ->helperText('Vacío: sin máximo adicional de la organización.'),
                    ]),
                Section::make('Imagen y comunicación')->columns(2)->schema([
                    // Solo PNG o JPG, por contenido y por extensión: image() admite SVG, y Filament guarda la
                    // extensión del cliente (un PNG llamado .html se serviría como página desde el disco público).
                    FileUpload::make('logo_path')->label('Logotipo')->image()->acceptedFileTypes(['image/png', 'image/jpeg'])
                        ->rules(['extensions:png,jpg,jpeg'])->disk('public')->directory('organization')->maxSize(2048)->helperText('PNG o JPG, máximo 2 MB.'),
                    Textarea::make('email_signature')->label('Firma de correo')->rows(4)->maxLength(2000)
                        ->helperText('Texto simple. Aparece al final de los correos a donantes.'),
                ]),
                Section::make('Correos automáticos a donantes')
                    ->description('Los textos se editan en Comunicaciones → Plantillas. El servidor de correo se configura en Administración → Correo saliente.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('thank_you_emails_enabled')->label('Agradecimiento al confirmar un donativo')
                            ->helperText('Incluye el recibo simple. Nunca espera ni adjunta un CFDI: Contabilidad los emite fuera del CRM.'),
                        Toggle::make('birthday_emails_enabled')->label('Felicitación de cumpleaños (09:00)')
                            ->helperText('Solo a donantes que aceptan comunicaciones y no están archivados.'),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Guardar')->submit('save')->visible(self::canEdit()),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(self::canEdit(), 403);

        self::withFormErrors(fn () => app(UpdateOrganizationSettings::class)->handle($this->form->getState()));

        Notification::make()->success()->title('Configuración guardada')->send();
    }

    private static function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
