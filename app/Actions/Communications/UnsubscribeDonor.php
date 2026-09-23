<?php

declare(strict_types=1);

namespace App\Actions\Communications;

use App\Enums\AuditSource;
use App\Models\Donor;
use App\Support\AuditOrigin;
use Illuminate\Support\Facades\DB;

/**
 * Baja de comunicaciones desde el enlace del correo, sin sesión. El token es
 * aleatorio (256 bits) y único por donante: una URL alterada no encuentra a
 * otro donante. Queda en la bitácora con procedencia "Donante" y sin datos
 * personales (solo el cambio de consentimiento). Idempotente.
 */
class UnsubscribeDonor
{
    public function __construct(private readonly AuditOrigin $origin) {}

    public static function findByToken(string $token): ?Donor
    {
        return preg_match('/^[a-f0-9]{64}$/', $token) === 1
            ? Donor::query()->where('communications_token', $token)->first()
            : null;
    }

    public function handle(string $token): bool
    {
        $donor = self::findByToken($token);
        if ($donor === null) {
            return false;
        }

        $this->origin->run(AuditSource::Donor, fn () => DB::transaction(function () use ($donor): void {
            $locked = Donor::query()->lockForUpdate()->findOrFail($donor->id);
            if ($locked->accepts_communications) {
                $locked->forceFill(['accepts_communications' => false, 'communications_consent_updated_at' => now()])->save();
            }
        }));

        return true;
    }
}
