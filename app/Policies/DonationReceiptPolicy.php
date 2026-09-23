<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\DonationReceipt;
use App\Models\User;

/**
 * Recibo simple: consultar y descargar (Administrador, Coordinador, Contador).
 * Solo lectura no descarga documentos a nombre del donante. Nadie los edita
 * ni borra.
 */
class DonationReceiptPolicy
{
    public function view(User $user, DonationReceipt $receipt): bool
    {
        return $user->hasPermission(Permission::ViewDonationReceipts);
    }

    public function download(User $user, DonationReceipt $receipt): bool
    {
        return $user->hasPermission(Permission::ViewDonationReceipts) && $receipt->pdf_path !== null;
    }
}
