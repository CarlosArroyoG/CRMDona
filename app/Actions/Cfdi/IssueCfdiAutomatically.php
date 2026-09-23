<?php

declare(strict_types=1);

namespace App\Actions\Cfdi;

use App\Cfdi\CfdiProviderRegistry;
use App\Cfdi\Data\FiscalCoverage;
use App\Enums\FiscalRoute;
use App\Models\Donation;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Emisión automática al confirmarse un donativo (manual o en línea, incluida
 * cada mensualidad cobrada) y en la conciliación. [V] La donataria debe
 * expedir CFDI por los donativos que recibe, dentro de las 24 horas: no
 * depende de que el donante lo pida.
 *
 * Solo timbra la ruta individual lista. Público en general y bloqueos quedan
 * registrados (se ven en el donativo) sin crear nada.
 */
class IssueCfdiAutomatically
{
    public function __construct(
        private readonly CfdiProviderRegistry $registry,
        private readonly RequestDonationCfdi $request,
        private readonly ResolveDonationFiscalRoute $route,
    ) {}

    public function handle(Donation $donation, bool $log = true): ?FiscalCoverage
    {
        if (! (bool) config('cfdi.auto_issue') || ! $this->registry->isConfigured()) {
            return null;
        }

        $coverage = $this->route->handle($donation);

        if (! $coverage->isReadyToIssue()) {
            if ($log) {
                Log::log($coverage->route === FiscalRoute::Blocked ? 'warning' : 'info', 'CFDI automático no emitido.', [
                    'donation_id' => $donation->id, 'route' => $coverage->route->value, 'reasons' => $coverage->reasons,
                ]);
            }

            return $coverage;
        }

        try {
            $this->request->handle($donation, null);
        } catch (ValidationException $exception) {
            Log::warning('CFDI automático no emitido.', ['donation_id' => $donation->id, 'reasons' => $exception->errors()['cfdi'] ?? []]);
        }

        return $coverage;
    }
}
