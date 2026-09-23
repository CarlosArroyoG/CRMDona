<?php

declare(strict_types=1);

namespace App\Actions\Cfdi;

use App\Enums\CfdiStatus;
use App\Enums\Permission;
use App\Jobs\StampCfdi;
use App\Models\Cfdi;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reintenta un CFDI con error temporal o rechazado (después de corregir los
 * datos). Usa la misma llave: el PAC no duplica el timbrado.
 */
class RetryCfdi
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Cfdi $cfdi, User $actor): Cfdi
    {
        if (! $actor->hasPermission(Permission::IssueCfdis)) {
            throw new AuthorizationException('No tienes permiso para emitir CFDI.');
        }

        $updated = DB::table('cfdis')->where('id', $cfdi->id)
            ->whereIn('status', [CfdiStatus::Failed->value, CfdiStatus::Rejected->value])
            ->update(['status' => CfdiStatus::Pending->value, 'updated_at' => now()]);

        if ($updated !== 1) {
            throw ValidationException::withMessages(['cfdi' => 'Solo se reintentan CFDI con error o rechazados.']);
        }

        StampCfdi::dispatch($cfdi->id);

        return $cfdi->refresh();
    }
}
