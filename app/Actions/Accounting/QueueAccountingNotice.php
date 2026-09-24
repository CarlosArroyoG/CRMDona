<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Enums\AccountingNoticeStatus;
use App\Enums\DonationStatus;
use App\Jobs\SendAccountingNotice;
use App\Models\AccountingNotice;
use App\Models\Donation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Registra el aviso a Contabilidad de un donativo confirmado, una sola vez
 * (`donation_id` único), y lo encola. Repetir la llamada devuelve el
 * registro existente sin volver a enviar.
 */
class QueueAccountingNotice
{
    public function handle(Donation $donation): ?AccountingNotice
    {
        if ($donation->status !== DonationStatus::Confirmed) {
            return null;
        }

        $existing = AccountingNotice::query()->where('donation_id', $donation->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            $notice = DB::transaction(fn (): AccountingNotice => AccountingNotice::query()->create([
                'donation_id' => $donation->id,
                'status' => AccountingNoticeStatus::Pending,
            ]));
        } catch (UniqueConstraintViolationException) {
            return AccountingNotice::query()->where('donation_id', $donation->id)->firstOrFail();
        }

        SendAccountingNotice::dispatch($notice->id)->afterCommit();

        return $notice;
    }
}
