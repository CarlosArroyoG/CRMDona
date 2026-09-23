<?php

declare(strict_types=1);

namespace App\Logging;

use App\Support\SensitiveData;
use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;

/**
 * Se aplica a todos los canales de log ("tap" en config/logging.php): oculta
 * secretos, tokens, firmas y números de tarjeta del mensaje y del contexto
 * antes de escribir (fase-2-diseno-pagos.md §18.2).
 */
final class RedactSensitiveData
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(
                message: SensitiveData::maskCardNumbers($record->message),
                context: SensitiveData::redact($record->context),
                extra: SensitiveData::redact($record->extra),
            ));
        }
    }
}
