<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Models\User;
use App\Reports\DashboardMetrics;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Indicadores del mes en curso. Los cálculos viven en DashboardMetrics
 * (definiciones en docs/tecnico/fase-5-reportes.md §1); aquí solo se muestran.
 */
class FundraisingOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Resumen del mes';

    public static function canView(): bool
    {
        return self::actor()?->hasPermission(Permission::ViewDonations) ?? false;
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $metrics = app(DashboardMetrics::class);
        $month = CarbonImmutable::now();
        $previous = $month->subMonthNoOverflow();
        $user = self::actor();

        $raised = $metrics->raisedInMonth($month);
        $raisedBefore = $metrics->raisedInMonth($previous);
        $change = $metrics->changePercent($raised, $raisedBefore);

        $stats = [
            Stat::make('Recaudado en '.$month->translatedFormat('F'), Money::format($raised).' MXN')
                ->description($change === null
                    ? 'Mes anterior sin donativos: sin base de comparación'
                    : ($change[0] === '-' ? "{$change} %" : "+{$change} %").' vs '.$previous->translatedFormat('F').' ('.Money::format($raisedBefore).')')
                ->descriptionIcon($change !== null && $change[0] === '-' ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-arrow-trending-up')
                ->color($change !== null && $change[0] === '-' ? 'danger' : 'success'),
            Stat::make('Donantes nuevos', (string) $metrics->newDonorsInMonth($month))
                ->description('Primer donativo confirmado en el mes'),
        ];

        if ($user?->hasPermission(Permission::ViewSubscriptions) ?? false) {
            $subscriptions = $metrics->subscriptionCounts();
            $stats[] = Stat::make('Donativos mensuales activos', (string) $subscriptions['active'])
                ->description("Además: {$subscriptions['past_due']} con cobro pendiente y {$subscriptions['paused']} en pausa");
        }

        if ($user?->hasPermission(Permission::ViewPayments) ?? false) {
            $failure = $metrics->paymentFailureRate($month);
            $stats[] = Stat::make('Tasa de fallos de pagos', $failure['rate'] === null ? '—' : "{$failure['rate']} %")
                ->description("{$failure['failed']} fallidos de {$failure['finished']} pagos terminados en el mes")
                ->color($failure['rate'] !== null && bccomp($failure['rate'], '10', 1) >= 0 ? 'danger' : 'gray');
            $stats[] = Stat::make('Reembolsado en el mes', Money::format($metrics->refundedInMonth($month)).' MXN')
                ->description('Reembolsos exitosos; no se restan de lo recaudado');
        }

        return $stats;
    }

    private static function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
