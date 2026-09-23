<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AuditSource;

/**
 * Procedencia de los cambios que se registran en la bitácora
 * (audit_logs.source). Instancia "scoped": se reinicia en cada petición y en
 * cada Job de la cola.
 *
 * Prioridad: la procedencia indicada explícitamente con run() (webhook,
 * sincronización), luego "Job" si se ejecuta dentro de la cola, luego
 * "Usuario" si hay sesión y "Consola" en comandos de Artisan.
 */
final class AuditOrigin
{
    private ?AuditSource $override = null;

    private bool $insideQueuedJob = false;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(AuditSource $source, callable $callback): mixed
    {
        $previous = $this->override;
        $this->override = $source;

        try {
            return $callback();
        } finally {
            $this->override = $previous;
        }
    }

    public function enterQueuedJob(): void
    {
        $this->insideQueuedJob = true;
    }

    public function leaveQueuedJob(): void
    {
        $this->insideQueuedJob = false;
    }

    public function current(): ?AuditSource
    {
        return match (true) {
            $this->override !== null => $this->override,
            $this->insideQueuedJob => AuditSource::Job,
            auth()->check() => AuditSource::User,
            app()->runningInConsole() => AuditSource::Console,
            default => null,
        };
    }
}
