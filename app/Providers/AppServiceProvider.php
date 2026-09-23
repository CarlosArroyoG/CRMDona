<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Users\CreateUser;
use App\Cfdi\CfdiProviderRegistry;
use App\Listeners\CheckDatabaseHealth;
use App\Listeners\ReportFailedJob;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Cfdi;
use App\Models\Communication;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Donor;
use App\Models\DonorTaxProfile;
use App\Models\Export;
use App\Models\MessageTemplate;
use App\Models\OrganizationSetting;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentDispute;
use App\Models\PaymentIncident;
use App\Models\PaymentIncidentNote;
use App\Models\Program;
use App\Models\Refund;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Payments\GatewayRegistry;
use App\Support\AuditOrigin;
use Filament\Actions\Exports\Models\Export as FilamentExport;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
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

        // Pagos (ADR-011): una pasarela por proveedor, según configuración.
        $this->app->singleton(GatewayRegistry::class);
        // CFDI (Fase 3): un solo PAC configurado.
        $this->app->singleton(CfdiProviderRegistry::class);
        // Procedencia de los cambios en la bitácora: se reinicia en cada petición y Job.
        $this->app->scoped(AuditOrigin::class);
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
            'payment' => Payment::class,
            'payment_attempt' => PaymentAttempt::class,
            'subscription' => Subscription::class,
            'refund' => Refund::class,
            'payment_dispute' => PaymentDispute::class,
            'payment_incident' => PaymentIncident::class,
            'payment_incident_note' => PaymentIncidentNote::class,
            'webhook_event' => WebhookEvent::class,
            'cfdi' => Cfdi::class,
            'donation_receipt' => DonationReceipt::class,
            'message_template' => MessageTemplate::class,
            'communication' => Communication::class,
        ]);

        // Dentro de un Job de la cola, los cambios se registran como "Proceso automático".
        Queue::before(fn () => app(AuditOrigin::class)->enterQueuedJob());
        Queue::after(fn () => app(AuditOrigin::class)->leaveQueuedJob());
        Queue::failing(fn () => app(AuditOrigin::class)->leaveQueuedJob());
        // Fase 7: log crítico y aviso a Administradores por cada Job que agota sus intentos.
        Event::listen(JobFailed::class, ReportFailedJob::class);
        // `/up` comprueba también la conexión a PostgreSQL.
        Event::listen(DiagnosingHealth::class, CheckDatabaseHealth::class);

        $this->configureTrustedProxies();
        $this->prohibitDestructiveCommandsOutsideDisposableDatabases();

        // Página pública de donativos: envíos por IP y minuto.
        RateLimiter::for('public-donations', fn (Request $request): Limit => Limit::perMinute(config()->integer('donations.public.rate_limit_per_minute'))
            ->by((string) $request->ip()));
    }

    /**
     * migrate:fresh, migrate:refresh, migrate:reset, migrate:rollback y db:wipe
     * solo se permiten fuera de producción y contra una base desechable
     * (`security.destructive_databases` o las copias paralelas de crm_testing).
     * `php artisan migrate` normal nunca se bloquea.
     */
    private function prohibitDestructiveCommandsOutsideDisposableDatabases(): void
    {
        $database = (string) config('database.connections.'.config()->string('database.default').'.database');
        /** @var list<string> $allowed */
        $allowed = config()->array('security.destructive_databases');
        $disposable = in_array($database, $allowed, true) || str_starts_with($database, 'crm_testing_test_');

        DB::prohibitDestructiveCommands(! config()->boolean('security.allow_destructive_commands') && (app()->isProduction() || ! $disposable));
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
