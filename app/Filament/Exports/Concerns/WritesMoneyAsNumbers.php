<?php

declare(strict_types=1);

namespace App\Filament\Exports\Concerns;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;

/**
 * En XLSX los importes van como celdas numéricas con dos decimales para que
 * Excel pueda sumarlos (ADR-007). Es solo presentación: el CSV y la base de
 * datos conservan el decimal exacto.
 */
trait WritesMoneyAsNumbers
{
    /**
     * @return list<string>
     */
    abstract protected static function moneyColumns(): array;

    /**
     * @param  array<mixed>  $values
     */
    public function makeXlsxRow(array $values, ?Style $style = null): Row
    {
        $positions = array_keys(array_intersect(array_keys($this->columnMap), static::moneyColumns()));
        $moneyStyle = ($style !== null ? clone $style : new Style)->setFormat('#,##0.00');

        $cells = [];
        foreach (array_values($values) as $index => $value) {
            $cells[] = in_array($index, $positions, true) && is_string($value) && is_numeric($value)
                ? new NumericCell((float) $value, $moneyStyle)
                : Cell::fromValue(is_scalar($value) || $value === null ? $value : (string) json_encode($value), $style);
        }

        return new Row($cells, $style);
    }
}
