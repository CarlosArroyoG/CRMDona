<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\DonationKind;
use App\Enums\DonationStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Donor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Métricas del tablero (Fase 5). Definiciones exactas en
 * docs/tecnico/fase-5-reportes.md §1. Todo se deriva de estados del dominio;
 * los importes se suman en PostgreSQL (NUMERIC) y llegan como string.
 * Los meses son de calendario en la zona de la aplicación (America/Mexico_City).
 */
final class DashboardMetrics
{
    /**
     * Donativos confirmados en dinero recibidos en el mes (`received_on`).
     * No incluye especie ni cancelados, y no resta reembolsos.
     *
     * @return numeric-string
     */
    public function raisedInMonth(CarbonImmutable $month): string
    {
        /** @var numeric-string $sum */
        $sum = (string) DB::table('donations')
            ->where('status', DonationStatus::Confirmed->value)
            ->where('kind', DonationKind::Monetary->value)
            ->whereBetween('received_on', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
            ->selectRaw('coalesce(sum(amount), 0)::numeric(14,2)::text as total')
            ->value('total');

        return $sum;
    }

    /**
     * Reembolsos exitosos procesados en el mes (`processed_at`).
     *
     * @return numeric-string
     */
    public function refundedInMonth(CarbonImmutable $month): string
    {
        /** @var numeric-string $sum */
        $sum = (string) DB::table('refunds')
            ->where('status', RefundStatus::Succeeded->value)
            ->whereBetween('processed_at', [$month->startOfMonth(), $month->endOfMonth()])
            ->selectRaw('coalesce(sum(amount), 0)::numeric(14,2)::text as total')
            ->value('total');

        return $sum;
    }

    /**
     * Variación porcentual contra el mes anterior, con un decimal. Nulo si el
     * mes anterior es cero (no hay base de comparación).
     *
     * @param  numeric-string  $current
     * @param  numeric-string  $previous
     * @return numeric-string|null
     */
    public function changePercent(string $current, string $previous): ?string
    {
        if (bccomp($previous, '0', 2) === 0) {
            return null;
        }

        $raw = bcmul(bcdiv(bcsub($current, $previous, 4), $previous, 6), '100', 4);

        return self::round1($raw);
    }

    /**
     * Donantes cuyo PRIMER donativo confirmado (dinero o especie) se recibió
     * en el mes. Registrarse sin donar no cuenta.
     */
    public function newDonorsInMonth(CarbonImmutable $month): int
    {
        return DB::query()->fromSub(
            DB::table('donations')->where('status', DonationStatus::Confirmed->value)
                ->groupBy('donor_id')->selectRaw('donor_id, min(received_on) as first_received_on'),
            'first_donations',
        )->whereBetween('first_received_on', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])->count();
    }

    /**
     * Donativos mensuales por estado actual (foto al momento de consultar).
     *
     * @return array{active: int, past_due: int, paused: int}
     */
    public function subscriptionCounts(): array
    {
        $counts = DB::table('subscriptions')
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value, SubscriptionStatus::Paused->value])
            ->groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status');

        return [
            'active' => (int) ($counts[SubscriptionStatus::Active->value] ?? 0),
            'past_due' => (int) ($counts[SubscriptionStatus::PastDue->value] ?? 0),
            'paused' => (int) ($counts[SubscriptionStatus::Paused->value] ?? 0),
        ];
    }

    /**
     * Pagos en línea creados en el mes que terminaron fallidos, entre los que
     * terminaron (exitosos + fallidos). Un pago recuperado tras un rechazo
     * cuenta como exitoso; pendientes y cancelados no entran.
     *
     * @return array{failed: int, finished: int, rate: numeric-string|null}
     */
    public function paymentFailureRate(CarbonImmutable $month): array
    {
        $row = DB::table('payments')
            ->whereBetween('created_at', [$month->startOfMonth(), $month->endOfMonth()])
            ->whereIn('status', [PaymentStatus::Succeeded->value, PaymentStatus::Failed->value])
            ->selectRaw('count(*) as finished, count(*) filter (where status = ?) as failed', [PaymentStatus::Failed->value])
            ->first();

        $finished = (int) ($row->finished ?? 0);
        $failed = (int) ($row->failed ?? 0);

        return [
            'failed' => $failed,
            'finished' => $finished,
            'rate' => $finished === 0 ? null : self::round1(bcmul(bcdiv((string) $failed, (string) $finished, 6), '100', 4)),
        ];
    }

    /**
     * Donantes no archivados que cumplen años hoy o en los próximos
     * `$days - 1` días. El 29 de febrero se cuenta el 28 en años no bisiestos
     * (igual que la felicitación). Ordenados por fecha próxima.
     *
     * @return Collection<int, Donor>
     */
    public function upcomingBirthdays(CarbonImmutable $today, int $days = 7): Collection
    {
        $pairs = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $date = $today->addDays($offset);
            $pairs[] = [$date->month, $date->day];
            if ($date->month === 2 && $date->day === 28 && ! $date->isLeapYear()) {
                $pairs[] = [2, 29];
            }
        }

        $conditions = implode(' or ', array_fill(0, count($pairs), '(extract(month from birth_date) = ? and extract(day from birth_date) = ?)'));

        $donors = Donor::query()->whereNull('archived_at')->whereNotNull('birth_date')
            ->whereRaw("({$conditions})", array_merge(...$pairs))
            ->get(['id', 'display_name', 'birth_date', 'accepts_communications', 'email']);

        return $donors->sortBy(fn (Donor $donor): int => $this->daysUntilBirthday($donor, $today))->values();
    }

    public function daysUntilBirthday(Donor $donor, CarbonImmutable $today): int
    {
        $birth = CarbonImmutable::parse($donor->birth_date?->toDateString());
        $day = $birth->month === 2 && $birth->day === 29 && ! $today->isLeapYear() ? 28 : $birth->day;
        $next = $today->setDate($today->year, $birth->month, $day)->startOfDay();
        if ($next->lt($today->startOfDay())) {
            $leapNext = $birth->month === 2 && $birth->day === 29 && $today->addYear()->isLeapYear() ? 29 : $day;
            $next = $next->setDate($today->year + 1, $birth->month, $leapNext);
        }

        return (int) $today->startOfDay()->diffInDays($next);
    }

    /**
     * Redondeo a un decimal, mitad hacia arriba, sin float.
     *
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private static function round1(string $value): string
    {
        $shift = bccomp($value, '0', 4) >= 0 ? '0.05' : '-0.05';

        return bcadd($value, $shift, 1);
    }
}
