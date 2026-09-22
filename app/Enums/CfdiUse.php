<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Catálogo SAT c_UsoCFDI (CFDI 4.0). Sin valor predeterminado: qué uso
 * corresponde a los donativos está PENDIENTE de confirmar con el
 * contador/PAC antes de la fase de CFDI.
 */
enum CfdiUse: string implements HasLabel
{
    case G01 = 'G01';
    case G02 = 'G02';
    case G03 = 'G03';
    case I01 = 'I01';
    case I02 = 'I02';
    case I03 = 'I03';
    case I04 = 'I04';
    case I05 = 'I05';
    case I06 = 'I06';
    case I07 = 'I07';
    case I08 = 'I08';
    case D01 = 'D01';
    case D02 = 'D02';
    case D03 = 'D03';
    case D04 = 'D04';
    case D05 = 'D05';
    case D06 = 'D06';
    case D07 = 'D07';
    case D08 = 'D08';
    case D09 = 'D09';
    case D10 = 'D10';
    case S01 = 'S01';
    case CP01 = 'CP01';
    case CN01 = 'CN01';

    public function getLabel(): string
    {
        return $this->value.' - '.match ($this) {
            self::G01 => 'Adquisición de mercancías',
            self::G02 => 'Devoluciones, descuentos o bonificaciones',
            self::G03 => 'Gastos en general',
            self::I01 => 'Construcciones',
            self::I02 => 'Mobiliario y equipo de oficina por inversiones',
            self::I03 => 'Equipo de transporte',
            self::I04 => 'Equipo de cómputo y accesorios',
            self::I05 => 'Dados, troqueles, moldes, matrices y herramental',
            self::I06 => 'Comunicaciones telefónicas',
            self::I07 => 'Comunicaciones satelitales',
            self::I08 => 'Otra maquinaria y equipo',
            self::D01 => 'Honorarios médicos, dentales y gastos hospitalarios',
            self::D02 => 'Gastos médicos por incapacidad o discapacidad',
            self::D03 => 'Gastos funerales',
            self::D04 => 'Donativos',
            self::D05 => 'Intereses reales efectivamente pagados por créditos hipotecarios (casa habitación)',
            self::D06 => 'Aportaciones voluntarias al SAR',
            self::D07 => 'Primas por seguros de gastos médicos',
            self::D08 => 'Gastos de transportación escolar obligatoria',
            self::D09 => 'Depósitos en cuentas para el ahorro, primas que tengan como base planes de pensiones',
            self::D10 => 'Pagos por servicios educativos (colegiaturas)',
            self::S01 => 'Sin efectos fiscales',
            self::CP01 => 'Pagos',
            self::CN01 => 'Nómina',
        };
    }
}
