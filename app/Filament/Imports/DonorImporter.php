<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Actions\Donors\ImportDonorRow;
use App\Models\Donor;
use App\Models\User;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Number;

/**
 * Carga masiva de donantes por CSV (docs/tecnico/carga-y-envios-masivos.md).
 * Filament lee el archivo (detecta la codificación de Excel y el separador),
 * lo divide en bloques y los procesa en la cola. Cada fila la registra
 * ImportDonorRow: aquí no hay reglas de negocio.
 *
 * Los encabezados se reconocen en español y también los de la exportación de
 * donantes, para poder corregir un archivo exportado y volver a cargarlo.
 */
class DonorImporter extends Importer
{
    protected static ?string $model = Donor::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('type')->label('Tipo de persona')->exampleHeader('tipo_persona')
                ->guess(['tipo_persona', 'tipo', 'persona'])->examples(['física', 'moral', ''])
                ->helperText('"física" o "moral". Vacío = física.'),
            ImportColumn::make('first_name')->label('Nombre(s)')->exampleHeader('nombre')
                ->guess(['nombre', 'nombres'])->examples(['María', '', 'José']),
            ImportColumn::make('last_name')->label('Apellido paterno')->exampleHeader('apellido_paterno')
                ->guess(['apellido_paterno', 'apellido', 'primer apellido'])->examples(['López', '', 'Pérez']),
            ImportColumn::make('second_last_name')->label('Apellido materno')->exampleHeader('apellido_materno')
                ->guess(['apellido_materno', 'segundo apellido'])->examples(['Hernández', '', '']),
            ImportColumn::make('legal_name')->label('Razón social')->exampleHeader('razon_social')
                ->guess(['razon_social', 'razón social', 'empresa', 'organización', 'organizacion'])->examples(['', 'Aceros del Sur S.A. de C.V.', '']),
            ImportColumn::make('contact_name')->label('Persona de contacto')->exampleHeader('persona_contacto')
                ->guess(['persona_contacto', 'contacto'])->examples(['', 'Luis Mora', '']),
            ImportColumn::make('email')->label('Correo electrónico')->exampleHeader('correo')
                ->guess(['correo', 'correo electronico', 'email', 'e-mail', 'mail'])->examples(['maria.lopez@example.com', 'contacto@example.org', '']),
            ImportColumn::make('phone')->label('Teléfono')->exampleHeader('telefono')
                ->guess(['telefono', 'teléfono', 'celular', 'tel'])->examples(['777 123 4567', '55 1234 5678', '']),
            ImportColumn::make('birth_date')->label('Fecha de nacimiento')->exampleHeader('fecha_nacimiento')
                ->guess(['fecha_nacimiento', 'nacimiento', 'cumpleaños', 'fecha de nacimiento'])->examples(['15/03/1980', '', ''])
                ->helperText('dd/mm/aaaa'),
            ImportColumn::make('tags')->label('Etiquetas')->exampleHeader('etiquetas')
                ->guess(['etiquetas', 'etiqueta'])->examples(['Padrino; Evento 2026', 'Empresa', ''])
                ->helperText('Separadas por punto y coma. Las que no existan se crean.'),
            ImportColumn::make('notes')->label('Notas')->exampleHeader('notas')
                ->guess(['notas', 'observaciones'])->examples(['Conocida en la kermés 2026', '', '']),
            ImportColumn::make('accepts_communications')->label('Acepta comunicaciones')->exampleHeader('acepta_comunicaciones')
                ->guess(['acepta_comunicaciones', 'consentimiento'])->examples(['sí', 'no', ''])
                ->helperText('"sí" o "no". Solo cuenta si confirmas el consentimiento abajo.'),
        ];
    }

    /**
     * Plantilla para llenar en Excel: encabezados y tres filas de ejemplo
     * (persona física completa, persona moral y una fila mínima). Lleva BOM
     * UTF-8 para que Excel muestre bien los acentos.
     */
    public static function templateCsv(): string
    {
        $columns = self::getColumns();
        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);

        fwrite($handle, "\u{FEFF}");
        fputcsv($handle, array_map(fn (ImportColumn $column): string => $column->getExampleHeader(), $columns), escape: '');

        $rows = max(0, ...array_map(fn (ImportColumn $column): int => count($column->getExamples()), $columns));
        for ($i = 0; $i < $rows; $i++) {
            fputcsv($handle, array_map(fn (ImportColumn $column): string => (string) ($column->getExamples()[$i] ?? ''), $columns), escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public static function getOptionsFormComponents(): array
    {
        return [
            TextInput::make('batch_tag')->label('Etiqueta para toda la carga (opcional)')->maxLength(50)
                ->placeholder('Ej. Carga septiembre 2026')
                ->helperText('Se agrega a cada donante importado; sirve para encontrarlos o elegirlos en un envío masivo.'),
            Checkbox::make('consent_confirmed')->label('Confirmo que la Fundación tiene evidencia del consentimiento de comunicaciones de los donantes marcados "sí" en el archivo.')
                ->helperText('Sin esta confirmación, todos se registran sin aceptar comunicaciones y no recibirán envíos masivos ni felicitaciones.'),
        ];
    }

    public function resolveRecord(): Donor
    {
        // Solo altas: un donante existente nunca se modifica desde un archivo.
        return new Donor;
    }

    /**
     * Las reglas viven en SaveDonor (vía ImportDonorRow): mismas validaciones y
     * mensajes que el alta manual.
     */
    public function validateData(): void {}

    public function fillRecord(): void {}

    public function saveRecord(): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        // Solo las columnas mapeadas: un encabezado del CSV sin mapear nunca se usa.
        $row = [];
        foreach ($this->getCachedColumns() as $column) {
            $name = $column->getName();
            if (filled($this->columnMap[$name] ?? null)) {
                $row[$name] = $this->data[$name] ?? null;
            }
        }

        $this->record = app(ImportDonorRow::class)->handle(
            $row,
            $actor,
            consentConfirmed: (bool) ($this->options['consent_confirmed'] ?? false),
            batchTag: is_string($this->options['batch_tag'] ?? null) ? $this->options['batch_tag'] : null,
        );
    }

    public static function getCompletedNotificationTitle(Import $import): string
    {
        return 'Carga de donantes terminada';
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = Number::format($import->successful_rows).' '.($import->successful_rows === 1 ? 'donante registrado' : 'donantes registrados').'.';

        $failed = $import->getFailedRowsCount();
        if ($failed > 0) {
            $body .= ' '.Number::format($failed).' '.($failed === 1 ? 'fila no se importó' : 'filas no se importaron')
                .': descarga el archivo con el motivo de cada una (disponible 7 días).';
        }

        return $body;
    }
}
