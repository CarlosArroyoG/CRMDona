<?php

declare(strict_types=1);

namespace App\Actions\Cfdi;

use App\Cfdi\CfdiProviderRegistry;
use App\Models\Donation;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Emisión automática al confirmarse un donativo (manual o en línea, incluida
 * cada mensualidad cobrada). [F] Apagada (`cfdi.auto_issue`) hasta decidir si
 * se emite a todo donativo o solo a quien lo solicita. Si el donativo aún no
 * puede tener CFDI, no se crea nada: se podrá solicitar después a mano.
 */
class IssueCfdiAutomatically
{
    public function __construct(
        private readonly CfdiProviderRegistry $registry,
        private readonly RequestDonationCfdi $request,
    ) {}

    public function handle(Donation $donation): void
    {
        if (! (bool) config('cfdi.auto_issue') || ! $this->registry->isConfigured() || ! $donation->tax_receipt_requested) {
            return;
        }

        try {
            $this->request->handle($donation, null);
        } catch (ValidationException $exception) {
            Log::info('CFDI automático no emitido: el donativo aún no cumple los requisitos.', [
                'donation_id' => $donation->id, 'reasons' => $exception->errors()['cfdi'] ?? [],
            ]);
        }
    }
}
