<?php

declare(strict_types=1);

namespace App\Cfdi;

use App\Models\Cfdi;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * XML y PDF de CFDI en el disco privado (nunca en `public`). Se descargan
 * solo a través de CfdiFileController con permiso.
 */
final class CfdiStorage
{
    /**
     * @return array{xml: string, pdf: string|null}
     */
    public function store(Cfdi $cfdi, string $uuid, string $xml, ?string $pdf): array
    {
        $base = 'cfdi/'.now()->format('Y/m').'/'.$uuid;
        $this->disk()->put("{$base}.xml", $xml) || throw new RuntimeException('No se pudo guardar el XML del CFDI.');

        $pdfPath = null;
        if ($pdf !== null) {
            $this->disk()->put("{$base}.pdf", $pdf) || throw new RuntimeException('No se pudo guardar el PDF del CFDI.');
            $pdfPath = "{$base}.pdf";
        }

        return ['xml' => "{$base}.xml", 'pdf' => $pdfPath];
    }

    public function read(string $path): ?string
    {
        return $this->disk()->exists($path) ? $this->disk()->get($path) : null;
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config()->string('cfdi.disk'));
    }
}
