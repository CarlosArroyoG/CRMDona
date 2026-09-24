<?php

declare(strict_types=1);

namespace App\ExternalCfdi;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use Illuminate\Validation\ValidationException;

/**
 * Lee los datos de un CFDI externo (generado por contabilidad fuera del CRM)
 * para no pedirlos a mano: UUID, fecha de emisión, fecha de timbrado, total y
 * RFC del emisor.
 *
 * Seguridad: nunca ejecuta ni resuelve nada del XML. Rechaza DOCTYPE y
 * entidades (XXE), no accede a la red, no expande entidades y limita el
 * tamaño. Solo comprueba la estructura mínima de un CFDI timbrado; el CRM NO
 * certifica su validez fiscal (eso lo hace el SAT y el proceso contable).
 */
final class CfdiXmlReader
{
    public const int MAX_BYTES = 2 * 1024 * 1024;

    private const array COMPROBANTE_NAMESPACES = ['http://www.sat.gob.mx/cfd/4', 'http://www.sat.gob.mx/cfd/3'];

    private const string TIMBRE_NAMESPACE = 'http://www.sat.gob.mx/TimbreFiscalDigital';

    /**
     * @throws ValidationException
     */
    public function read(string $xml): CfdiXmlData
    {
        if ($xml === '' || strlen($xml) > self::MAX_BYTES) {
            $this->fail('El XML está vacío o excede 2 MB.');
        }

        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $xml) === 1) {
            $this->fail('El XML contiene declaraciones DOCTYPE o ENTITY, que no se aceptan.');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $document->documentElement;
        if (! $loaded || ! $root instanceof DOMElement || $root->localName !== 'Comprobante'
            || ! in_array($root->namespaceURI, self::COMPROBANTE_NAMESPACES, true)) {
            $this->fail('El archivo no es un CFDI (no tiene el nodo cfdi:Comprobante).');
        }

        $timbre = $document->getElementsByTagNameNS(self::TIMBRE_NAMESPACE, 'TimbreFiscalDigital')->item(0);
        $uuid = $timbre instanceof DOMElement ? strtoupper(trim($timbre->getAttribute('UUID'))) : '';
        if (preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/', $uuid) !== 1) {
            $this->fail('El CFDI no está timbrado: falta el UUID del Timbre Fiscal Digital.');
        }

        $issuedAt = $this->date($root->getAttribute('Fecha'));
        if ($issuedAt === null) {
            $this->fail('El CFDI no tiene una fecha de emisión válida.');
        }

        /** @var numeric-string|null $total Solo si es un importe decimal válido. */
        $total = preg_match('/^\d+(\.\d+)?$/', $root->getAttribute('Total')) === 1 ? $root->getAttribute('Total') : null;
        $emisor = $document->getElementsByTagNameNS((string) $root->namespaceURI, 'Emisor')->item(0);

        return new CfdiXmlData(
            uuid: $uuid,
            version: $root->getAttribute('Version'),
            issuedAt: $issuedAt,
            stampedAt: $this->date($timbre->getAttribute('FechaTimbrado')),
            total: $total !== null ? bcadd($total, '0', 2) : null,
            issuerRfc: $emisor instanceof DOMElement ? strtoupper(trim($emisor->getAttribute('Rfc'))) : null,
        );
    }

    /**
     * Las fechas del CFDI no llevan zona: se interpretan en la hora del centro de México.
     */
    private function date(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $value) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d\TH:i:s', $value, 'America/Mexico_City') ?: null;
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['xml' => $message]);
    }
}
