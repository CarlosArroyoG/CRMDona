<?php

declare(strict_types=1);

use App\Enums\PaymentProvider;
use App\Logging\RedactSensitiveData;
use App\Payments\Exceptions\PaymentProviderException;
use App\Support\SensitiveData;
use Illuminate\Support\Facades\Log;

it('la lista permitida copia solo rutas escalares indicadas', function (): void {
    $data = [
        'id' => 'evt_1',
        'data' => ['object' => [
            'id' => 'pi_1', 'amount' => 5000, 'status' => 'succeeded',
            'payment_method_details' => ['card' => ['brand' => 'visa', 'last4' => '4242', 'fingerprint' => 'abc', 'exp_month' => 1]],
            'charges' => [['id' => 'ch_1', 'outcome' => ['reason' => 'x']], ['id' => 'ch_2']],
            'billing_details' => ['name' => 'Rosa'],
        ], 'previous_attributes' => ['status' => 'processing', 'amount' => 1]],
    ];

    $allowed = SensitiveData::allow($data, [
        'id', 'data.object.id', 'data.object.status', 'data.object.payment_method_details.card.brand',
        'data.object.payment_method_details.card.last4', 'data.object.charges.*.id', 'data.previous_attributes#keys',
        // Una ruta que apunta a un subárbol no lo copia completo.
        'data.object.billing_details',
    ]);

    expect($allowed)->toBe([
        'id' => 'evt_1',
        'data' => [
            'object' => [
                'id' => 'pi_1', 'status' => 'succeeded',
                'payment_method_details' => ['card' => ['brand' => 'visa', 'last4' => '4242']],
                'charges' => [['id' => 'ch_1'], ['id' => 'ch_2']],
            ],
            'previous_attributes' => ['status', 'amount'],
        ],
    ]);
});

it('oculta números de tarjeta dentro de textos permitidos', function (): void {
    $allowed = SensitiveData::allow(['message' => 'Tarjeta 4242 4242 4242 4242 rechazada'], ['message']);

    expect($allowed['message'])->toBe('Tarjeta [número de tarjeta oculto] rechazada');
});

it('redacta secretos, tokens, firmas y datos de tarjeta en cualquier nivel', function (): void {
    $clean = SensitiveData::redact([
        'Authorization' => 'Bearer sk_live_x',
        'stripe-signature' => 't=1,v1=abc',
        'nested' => ['client_secret' => 'pi_secret', 'card_number' => '4000000000000002', 'cvc' => '123', 'company' => 'Fundación'],
        'note' => 'pagó con 5555555555554444',
        'amount' => 100,
    ]);

    expect($clean)->toBe([
        'Authorization' => SensitiveData::REDACTED,
        'stripe-signature' => SensitiveData::REDACTED,
        'nested' => ['client_secret' => SensitiveData::REDACTED, 'card_number' => SensitiveData::REDACTED, 'cvc' => SensitiveData::REDACTED, 'company' => 'Fundación'],
        'note' => 'pagó con [número de tarjeta oculto]',
        'amount' => 100,
    ]);
});

it('los logs nunca escriben secretos ni números de tarjeta', function (): void {
    $path = storage_path('logs/sanitization-test.log');
    @unlink($path);
    config(['logging.channels.sanitization_test' => [
        'driver' => 'single', 'path' => $path, 'level' => 'debug', 'replace_placeholders' => true,
        'tap' => [RedactSensitiveData::class],
    ]]);

    Log::channel('sanitization_test')->warning('Falla con tarjeta 4111111111111111 del cliente {cliente}', [
        'cliente' => 'demo',
        'headers' => ['authorization' => 'Bearer sk_test_supersecreto', 'x-signature' => 'ts=1,v1=deadbeef'],
        'webhook_secret' => 'whsec_123',
    ]);

    $written = (string) file_get_contents($path);
    @unlink($path);

    expect($written)->toContain('[número de tarjeta oculto]')
        ->and($written)->not->toContain('4111111111111111')
        ->and($written)->not->toContain('sk_test_supersecreto')
        ->and($written)->not->toContain('deadbeef')
        ->and($written)->not->toContain('whsec_123');
});

it('todos los canales de log configurados aplican la redacción', function (): void {
    foreach (['single', 'daily', 'stderr', 'syslog', 'errorlog'] as $channel) {
        expect(config("logging.channels.{$channel}.tap"))->toContain(RedactSensitiveData::class);
    }
});

it('las excepciones del proveedor no arrastran el mensaje original (que podría traer datos)', function (): void {
    $original = new RuntimeException('respuesta cruda con 4242424242424242 y sk_live_secreto');
    $exception = new PaymentProviderException(PaymentProvider::Fake, 'El proveedor no respondió.', null, $original);

    expect($exception->getMessage())->toBe('El proveedor no respondió.')
        ->and($exception->getPrevious()?->getMessage())->toBe('Excepción original: RuntimeException')
        ->and($exception->getPrevious()?->getMessage())->not->toContain('sk_live');
});

it('la configuración de pruebas nunca tiene credenciales reales', function (): void {
    expect(config('payments.providers.stripe.enabled'))->toBeFalse()
        ->and(config('payments.providers.stripe.secret_key'))->toBeEmpty()
        ->and(config('payments.providers.mercado_pago.enabled'))->toBeFalse()
        ->and(config('payments.providers.mercado_pago.access_token'))->toBeEmpty();
});
