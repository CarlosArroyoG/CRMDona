<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Catálogo SAT c_RegimenFiscal (CFDI 4.0). Solo códigos y descripciones:
 * las reglas de compatibilidad por régimen están PENDIENTES de validar con el
 * contador/PAC y la versión vigente del catálogo antes de la fase de CFDI.
 */
enum TaxRegime: string implements HasLabel
{
    case GeneralLegalEntities = '601';
    case NonProfitLegalEntities = '603';
    case Wages = '605';
    case Leasing = '606';
    case AssetDisposal = '607';
    case OtherIncome = '608';
    case ForeignResidents = '610';
    case Dividends = '611';
    case BusinessAndProfessional = '612';
    case Interest = '614';
    case Prizes = '615';
    case NoTaxObligations = '616';
    case ProductionCooperatives = '620';
    case FiscalIncorporation = '621';
    case AgriculturalActivities = '622';
    case CorporateGroups = '623';
    case Coordinated = '624';
    case DigitalPlatforms = '625';
    case SimplifiedTrust = '626';

    public function getLabel(): string
    {
        return $this->value.' - '.match ($this) {
            self::GeneralLegalEntities => 'General de Ley Personas Morales',
            self::NonProfitLegalEntities => 'Personas Morales con Fines no Lucrativos',
            self::Wages => 'Sueldos y Salarios e Ingresos Asimilados a Salarios',
            self::Leasing => 'Arrendamiento',
            self::AssetDisposal => 'Régimen de Enajenación o Adquisición de Bienes',
            self::OtherIncome => 'Demás ingresos',
            self::ForeignResidents => 'Residentes en el Extranjero sin Establecimiento Permanente en México',
            self::Dividends => 'Ingresos por Dividendos (socios y accionistas)',
            self::BusinessAndProfessional => 'Personas Físicas con Actividades Empresariales y Profesionales',
            self::Interest => 'Ingresos por intereses',
            self::Prizes => 'Régimen de los ingresos por obtención de premios',
            self::NoTaxObligations => 'Sin obligaciones fiscales',
            self::ProductionCooperatives => 'Sociedades Cooperativas de Producción que optan por diferir sus ingresos',
            self::FiscalIncorporation => 'Incorporación Fiscal',
            self::AgriculturalActivities => 'Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras',
            self::CorporateGroups => 'Opcional para Grupos de Sociedades',
            self::Coordinated => 'Coordinados',
            self::DigitalPlatforms => 'Régimen de las Actividades Empresariales con ingresos a través de Plataformas Tecnológicas',
            self::SimplifiedTrust => 'Régimen Simplificado de Confianza',
        };
    }
}
