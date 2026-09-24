<?php

declare(strict_types=1);

namespace App\Support;

/**
 * PDF de una página con texto (Helvetica, WinAnsi) y, opcionalmente, una
 * imagen JPG arriba (el logotipo), sin dependencias. Basta para el recibo
 * simple; no es un motor de maquetación.
 */
final class SimplePdf
{
    /** @var list<array{text: string, size: int, bold: bool, gap: int}> */
    private array $lines = [];

    /** @var array{data: string, width: int, height: int, drawWidth: float, drawHeight: float}|null */
    private ?array $image = null;

    /**
     * Imagen JPG en la parte superior (el logotipo), escalada para caber en
     * el recuadro sin deformarse. El JPG se incrusta tal cual (DCTDecode).
     */
    public function image(string $jpeg, int $width, int $height, int $maxWidth = 160, int $maxHeight = 60): self
    {
        $scale = min($maxWidth / max(1, $width), $maxHeight / max(1, $height));
        $this->image = [
            'data' => $jpeg, 'width' => $width, 'height' => $height,
            'drawWidth' => round($width * $scale, 2), 'drawHeight' => round($height * $scale, 2),
        ];

        return $this;
    }

    public function line(string $text, int $size = 11, bool $bold = false, int $gap = 0): self
    {
        foreach ($this->wrap($text, $size) as $index => $chunk) {
            $this->lines[] = ['text' => $chunk, 'size' => $size, 'bold' => $bold, 'gap' => $index === 0 ? $gap : 0];
        }

        return $this;
    }

    public function space(int $points = 10): self
    {
        $this->lines[] = ['text' => '', 'size' => 1, 'bold' => false, 'gap' => $points];

        return $this;
    }

    public function render(): string
    {
        $content = '';
        $y = 790;
        if ($this->image !== null) {
            $top = $y - $this->image['drawHeight'];
            $content .= "q {$this->image['drawWidth']} 0 0 {$this->image['drawHeight']} 56 {$top} cm /Im1 Do Q\n";
            $y = (int) floor($top) - 14;
        }

        foreach ($this->lines as $line) {
            $y -= $line['gap'] + $line['size'] + 4;
            if ($line['text'] === '') {
                continue;
            }
            $font = $line['bold'] ? 'F2' : 'F1';
            $content .= "BT /{$font} {$line['size']} Tf 56 {$y} Td (".$this->escape($line['text']).") Tj ET\n";
        }

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R /F2 5 0 R >>'
                .($this->image !== null ? ' /XObject << /Im1 7 0 R >>' : '').' >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            '<< /Length '.strlen($content)." >>\nstream\n{$content}endstream",
        ];

        if ($this->image !== null) {
            $objects[] = "<< /Type /XObject /Subtype /Image /Width {$this->image['width']} /Height {$this->image['height']}"
                .' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($this->image['data'])." >>\nstream\n{$this->image['data']}\nendstream";
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text, int $size): array
    {
        $width = max(20, (int) floor(500 / ($size * 0.5)));

        return $text === '' ? [''] : explode("\n", wordwrap($text, $width, "\n", true));
    }

    private function escape(string $text): string
    {
        $encoded = (string) mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $encoded);
    }
}
