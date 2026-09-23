<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Cómo queda cubierto un donativo confirmado por la obligación de expedir
 * CFDI (LISR 86 fr. II; RLISR 138-E; SAT "Donatarias Autorizadas: Emisión de
 * CFDI" 2026). No depende de que el donante haya pedido comprobante.
 */
enum FiscalRoute: string implements HasColor, HasLabel
{
    // CFDI nominativo al RFC del donante: sus datos fiscales existen y proceden.
    case Individual = 'individual';
    // Público en general: debe incorporarse a una factura global (RMF 2.7.1.21).
    case PublicGeneral = 'public_general';
    // Bloqueo fiscal real: requiere intervención o decisión [F].
    case Blocked = 'blocked';

    public function getLabel(): string
    {
        return match ($this) {
            self::Individual => 'CFDI individual',
            self::PublicGeneral => 'Público en general (factura global)',
            self::Blocked => 'Bloqueado: requiere intervención',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Individual => 'success',
            self::PublicGeneral => 'warning',
            self::Blocked => 'danger',
        };
    }
}
