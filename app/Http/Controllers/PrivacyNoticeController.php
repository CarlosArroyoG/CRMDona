<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OrganizationSetting;
use App\Support\Branding;
use Illuminate\Contracts\View\View;

/**
 * Aviso de privacidad que publica el CRM. Describe lo que el sistema hace
 * realmente con los datos (docs/privacidad/aviso-privacidad-contenido-funcional.md);
 * el domicilio y el correo de privacidad los captura Administración.
 */
class PrivacyNoticeController extends Controller
{
    public function __invoke(): View
    {
        $settings = OrganizationSetting::current();

        return view('public.privacy', Branding::publicLayout() + [
            'rfc' => $settings->rfc,
            'address' => $settings->privacy_address,
            'contactEmail' => $settings->privacy_contact_email,
            'version' => $settings->privacy_notice_version,
            'publishedByCrm' => $settings->privacy_notice_url === route('privacy.notice'),
        ]);
    }
}
