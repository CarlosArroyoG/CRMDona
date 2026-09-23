<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Models\OrganizationSetting;
use App\Payments\Contracts\PaymentGateway;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Aplica el límite más restrictivo entre la regla de negocio configurable
 * (organization_settings, nulo = sin límite adicional) y el límite técnico
 * verificado del proveedor (nulo = no hay uno verificado que aplicar).
 */
class ValidateOnlineDonationAmount
{
    /**
     * @return array{min: numeric-string|null, max: numeric-string|null}
     */
    public function effectiveLimits(PaymentGateway $gateway): array
    {
        $settings = OrganizationSetting::current();
        $technical = $gateway->amountLimits();

        return [
            'min' => self::pick([$settings->online_donation_min_amount, $technical->min], higher: true),
            'max' => self::pick([$settings->online_donation_max_amount, $technical->max], higher: false),
        ];
    }

    /**
     * @throws ValidationException
     */
    public function handle(PaymentGateway $gateway, string $amount): void
    {
        $limits = $this->effectiveLimits($gateway);

        if ($limits['min'] !== null && Money::compare($amount, $limits['min']) < 0) {
            throw ValidationException::withMessages(['amount' => 'El importe mínimo para donar en línea es '.Money::format($limits['min']).' MXN.']);
        }

        if ($limits['max'] !== null && Money::compare($amount, $limits['max']) > 0) {
            throw ValidationException::withMessages(['amount' => 'El importe máximo para donar en línea es '.Money::format($limits['max']).' MXN.']);
        }
    }

    /**
     * @param  list<string|null>  $values
     * @return numeric-string|null
     */
    private static function pick(array $values, bool $higher): ?string
    {
        $chosen = null;
        foreach ($values as $value) {
            if ($value === null || ! is_numeric($value)) {
                continue;
            }

            if ($chosen === null || bccomp($value, $chosen, 2) === ($higher ? 1 : -1)) {
                $chosen = bcadd($value, '0', 2);
            }
        }

        return $chosen;
    }
}
