<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Cfdi\CfdiStorage;
use App\Models\Cfdi;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Descarga del XML o PDF de un CFDI desde el disco privado, solo con
 * permiso. Nunca hay enlaces públicos a estos archivos.
 */
class CfdiFileController extends Controller
{
    public function __invoke(Cfdi $cfdi, string $format, CfdiStorage $storage): Response
    {
        Gate::authorize('download', $cfdi);

        $path = match ($format) {
            'xml' => $cfdi->xml_path,
            'pdf' => $cfdi->pdf_path,
            default => null,
        };
        $content = $path !== null ? $storage->read($path) : null;
        abort_if($content === null, 404);

        return response($content, 200, [
            'Content-Type' => $format === 'xml' ? 'application/xml' : 'application/pdf',
            'Content-Disposition' => 'attachment; filename="CFDI-'.$cfdi->uuid.'.'.$format.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
