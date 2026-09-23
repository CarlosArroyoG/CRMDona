<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\Permission;
use App\Support\OperationalAlerts;
use App\Support\SensitiveData;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;

/**
 * Un Job que agotó sus intentos (Fase 7): log `critical` estructurado (clase,
 * cola, intentos y tipo de error; nunca el payload) y aviso a los
 * Administradores, uno por clase de Job y hora. Los fallos de dominio
 * (webhooks, pagos, CFDI) ya tienen su incidencia o alerta propia; esto cubre
 * además cualquier Job que falle. `php artisan queue:failed` conserva el detalle.
 */
class ReportFailedJob
{
    public function handle(JobFailed $event): void
    {
        $name = $event->job->resolveName();
        $short = class_basename($name);

        Log::critical('Job fallido después de sus reintentos.', [
            'job' => $name,
            'queue' => $event->job->getQueue(),
            'connection' => $event->connectionName,
            'attempts' => $event->job->attempts(),
            'exception' => $event->exception::class,
            'message' => SensitiveData::safeText($event->exception->getMessage(), 300),
        ]);

        rescue(fn () => OperationalAlerts::send(
            'job-failed:'.$short.':'.now()->format('Y-m-d-H'),
            Permission::ViewWebhooks,
            "Proceso automático fallido: {$short}",
            ['Un proceso en segundo plano agotó sus reintentos.', 'Detalle técnico: php artisan queue:failed en el servidor.'],
        ), report: false);
    }
}
