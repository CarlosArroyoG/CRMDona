<?php

declare(strict_types=1);

use App\Support\BlindIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cifrado de datos sensibles de donantes (docs/tecnico/proteccion-de-datos.md):
 * RFC, nombre fiscal, régimen, CP fiscal y uso de CFDI; teléfono y notas.
 * Se cifran con APP_KEY: una copia robada de la base no los revela.
 *
 * - Las columnas pasan a `text` (el texto cifrado es más largo).
 * - `rfc_hash`: huella HMAC del RFC para buscar duplicados sin descifrar; el
 *   índice sobre el RFC legible se elimina.
 * - Los datos existentes se cifran aquí mismo. La reversión los descifra.
 */
return new class extends Migration
{
    private const array TAX_COLUMNS = ['rfc', 'tax_name', 'tax_regime', 'tax_postal_code', 'cfdi_use'];

    private const array DONOR_COLUMNS = ['phone', 'notes'];

    public function up(): void
    {
        Schema::table('donor_tax_profiles', function (Blueprint $table): void {
            $table->dropIndex(['rfc']);
            $table->char('rfc_hash', 64)->nullable();
        });
        foreach (self::TAX_COLUMNS as $column) {
            DB::statement("alter table donor_tax_profiles alter column {$column} type text");
        }
        DB::statement('alter table donors alter column phone type text');

        DB::table('donor_tax_profiles')->orderBy('id')->lazyById()->each(function (object $row): void {
            $values = ['rfc_hash' => BlindIndex::rfc((string) $row->rfc)];
            foreach (self::TAX_COLUMNS as $column) {
                $values[$column] = $row->{$column} !== null ? Crypt::encryptString((string) $row->{$column}) : null;
            }
            DB::table('donor_tax_profiles')->where('id', $row->id)->update($values);
        });

        $this->transformDonors(fn (string $value): string => Crypt::encryptString($value));

        // rfc_hash admite nulo: durante el despliegue el contenedor anterior aún puede
        // guardar perfiles sin huella; `app:encrypt-legacy-data` los completa.
        Schema::table('donor_tax_profiles', function (Blueprint $table): void {
            $table->index('rfc_hash');
        });
    }

    public function down(): void
    {
        DB::table('donor_tax_profiles')->orderBy('id')->lazyById()->each(function (object $row): void {
            $values = [];
            foreach (self::TAX_COLUMNS as $column) {
                $values[$column] = $row->{$column} !== null ? Crypt::decryptString((string) $row->{$column}) : null;
            }
            DB::table('donor_tax_profiles')->where('id', $row->id)->update($values);
        });

        $this->transformDonors(fn (string $value): string => Crypt::decryptString($value));

        Schema::table('donor_tax_profiles', function (Blueprint $table): void {
            $table->dropIndex(['rfc_hash']);
            $table->dropColumn('rfc_hash');
        });
        foreach (['rfc' => 13, 'tax_regime' => 3, 'tax_postal_code' => 5, 'cfdi_use' => 4] as $column => $length) {
            DB::statement("alter table donor_tax_profiles alter column {$column} type varchar({$length})");
        }
        DB::statement('alter table donor_tax_profiles alter column tax_name type varchar(255)');
        DB::statement('alter table donors alter column phone type varchar(30)');
        Schema::table('donor_tax_profiles', function (Blueprint $table): void {
            $table->index('rfc');
        });
    }

    /**
     * Cifra o descifra teléfono y notas. `donors` no tiene triggers que lo
     * impidan y el cambio no pasa por la bitácora: es una transformación de
     * almacenamiento, no un cambio de datos.
     *
     * @param  callable(string): string  $transform
     */
    private function transformDonors(callable $transform): void
    {
        DB::table('donors')->where(fn ($query) => $query->whereNotNull('phone')->orWhereNotNull('notes'))
            ->orderBy('id')->lazyById()->each(function (object $row) use ($transform): void {
                $values = [];
                foreach (self::DONOR_COLUMNS as $column) {
                    $values[$column] = $row->{$column} !== null ? $transform((string) $row->{$column}) : null;
                }
                DB::table('donors')->where('id', $row->id)->update($values);
            });
    }
};
