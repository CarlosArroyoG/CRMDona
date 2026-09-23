<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Consultas del reporte de pagos (Fase 5), compartidas por la pantalla, la
 * exportación y las pruebas. Definiciones en docs/tecnico/fase-5-reportes.md §2.
 */
final class PaymentReport
{
    public const string RECOVERED = 'recovered';

    public const string FAILED_RECURRING = 'failed_recurring';

    /**
     * @return array<string, string>
     */
    public static function situations(): array
    {
        return [
            self::RECOVERED => 'Recuperado (exitoso después de un intento rechazado)',
            self::FAILED_RECURRING => 'Cobro mensual fallido',
        ];
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function whereSituation(Builder $query, string $situation): Builder
    {
        return match ($situation) {
            self::RECOVERED => $query->where('status', PaymentStatus::Succeeded->value)
                ->whereHas('attempts', fn (Builder $attempts) => $attempts->where('status', PaymentAttemptStatus::Failed->value)),
            self::FAILED_RECURRING => $query->where('kind', PaymentKind::RecurringCharge->value)->where('status', PaymentStatus::Failed->value),
            default => $query,
        };
    }

    /**
     * Precarga si hubo algún intento rechazado (para "Recuperado" sin N+1).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function withFailedAttemptFlag(Builder $query): Builder
    {
        return $query->withExists(['attempts as had_failed_attempt' => fn (Builder $attempts) => $attempts
            ->where('status', PaymentAttemptStatus::Failed->value)]);
    }

    /**
     * Totales de un conjunto de pagos (con los mismos filtros de la pantalla),
     * sumados en PostgreSQL: importe de todos, importe cobrado (exitosos) y
     * reembolsado (reembolsos exitosos). Strings decimales exactos.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>|QueryBuilder  $payments
     * @return array{count: int, amount: string, succeeded: string, refunded: string}
     */
    public static function totals(Builder|QueryBuilder $payments): array
    {
        $base = $payments instanceof Builder ? $payments->toBase() : $payments;
        $ids = (clone $base)->reorder()->select('payments.id');

        $row = DB::table('payments')->whereIn('id', $ids)
            ->selectRaw('count(*) as total_count, coalesce(sum(amount), 0)::numeric(14,2)::text as amount,
                coalesce(sum(amount) filter (where status = ?), 0)::numeric(14,2)::text as succeeded', [PaymentStatus::Succeeded->value])
            ->first();

        $refunded = DB::table('refunds')->whereIn('payment_id', (clone $ids))->where('status', RefundStatus::Succeeded->value)
            ->selectRaw('coalesce(sum(amount), 0)::numeric(14,2)::text as total')->value('total');

        return [
            'count' => (int) ($row->total_count ?? 0),
            'amount' => (string) ($row->amount ?? '0.00'),
            'succeeded' => (string) ($row->succeeded ?? '0.00'),
            'refunded' => (string) $refunded,
        ];
    }
}
