<?php

declare(strict_types=1);

namespace App\Reports;

use App\Actions\Cfdi\BuildDonationCfdiDraft;
use App\Actions\Cfdi\ResolveDonationFiscalRoute;
use App\Cfdi\Data\FiscalCoverage;
use App\Enums\CfdiStatus;
use App\Enums\DonationStatus;
use App\Enums\FiscalRoute;
use App\Models\Cfdi;
use App\Models\Donation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reporte CFDI (Fase 5): una fila por donativo confirmado o con CFDI. No
 * inventa factura global: sin CFDI, la ruta la decide
 * ResolveDonationFiscalRoute (misma lógica que la emisión). Definiciones en
 * docs/tecnico/fase-5-reportes.md §3.
 */
final class CfdiReport
{
    public const string NO_CFDI = 'none';

    public function __construct(private readonly ResolveDonationFiscalRoute $route) {}

    /**
     * @return Builder<Donation>
     */
    public static function query(): Builder
    {
        return Donation::query()
            ->where(fn (Builder $query) => $query->where('status', DonationStatus::Confirmed->value)->orWhereHas('cfdis'))
            ->with(['donor.taxProfile', 'cfdis', 'payment', 'campaign.program', 'program']);
    }

    /**
     * El CFDI vigente a partir de la relación ya cargada (sin consulta).
     */
    public static function activeCfdi(Donation $donation): ?Cfdi
    {
        return $donation->cfdis->first(fn (Cfdi $cfdi): bool => $cfdi->status->isActive() && ! $cfdi->replacement_pending);
    }

    public static function cancelledCount(Donation $donation): int
    {
        return $donation->cfdis->where('status', CfdiStatus::Cancelled)->count();
    }

    /**
     * Filtro por estado del CFDI vigente; `none` = sin CFDI vigente.
     *
     * @param  Builder<Donation>  $query
     * @return Builder<Donation>
     */
    public static function whereActiveStatus(Builder $query, string $status): Builder
    {
        $active = fn (Builder $cfdis) => $cfdis->whereNotIn('status', CfdiStatus::inactiveValues())->where('replacement_pending', false);

        return $status === self::NO_CFDI
            ? $query->whereDoesntHave('cfdis', $active)
            : $query->whereHas('cfdis', fn (Builder $cfdis) => $active($cfdis)->where('status', $status));
    }

    /**
     * Donativos al público en general (sin datos fiscales o RFC genérico): la
     * misma regla que BuildDonationCfdiDraft::isPublicGeneral, en SQL.
     *
     * @param  Builder<Donation>  $query
     * @return Builder<Donation>
     */
    public static function wherePublicGeneral(Builder $query, bool $publicGeneral): Builder
    {
        $generic = fn (Builder $profiles) => $profiles->whereRaw('upper(rfc) = ?', [BuildDonationCfdiDraft::GENERIC_RFC]);
        $withoutProfile = fn (Builder $donors) => $donors->whereDoesntHave('taxProfile')->orWhereHas('taxProfile', $generic);

        return $publicGeneral
            ? $query->whereHas('donor', $withoutProfile)
            : $query->whereHas('donor', fn (Builder $donors) => $donors->whereHas('taxProfile', fn (Builder $profiles) => $profiles
                ->whereRaw('upper(rfc) <> ?', [BuildDonationCfdiDraft::GENERIC_RFC])));
    }

    /**
     * Con CFDI vigente la ruta es individual (ya emitido). Sin él, se calcula
     * con las reglas de emisión.
     */
    public function coverage(Donation $donation): FiscalCoverage
    {
        return self::activeCfdi($donation) !== null
            ? new FiscalCoverage(FiscalRoute::Individual)
            : $this->route->handle($donation);
    }
}
