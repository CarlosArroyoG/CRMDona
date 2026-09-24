<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\ChangePassword;
use App\Http\Middleware\EnsurePasswordIsCurrent;
use App\Models\OrganizationSetting;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // Recuperación por correo: sale por el correo saliente vigente (panel o MAIL_*).
            ->passwordReset()
            ->profile(ChangePassword::class, isSimple: false)
            // MFA obligatorio para todos (#45): aplicación autenticadora (TOTP) con códigos de recuperación.
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: true)
            ->databaseNotifications()
            ->navigationGroups(['Donativos', 'Pagos en línea', 'Destinos', 'Administración'])
            ->brandName(fn (): string => (string) config('app.name'))
            // Logo configurado por la organización; sin él, Filament muestra el nombre de la marca.
            ->brandLogo(fn (): ?string => OrganizationSetting::current()->logo_path !== null
                ? Storage::disk('public')->url(OrganizationSetting::current()->logo_path)
                : null)
            ->brandLogoHeight('2.5rem')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                // Paleta explícita: Color::hex() normaliza la luminosidad y aclara el
                // azul marino institucional. Aquí el matiz 600 es el hex exacto (#162562).
                'primary' => [
                    50 => '#f3f4f7',
                    100 => '#e8e9ef',
                    200 => '#c5c8d8',
                    300 => '#a2a8c0',
                    400 => '#5c6691',
                    500 => '#39467a',
                    600 => '#162562',
                    700 => '#131f53',
                    800 => '#0f1a45',
                    900 => '#0c1436',
                    950 => '#090f27',
                ],
                // Acento institucional (uso moderado: insignias, resaltados).
                'warning' => Color::hex('#F2C94C'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            // Persistente: también protege las peticiones Livewire.
            ->authMiddleware([
                EnsurePasswordIsCurrent::class,
            ], isPersistent: true);
    }
}
