<?php

declare(strict_types=1);

namespace App\Actions\Donations;

use App\Actions\Accounting\QueueAccountingNotice;
use App\Actions\Communications\IssueDonationReceipt;
use App\Actions\Communications\QueueDonationThankYou;
use App\Models\Donation;

/**
 * Pasos al confirmarse un donativo (manual, en línea o cada mensualidad),
 * después del commit: 1) recibo simple, 2) agradecimiento al donante con el
 * recibo, 3) aviso a Contabilidad. Cada paso es independiente e idempotente:
 * un fallo en uno no impide los demás ni revierte la confirmación (los
 * envíos se reintentan desde su propio registro). Nada espera un CFDI.
 */
class RunConfirmedDonationSteps
{
    public function __construct(
        private readonly IssueDonationReceipt $receipts,
        private readonly QueueDonationThankYou $thankYou,
        private readonly QueueAccountingNotice $accounting,
    ) {}

    public function handle(Donation $donation): void
    {
        rescue(fn () => $this->receipts->handle($donation));
        rescue(fn () => $this->thankYou->handle($donation));
        rescue(fn () => $this->accounting->handle($donation));
    }
}
