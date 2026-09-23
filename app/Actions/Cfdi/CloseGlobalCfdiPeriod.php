<?php

declare(strict_types=1);

namespace App\Actions\Cfdi;

use App\Cfdi\CfdiProviderRegistry;
use App\Enums\CfdiStatus;
use App\Enums\FiscalRoute;
use App\Jobs\StampGlobalCfdi;
use App\Models\Cfdi;
use App\Models\Donation;
use App\Models\GlobalCfdi;
use App\Models\OrganizationSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Closes exactly one completed daily, weekly, or monthly public-general period. */
final class CloseGlobalCfdiPeriod
{
    public function __construct(
        private readonly ResolveDonationFiscalRoute $route,
        private readonly CfdiProviderRegistry $registry,
    ) {}

    public function handle(?CarbonImmutable $asOf = null): ?GlobalCfdi
    {
        $now = ($asOf ?? CarbonImmutable::now('America/Mexico_City'))->setTimezone('America/Mexico_City');
        $periodicity = (string) OrganizationSetting::current()->global_cfdi_periodicity;
        [$start, $end] = $this->period($now, $periodicity);

        /** @var Collection<int, Donation> $donations */
        $donations = Donation::query()->with(['donor.taxProfile', 'payment.attempts'])
            ->where('status', 'confirmed')
            ->whereBetween('received_on', [$start->toDateString(), $end->toDateString()])
            ->whereDoesntHave('globalCfdis')
            ->get()
            ->filter(function (Donation $donation): bool {
                return $this->route->handle($donation)->route === FiscalRoute::PublicGeneral;
            });

        if ($donations->isEmpty()) {
            return GlobalCfdi::query()
                ->where('periodicity', $periodicity)
                ->whereDate('period_start', $start)
                ->whereDate('period_end', $end)
                ->with(['donations', 'cfdi'])
                ->first();
        }

        return DB::transaction(function () use ($donations, $periodicity, $start, $end): GlobalCfdi {
            $global = GlobalCfdi::query()->createOrFirst(
                ['periodicity' => $periodicity, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString()],
                [
                    'provider' => $this->registry->isConfigured() ? $this->registry->current()->name() : 'unconfigured',
                    'status' => CfdiStatus::Pending,
                    'total' => $donations->sum(fn (Donation $donation): string => $donation->kind->value === 'in_kind' ? (string) $donation->in_kind_total_value : $donation->amount),
                    'currency' => 'MXN',
                    'idempotency_key' => "global:{$periodicity}:{$start->toDateString()}:{$end->toDateString()}",
                    'requested_at' => now(),
                ],
            );

            foreach ($donations as $donation) {
                $global->donations()->syncWithoutDetaching([
                    $donation->id => ['operation_number' => "donation:{$donation->id}:global:{$global->id}"],
                ]);
                $donation->forceFill(['fiscal_route' => FiscalRoute::PublicGeneral])->save();
            }

            $cfdi = Cfdi::query()->createOrFirst(
                ['idempotency_key' => "global-cfdi:{$global->id}"],
                [
                    'donation_id' => null,
                    'global_cfdi_id' => $global->id,
                    'provider' => $global->provider,
                    'status' => CfdiStatus::Pending,
                    'total' => $global->total,
                    'currency' => 'MXN',
                    'requested_at' => now(),
                ],
            );

            if ($cfdi->wasRecentlyCreated && $this->registry->isConfigured()) {
                StampGlobalCfdi::dispatch($global->id)->afterCommit();
            }

            return $global->fresh(['donations', 'cfdi']) ?? $global;
        });
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function period(CarbonImmutable $now, string $periodicity): array
    {
        return match ($periodicity) {
            'weekly' => [$now->startOfWeek()->subWeek(), $now->startOfWeek()->subDay()],
            'monthly' => [$now->startOfMonth()->subMonth(), $now->startOfMonth()->subDay()],
            default => [$now->startOfDay()->subDay(), $now->startOfDay()->subDay()],
        };
    }
}
