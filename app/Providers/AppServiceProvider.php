<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Users\CreateUser;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\DonorTaxProfile;
use App\Models\Export;
use App\Models\OrganizationSetting;
use App\Models\Program;
use App\Models\Tag;
use App\Models\User;
use Filament\Actions\Exports\Models\Export as FilamentExport;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Exportaciones con purga a 7 días (ADR-007).
        $this->app->bind(FilamentExport::class, Export::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Number::useLocale(config()->string('app.regional_locale'));
        Number::useCurrency(config()->string('app.currency'));

        // Política única de contraseñas: alta de usuarios y cambio propio.
        Password::defaults(fn (): Password => Password::min(CreateUser::PASSWORD_MIN_LENGTH)->letters()->numbers());

        // Nombres estables en la bitácora y notificaciones (no nombres de clase).
        Relation::enforceMorphMap([
            'user' => User::class,
            'donor' => Donor::class,
            'donor_tax_profile' => DonorTaxProfile::class,
            'tag' => Tag::class,
            'program' => Program::class,
            'campaign' => Campaign::class,
            'donation' => Donation::class,
            'organization_setting' => OrganizationSetting::class,
            'audit_log' => AuditLog::class,
            'export' => Export::class,
        ]);

        $this->configureTrustedProxies();
    }

    /**
     * Coolify termina HTTPS en su proxy. Solo se confía en la IP del cliente
     * (X-Forwarded-For) y el esquema original (X-Forwarded-Proto); Host y
     * puerto llegan intactos, así que no se aceptan X-Forwarded-Host/Port y
     * se evita la inyección de cabecera Host.
     */
    private function configureTrustedProxies(): void
    {
        TrustProxies::at(array_map('trim', explode(',', config()->string('app.trusted_proxies'))));
        TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
    }
}
