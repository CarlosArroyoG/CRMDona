<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Con contraseña temporal el usuario solo puede cambiarla o cerrar sesión.
 * Se registra como middleware persistente del panel: también aplica a las
 * peticiones Livewire (acciones, tablas), no solo a la navegación.
 * Una contraseña temporal vencida no da acceso: se cierra la sesión.
 */
class EnsurePasswordIsCurrent
{
    /** Rutas permitidas mientras la contraseña sea temporal. */
    private const array ALLOWED_ROUTES = [
        'filament.admin.auth.profile',
        'filament.admin.auth.logout',
    ];

    public const string EXPIRED_MESSAGE = 'Tu contraseña temporal venció. Pide al Administrador un nuevo restablecimiento.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->mustChangePassword()) {
            return $next($request);
        }

        if ($user->temporaryPasswordExpired()) {
            Auth::guard(Filament::getAuthGuard())->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            Notification::make()->danger()->title(self::EXPIRED_MESSAGE)->persistent()->send();

            return redirect()->to(Filament::getLoginUrl() ?? '/');
        }

        if (in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        return redirect()->to(Filament::getProfileUrl() ?? '/');
    }
}
