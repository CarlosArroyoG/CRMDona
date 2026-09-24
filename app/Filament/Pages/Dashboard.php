<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Escritorio: punto de entrada al trabajo diario. Solo reúne widgets; cada
 * widget decide con la matriz de permisos si se muestra.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Escritorio';

    protected static ?string $navigationLabel = 'Escritorio';

    protected ?string $subheading = 'Tu resumen del día: pendientes, accesos rápidos e indicadores del mes.';
}
