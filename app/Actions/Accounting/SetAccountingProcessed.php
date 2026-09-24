<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Enums\AccountingNoticeStatus;
use App\Enums\DonationStatus;
use App\Enums\Permission;
use App\Models\AccountingNotice;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contabilidad marca un donativo como procesado (ya decidió y realizó fuera
 * del CRM su tratamiento fiscal) o lo reabre con motivo. El CRM no decide
 * ese tratamiento: solo registra quién lo marcó, cuándo y la nota.
 */
class SetAccountingProcessed
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Donation $donation, bool $processed, ?string $note, User $actor): AccountingNotice
    {
        if (! $actor->hasPermission(Permission::ProcessAccounting)) {
            throw new AuthorizationException('No tienes permiso para el procesamiento contable.');
        }

        if ($donation->status !== DonationStatus::Confirmed) {
            throw ValidationException::withMessages(['note' => 'Solo se procesan donativos confirmados.']);
        }

        $note = $note !== null ? trim($note) : null;
        if (! $processed && ($note === null || mb_strlen($note) < 5)) {
            throw ValidationException::withMessages(['note' => 'Indica el motivo para reabrir (mínimo 5 caracteres).']);
        }
        if ($note !== null && mb_strlen($note) > 1000) {
            throw ValidationException::withMessages(['note' => 'La nota admite hasta 1000 caracteres.']);
        }

        return DB::transaction(function () use ($donation, $processed, $note, $actor): AccountingNotice {
            // Un donativo sin aviso registrado (si falló al registrarse) también se puede procesar.
            AccountingNotice::query()->insertOrIgnore([
                'donation_id' => $donation->id,
                'status' => AccountingNoticeStatus::Skipped->value,
                'skip_reason' => 'Sin aviso registrado; procesado directamente en Control contable.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $notice = AccountingNotice::query()->lockForUpdate()->where('donation_id', $donation->id)->firstOrFail();

            if ($notice->isProcessed() === $processed) {
                throw ValidationException::withMessages(['note' => $processed ? 'El donativo ya estaba procesado.' : 'El donativo ya estaba pendiente.']);
            }

            $notice->forceFill([
                'processed_at' => $processed ? now() : null,
                'processed_by_id' => $processed ? $actor->id : null,
                'processing_note' => $note === '' ? null : $note,
            ])->save();

            return $notice;
        });
    }
}
