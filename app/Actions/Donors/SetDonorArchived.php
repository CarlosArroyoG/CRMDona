<?php

declare(strict_types=1);

namespace App\Actions\Donors;

use App\Enums\AuditEvent;
use App\Models\Donor;

/**
 * Archivar oculta al donante de los listados habituales sin perder nada.
 */
class SetDonorArchived
{
    public function handle(Donor $donor, bool $archived): Donor
    {
        if ($donor->isArchived() === $archived) {
            return $donor;
        }

        $donor->auditAs($archived ? AuditEvent::Archived : AuditEvent::Unarchived)
            ->forceFill(['archived_at' => $archived ? now() : null])
            ->save();

        return $donor;
    }
}
