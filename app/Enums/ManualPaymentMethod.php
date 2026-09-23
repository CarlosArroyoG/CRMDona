<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Formas de pago de los donativos registrados a mano (origin = manual). Nunca "tarjeta": los pagos en línea viven en payments. Para agregar una,
 * basta un caso nuevo aquí y en el CHECK `donations_manual_payment_method_valid`.
 */
enum ManualPaymentMethod: string implements HasLabel
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Check = 'check';
    case BankDeposit = 'bank_deposit';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'Efectivo',
            self::BankTransfer => 'Transferencia bancaria',
            self::Check => 'Cheque',
            self::BankDeposit => 'Depósito bancario',
        };
    }
}
