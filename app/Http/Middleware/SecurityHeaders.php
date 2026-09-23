<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad para todas las respuestas (Fase 7).
 *
 * CSP en dos niveles:
 * - Página pública y demás rutas propias: estricta, con nonce para los
 *   scripts y estilos en línea, y solo los orígenes de los proveedores de
 *   pago. Sin 'unsafe-eval'.
 * - Panel (Filament/Livewire): Filament usa expresiones de Alpine que exigen
 *   evaluar código, y su build "CSP" no las admite. Por eso el panel aplica
 *   las directivas que no rompen (frame-ancestors, object-src, base-uri,
 *   form-action) y envía la política de scripts estricta solo como
 *   Report-Only. Nunca se declara 'unsafe-eval'.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), usb=(), payment=(self "https://js.stripe.com" "https://checkout.stripe.com")');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');

        if (config('security.hsts.enabled') && app()->isProduction() && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age='.config()->integer('security.hsts.max_age'));
        }

        $mode = config('security.csp.mode');
        if ($mode === 'off') {
            return $response;
        }

        $strict = $this->strictPolicy($nonce);

        if ($this->isPanel($request)) {
            $headers->set('Content-Security-Policy', "frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'");
            $headers->set('Content-Security-Policy-Report-Only', $strict);

            return $response;
        }

        $headers->set($mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only', $strict);

        return $response;
    }

    private function strictPolicy(string $nonce): string
    {
        /** @var array<string, list<string>> $sources */
        $sources = config()->array('security.csp.payment_sources');
        $join = fn (string $key): string => implode(' ', $sources[$key] ?? []);

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' {$join('script')}",
            "style-src 'self' 'nonce-{$nonce}'",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data: {$join('img')}",
            "font-src 'self' data:",
            "connect-src 'self' {$join('connect')}",
            "frame-src {$join('frame')}",
            "frame-ancestors 'self'",
            "form-action 'self' {$join('frame')}",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }

    private function isPanel(Request $request): bool
    {
        return $request->is('admin', 'admin/*', 'livewire', 'livewire/*', 'livewire-*', 'filament/*');
    }
}
