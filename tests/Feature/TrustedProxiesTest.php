<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\withServerVariables;

beforeEach(function (): void {
    Route::get('/_prueba-proxy', fn (Request $request): array => [
        'secure' => $request->isSecure(),
        'url' => url('/admin'),
        'ip' => $request->ip(),
        'host' => $request->getHost(),
    ]);
});

it('genera URLs HTTPS cuando el proxy de Coolify indica HTTPS', function (): void {
    withServerVariables(['REMOTE_ADDR' => '10.0.1.5'])
        ->get('/_prueba-proxy', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'])
        ->assertJsonPath('secure', true)
        ->assertJsonPath('ip', '203.0.113.7')
        ->assertJsonPath('url', fn (string $url): bool => str_starts_with($url, 'https://'));
});

it('ignora X-Forwarded-Host aunque venga del proxy', function (): void {
    withServerVariables(['REMOTE_ADDR' => '10.0.1.5'])
        ->get('/_prueba-proxy', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'atacante.example'])
        ->assertJsonPath('host', 'localhost');
});

it('ignora las cabeceras de proxy si la petición no viene de una red privada', function (): void {
    withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->get('/_prueba-proxy', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '198.51.100.1'])
        ->assertJsonPath('secure', false)
        ->assertJsonPath('ip', '203.0.113.50');
});
