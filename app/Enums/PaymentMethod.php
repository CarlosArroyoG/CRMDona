<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Formas de pago de los donativos registrados a mano. Para agregar una,
 * basta un caso nuevo aquí y en el CHECK `donations_payment_method_valid`.
 */
enum PaymentMethod: string implements HasLabel
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
