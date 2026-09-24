<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\DonationStatus;
use App\Models\Donation;
use App\Models\ExternalCfdi;
use Illuminate\Database\Eloquent\Builder;

/**
 * Control contable y documental (docs/tecnico/cfdi-externo.md): una fila por
 * donativo confirmado con su recibo simple, si el donante solicitó CFDI, el
 * aviso a Contabilidad, el procesamiento contable y el CFDI externo adjunto.
 * El CRM no decide qué donativos van a factura global ni emite nada.
 */
final class AccountingControl
{
    public const string PENDING = 'pending';

    public const string PROCESSED = 'processed';

    /**
     * @return Builder<Donation>
     */
    public static function query(): Builder
    {
        return Donation::query()
            ->where('status', DonationStatus::Confirmed->value)
            ->with([
                'donor', 'receipt', 'campaign.program', 'program', 'accountingNotice.processedBy',
                'externalCfdis' => fn ($query) => $query->whereNull('removed_at'),
            ]);
    }

    /**
     * El CFDI externo vigente más reciente, de la relación ya cargada.
     */
    public static function externalCfdi(Donation $donation): ?ExternalCfdi
    {
        return $donation->externalCfdis->first();
    }

    public static function isProcessed(Donation $donation): bool
    {
        return $donation->accountingNotice?->processed_at !== null;
    }

    /**
     * @param  Builder<Donation>  $query
     * @return Builder<Donation>
     */
    public static function whereProcessing(Builder $query, string $state): Builder
    {
        return $state === self::PROCESSED
            ? $query->whereHas('accountingNotice', fn (Builder $notice) => $notice->whereNotNull('processed_at'))
            : $query->whereDoesntHave('accountingNotice', fn (Builder $notice) => $notice->whereNotNull('processed_at'));
    }

    /**
     * @param  Builder<Donation>  $query
     * @return Builder<Donation>
     */
    public static function whereExternalCfdi(Builder $query, bool $attached): Builder
    {
        $active = fn (Builder $cfdis) => $cfdis->whereNull('removed_at');

        return $attached ? $query->whereHas('externalCfdis', $active) : $query->whereDoesntHave('externalCfdis', $active);
    }
}
