<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Columnas de donantes que se guardan cifradas con APP_KEY
 * (docs/tecnico/proteccion-de-datos.md §3) y reparación de las que hayan
 * quedado legibles: por ejemplo, un guardado del contenedor anterior durante
 * el despliegue que introdujo el cifrado.
 *
 * Un valor cifrado por Laravel es base64 de un JSON que empieza con
 * {"iv": su prefijo es siempre "eyJpdiI6". Ningún RFC, teléfono o nota
 * legible empieza así.
 */
final class SensitiveColumns
{
    public const string CIPHER_PREFIX = 'eyJpdiI6';

    /** @var array<string, list<string>> */
    public const array COLUMNS = [
        'donor_tax_profiles' => ['rfc', 'tax_name', 'tax_regime', 'tax_postal_code', 'cfdi_use'],
        'donors' => ['phone', 'notes'],
    ];

    /**
     * Filas con algún dato legible (o perfil fiscal sin huella de RFC).
     */
    public static function pendingCount(): int
    {
        $total = 0;
        foreach (array_keys(self::COLUMNS) as $table) {
            $total += self::pending($table)->count();
        }

        return $total;
    }

    /**
     * Cifra lo que quedó legible y completa las huellas. Devuelve cuántas filas corrigió.
     */
    public static function encryptPending(): int
    {
        $fixed = 0;
        foreach (self::COLUMNS as $table => $columns) {
            self::pending($table)->orderBy('id')->lazyById()->each(function (object $row) use ($table, $columns, &$fixed): void {
                $values = [];
                foreach ($columns as $column) {
                    $value = $row->{$column};
                    if ($value !== null && ! str_starts_with((string) $value, self::CIPHER_PREFIX)) {
                        $values[$column] = Crypt::encryptString((string) $value);
                    }
                }

                if ($table === 'donor_tax_profiles') {
                    $rfc = str_starts_with((string) $row->rfc, self::CIPHER_PREFIX) ? Crypt::decryptString((string) $row->rfc) : (string) $row->rfc;
                    $values['rfc_hash'] = BlindIndex::rfc($rfc);
                }

                if ($values !== []) {
                    DB::table($table)->where('id', $row->id)->update($values);
                    $fixed++;
                }
            });
        }

        return $fixed;
    }

    private static function pending(string $table): Builder
    {
        return DB::table($table)->where(function (Builder $query) use ($table): void {
            foreach (self::COLUMNS[$table] as $column) {
                $query->orWhere(fn (Builder $inner) => $inner->whereNotNull($column)->where($column, 'not like', self::CIPHER_PREFIX.'%'));
            }
            if ($table === 'donor_tax_profiles') {
                $query->orWhereNull('rfc_hash');
            }
        });
    }
}
