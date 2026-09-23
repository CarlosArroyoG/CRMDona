<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public const string TESTING_DATABASE = 'crm_testing';

    /**
     * Se ejecuta antes de RefreshDatabase: si la configuración no apunta a
     * `crm_testing` (por ejemplo, porque una variable del contenedor ganó a
     * phpunit.xml), las pruebas se detienen sin tocar ninguna tabla.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits(): array
    {
        $connection = config()->string('database.default');
        $database = config()->string("database.connections.{$connection}.database");

        if ($database !== self::TESTING_DATABASE) {
            throw new RuntimeException('Las pruebas deben usar la base "'.self::TESTING_DATABASE."\", no \"{$database}\". Revisa phpunit.xml.");
        }

        $traits = parent::setUpTraits();

        // Recibos y CFDI se guardan en el disco privado: en pruebas, siempre simulado.
        Storage::fake('local');

        return $traits;
    }
}
