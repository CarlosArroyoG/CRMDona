<?php

declare(strict_types=1);

namespace App\Payments\Contracts;

use App\Enums\PaymentProvider;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Subscription;
use App\Payments\Data\AmountLimits;
use App\Payments\Data\InboundWebhook;
use App\Payments\Data\ProviderSnapshot;
use App\Payments\Exceptions\InvalidWebhookSignatureException;
use App\Payments\Exceptions\PaymentProviderException;
use Illuminate\Http\Request;
use JsonException;

/**
 * Contrato base de toda pasarela (fase-2-diseno-pagos.md §21). Las
 * capacidades opcionales (pago único, mensual, pausa, reembolsos) son
 * interfaces aparte: el dominio pregunta con `instanceof`, nunca por el
 * nombre del proveedor. Ninguna Action ni pantalla usa clases del SDK de un
 * proveedor.
 */
interface PaymentGateway
{
    public function provider(): PaymentProvider;

    public function isTestMode(): bool;

    /**
     * Límites técnicos verificados del proveedor (MXN, tarjeta).
     */
    public function amountLimits(): AmountLimits;

    /**
     * Autentica la notificación y la reduce a la lista permitida.
     *
     * @throws InvalidWebhookSignatureException
     * @throws JsonException si el cuerpo no es JSON válido
     */
    public function parseWebhook(Request $request): InboundWebhook;

    /**
     * Estado actual de un recurso del proveedor. Se llama siempre fuera de
     * una transacción de base de datos.
     *
     * @throws PaymentProviderException
     */
    public function fetch(string $resourceType, string $externalId): ProviderSnapshot;

    /**
     * @throws PaymentProviderException
     */
    public function fetchPayment(Payment $payment): ProviderSnapshot;

    /**
     * @throws PaymentProviderException
     */
    public function fetchSubscription(Subscription $subscription): ProviderSnapshot;

    /**
     * @throws PaymentProviderException
     */
    public function fetchRefund(Refund $refund): ProviderSnapshot;
}
