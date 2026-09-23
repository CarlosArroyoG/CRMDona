<?php

declare(strict_types=1);

namespace App\Cfdi\Exceptions;

use RuntimeException;

/**
 * El donativo todavía no puede tener CFDI: faltan datos o la regla fiscal
 * aplicable está pendiente de decisión [F]. Lista los motivos en español.
 */
final class CfdiNotReadyException extends RuntimeException
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }
}
