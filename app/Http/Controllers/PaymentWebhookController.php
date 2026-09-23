<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Webhooks\RecordWebhookEvent;
use App\Enums\PaymentProvider;
use App\Payments\Exceptions\GatewayNotAvailableException;
use App\Payments\Exceptions\InvalidWebhookSignatureException;
use App\Payments\GatewayRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Entrada de notificaciones de los proveedores (fase-2-diseno-pagos.md §12):
 * valida la firma, guarda una sola vez y responde de inmediato. El
 * procesamiento ocurre en la cola (ProcessWebhookEvent).
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, GatewayRegistry $registry, RecordWebhookEvent $record): Response
    {
        $paymentProvider = PaymentProvider::tryFrom($provider);
        if ($paymentProvider === null) {
            abort(404);
        }

        try {
            $webhook = $registry->get($paymentProvider)->parseWebhook($request);
        } catch (GatewayNotAvailableException) {
            abort(404);
        } catch (InvalidWebhookSignatureException|JsonException) {
            // Sin cuerpo, encabezados ni firma en el log: solo el hecho.
            Log::warning('Notificación de pago rechazada: firma o formato inválidos.', ['provider' => $paymentProvider->value]);

            return response('', 400);
        }

        $record->handle($paymentProvider, $webhook);

        return response('', 200);
    }
}
