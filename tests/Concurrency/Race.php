<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Ejecuta dos procesos PHP reales contra PostgreSQL (crm_testing) que
 * arrancan la misma operación al mismo tiempo: el proceso de la prueba
 * retiene un candado consultivo y lo libera solo cuando ambos esperan.
 */
final class Race
{
    private const int BARRIER = 424242;

    /**
     * @param  array<string, mixed>  $argsA
     * @param  array<string, mixed>  $argsB
     * @return array{0: array{ok: bool, result: mixed, error: string|null, message: string|null}, 1: array{ok: bool, result: mixed, error: string|null, message: string|null}}
     */
    public static function run(string $scenario, array $argsA, array $argsB): array
    {
        DB::select('select pg_advisory_lock(?)', [self::BARRIER]);

        try {
            $pool = Process::pool(fn (Pool $pool) => [
                $pool->as('a')->path(base_path())->env(self::env())->timeout(60)
                    ->command(['php', 'tests/Support/race.php', $scenario, json_encode($argsA, JSON_THROW_ON_ERROR), (string) self::BARRIER]),
                $pool->as('b')->path(base_path())->env(self::env())->timeout(60)
                    ->command(['php', 'tests/Support/race.php', $scenario, json_encode($argsB, JSON_THROW_ON_ERROR), (string) self::BARRIER]),
            ])->start();

            self::waitUntilBothAreBlocked();
        } finally {
            DB::select('select pg_advisory_unlock(?)', [self::BARRIER]);
        }

        $results = $pool->wait()->collect();

        return [self::decode($results->get('a')), self::decode($results->get('b'))];
    }

    /**
     * @return array<string, string>
     */
    private static function env(): array
    {
        return [
            'APP_ENV' => 'testing',
            'DB_DATABASE' => 'crm_testing',
            'DB_URL' => '',
            'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
            'BCRYPT_ROUNDS' => '4',
            'PAYMENTS_FAKE_ENABLED' => 'true',
            'PAYMENTS_FAKE_STORE' => 'file',
            'STRIPE_ENABLED' => 'false',
            'MERCADO_PAGO_ENABLED' => 'false',
        ];
    }

    private static function waitUntilBothAreBlocked(): void
    {
        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            $waiting = DB::selectOne(
                "select count(*) as waiting from pg_locks where locktype = 'advisory' and objid = ? and not granted",
                [self::BARRIER],
            );

            if ((int) ($waiting->waiting ?? 0) >= 2) {
                return;
            }

            usleep(20_000);
        }

        throw new RuntimeException('Los dos procesos no llegaron a la barrera.');
    }

    /**
     * @return array{ok: bool, result: mixed, error: string|null, message: string|null}
     */
    private static function decode(?ProcessResult $process): array
    {
        $output = $process?->output() ?? '';
        $lines = array_values(array_filter(explode(PHP_EOL, trim($output))));
        $decoded = json_decode((string) end($lines), true);

        if (! is_array($decoded) || ! array_key_exists('ok', $decoded)) {
            throw new RuntimeException('Salida inesperada del proceso: '.$output.' '.($process?->errorOutput() ?? ''));
        }

        return [
            'ok' => (bool) $decoded['ok'],
            'result' => $decoded['result'] ?? null,
            'error' => isset($decoded['error']) && is_string($decoded['error']) ? $decoded['error'] : null,
            'message' => isset($decoded['message']) && is_string($decoded['message']) ? $decoded['message'] : null,
        ];
    }
}
