<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;
use Stripe\Util\CaseInsensitiveArray;

/**
 * Cliente HTTP del SDK de Stripe para pruebas: nunca sale a Internet.
 * Responde según método y ruta con respuestas registradas y guarda cada
 * petición (encabezados y parámetros) para verificarla.
 */
final class FakeStripeHttpClient implements ClientInterface
{
    /** @var array<string, list<array{status: int, body: array<string, mixed>}>> */
    private array $responses = [];

    /** @var list<array{method: string, path: string, headers: array<int, string>, params: array<string, mixed>}> */
    public array $requests = [];

    private bool $offline = false;

    /**
     * @param  array<string, mixed>  $body
     */
    public function respond(string $method, string $path, array $body, int $status = 200): self
    {
        $this->responses[strtoupper($method).' '.$path][] = ['status' => $status, 'body' => $body];

        return $this;
    }

    public function goOffline(): self
    {
        $this->offline = true;

        return $this;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<string, mixed>  $params
     * @return array{0: string, 1: int, 2: CaseInsensitiveArray}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $this->requests[] = ['method' => strtoupper($method), 'path' => $path, 'headers' => $headers, 'params' => $params];

        if ($this->offline) {
            throw new ApiConnectionException('Sin conexión (simulado).');
        }

        $key = strtoupper($method).' '.$path;
        $queue = $this->responses[$key] ?? [];
        if ($queue === []) {
            throw new RuntimeException("Sin respuesta registrada para {$key}");
        }

        // La última respuesta registrada se repite para llamadas posteriores.
        $response = $queue[0];
        if (count($queue) > 1) {
            array_shift($queue);
            $this->responses[$key] = $queue;
        }

        return [json_encode($response['body'], JSON_THROW_ON_ERROR), $response['status'], new CaseInsensitiveArray([])];
    }

    /**
     * @return list<array{method: string, path: string, headers: array<int, string>, params: array<string, mixed>}>
     */
    public function requestsTo(string $method, string $path): array
    {
        return array_values(array_filter($this->requests, fn (array $r): bool => $r['method'] === strtoupper($method) && $r['path'] === $path));
    }

    /**
     * @param  array{headers: array<int, string>}  $request
     */
    public static function header(array $request, string $name): ?string
    {
        foreach ($request['headers'] as $header) {
            [$key, $value] = array_pad(explode(':', $header, 2), 2, '');
            if (strcasecmp(trim($key), $name) === 0) {
                return trim($value);
            }
        }

        return null;
    }
}
