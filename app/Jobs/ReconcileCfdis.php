<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Cfdi\IssueCfdiAutomatically;
use App\Cfdi\CfdiProviderRegistry;
use App\Enums\CfdiStatus;
use App\Enums\DonationStatus;
use App\Enums\Permission;
use App\Filament\Resources\Donations\DonationResource;
use App\Models\Cfdi;
use App\Models\Donation;
use App\Models\FiscalIncident;
use App\Support\OperationalAlerts;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Conciliación programada de CFDI:
 * - un timbrado que quedó "Timbrando" (proceso interrumpido) vuelve a la
 *   cola; antes de reenviar, el adaptador busca en el PAC si ya existe;
 * - un error temporal que agotó los reintentos del Job se vuelve a encolar;
 * - las cancelaciones en espera de aceptación se vuelven a consultar;
 * - con emisión automática, los donativos confirmados recientemente que
 *   siguen sin CFDI se vuelven a evaluar (por ejemplo, tras corregir datos
 *   fiscales o configurar el PAC).
 */
class ReconcileCfdis implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 900;

    public function handle(CfdiProviderRegistry $registry, IssueCfdiAutomatically $issue): void
    {
        $this->markLateCoverage();

        if (! $registry->isConfigured()) {
            return;
        }

        DB::table('cfdis')->where('status', CfdiStatus::Stamping->value)
            ->where('updated_at', '<=', now()->subMinutes(config()->integer('cfdi.stamping.stuck_after_minutes')))
            ->update(['status' => CfdiStatus::Failed->value, 'last_error_code' => 'stuck', 'updated_at' => now()]);

        Cfdi::query()->where('status', CfdiStatus::Failed->value)->where('attempts', '<', 20)
            ->where('updated_at', '<=', now()->subMinutes(30))->orderBy('id')->limit(100)
            ->pluck('id')->each(fn (int $id) => StampCfdi::dispatch($id));

        Cfdi::query()->where('status', CfdiStatus::CancellationPending->value)
            ->where('updated_at', '<=', now()->subHour())->orderBy('id')->limit(100)
            ->pluck('id')->each(fn (int $id) => CancelCfdi::dispatch($id, poll: true));

        if ((bool) config('cfdi.auto_issue')) {
            Donation::query()->where('status', DonationStatus::Confirmed->value)
                ->where('confirmed_at', '>=', now()->subHours(config()->integer('cfdi.sweep_hours')))
                ->whereDoesntHave('cfdis', fn (Builder $query) => $query->whereNotIn('status', CfdiStatus::inactiveValues()))
                ->orderBy('id')->limit(100)->get()
                ->each(fn (Donation $donation) => $issue->handle($donation, log: false));
        }
    }

    private function markLateCoverage(): void
    {
        Donation::query()->with(['donor.taxProfile', 'payment.attempts'])
            ->where('status', DonationStatus::Confirmed->value)
            ->where('confirmed_at', '<=', now()->subHours(24))
            ->whereDoesntHave('cfdis', fn (Builder $query) => $query->whereNotIn('status', CfdiStatus::inactiveValues()))
            ->whereDoesntHave('globalCfdis')
            ->orderBy('id')->limit(100)->get()->each(function (Donation $donation): void {
                $donation->forceFill(['fiscal_late_at' => $donation->fiscal_late_at ?? now()])->save();
                $incident = FiscalIncident::query()->firstOrCreate(
                    ['dedupe_key' => "donation:{$donation->id}:cfdi_late"],
                    [
                        'donation_id' => $donation->id,
                        'type' => 'cfdi_late',
                        'status' => 'open',
                        'details' => 'El donativo supera 24 horas sin cobertura CFDI. Se conserva la posibilidad de emisión y reintento.',
                        'detected_at' => now(),
                    ],
                );
                if ($incident->wasRecentlyCreated) {
                    OperationalAlerts::send(
                        "fiscal:late:{$donation->id}",
                        Permission::IssueCfdis,
                        'CFDI tardío: requiere intervención',
                        ["Donativo #{$donation->id} supera 24 horas sin cobertura fiscal."],
                        DonationResource::getUrl('view', ['record' => $donation->id], panel: 'admin'),
                    );
                }
            });
    }
}
