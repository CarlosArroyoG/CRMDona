<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Branding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Favicon derivado del logotipo institucional (sin campo aparte). Sin logo
 * configurado, el ícono genérico del sitio.
 */
class FaviconController extends Controller
{
    public function __invoke(): Response|RedirectResponse
    {
        $path = Branding::faviconPath();
        if ($path === null) {
            return redirect()->to(asset('favicon.ico'));
        }

        return response((string) Storage::disk('public')->get($path), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
