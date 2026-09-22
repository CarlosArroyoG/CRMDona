<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Number::useLocale(config()->string('app.regional_locale'));
        Number::useCurrency(config()->string('app.currency'));

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
