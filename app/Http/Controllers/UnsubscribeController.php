<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Communications\UnsubscribeDonor;
use App\Support\Branding;
use Illuminate\Contracts\View\View;

/**
 * Baja de comunicaciones sin iniciar sesión. GET solo muestra la
 * confirmación (los clientes de correo que abren enlaces por adelantado no
 * dan de baja a nadie); POST la aplica. No muestra datos del donante.
 */
class UnsubscribeController extends Controller
{
    public function show(string $token): View
    {
        abort_if(UnsubscribeDonor::findByToken($token) === null, 404);

        return view('communications.unsubscribe', ['token' => $token, 'done' => false, 'organization' => $this->organization()]);
    }

    public function store(string $token, UnsubscribeDonor $unsubscribe): View
    {
        abort_unless($unsubscribe->handle($token), 404);

        return view('communications.unsubscribe', ['token' => $token, 'done' => true, 'organization' => $this->organization()]);
    }

    private function organization(): string
    {
        return Branding::name();
    }
}
