<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Export;
use App\Models\User;

/**
 * Un archivo exportado solo lo descarga quien lo generó, y solo mientras su
 * usuario siga activo.
 */
class ExportPolicy
{
    public function view(User $user, Export $export): bool
    {
        return $user->isActive() && $export->user_id === $user->id;
    }
}
