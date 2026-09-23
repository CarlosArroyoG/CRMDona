<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DonationReceipt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Descarga del PDF del recibo simple desde el disco privado, solo con
 * permiso. Nunca hay enlaces públicos a estos archivos.
 */
class DonationReceiptFileController extends Controller
{
    public function __invoke(DonationReceipt $receipt): Response
    {
        Gate::authorize('download', $receipt);

        $disk = Storage::disk(config()->string('communications.disk'));
        $content = $receipt->pdf_path !== null && $disk->exists($receipt->pdf_path) ? $disk->get($receipt->pdf_path) : null;
        abort_if($content === null, 404);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Recibo-'.$receipt->folio.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
