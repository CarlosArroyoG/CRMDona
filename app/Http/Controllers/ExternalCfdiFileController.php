<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ExternalCfdi;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Descarga del XML o PDF de un CFDI externo desde el disco privado, solo con
 * permiso y sesión. Nunca hay URLs públicas permanentes a estos archivos.
 */
class ExternalCfdiFileController extends Controller
{
    public function __invoke(ExternalCfdi $externalCfdi, string $format): Response
    {
        Gate::authorize('download', $externalCfdi);

        $path = $format === 'xml' ? $externalCfdi->xml_path : $externalCfdi->pdf_path;
        $disk = Storage::disk('local');
        abort_if($path === null || ! $disk->exists($path), 404);

        return response((string) $disk->get($path), 200, [
            'Content-Type' => $format === 'xml' ? 'application/xml' : 'application/pdf',
            'Content-Disposition' => 'attachment; filename="CFDI-'.$externalCfdi->uuid.'.'.$format.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
