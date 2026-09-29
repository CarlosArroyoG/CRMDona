<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SensitiveColumns;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Cifra datos sensibles de donantes que hayan quedado legibles (por ejemplo,
 * guardados por el contenedor anterior durante el despliegue del cifrado) y
 * completa las huellas de RFC. Se puede ejecutar las veces que sea: lo ya
 * cifrado no se toca. docs/tecnico/proteccion-de-datos.md §3.
 */
#[Signature('app:encrypt-legacy-data')]
#[Description('Cifra datos sensibles de donantes que quedaron legibles y completa las huellas de RFC')]
class EncryptLegacyDataCommand extends Command
{
    public function handle(): int
    {
        $fixed = SensitiveColumns::encryptPending();

        $this->components->info($fixed === 0
            ? 'No había datos legibles: todo estaba cifrado.'
            : "Se cifraron {$fixed} registro(s) que habían quedado legibles.");

        return self::SUCCESS;
    }
}
