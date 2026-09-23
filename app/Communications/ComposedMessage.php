<?php

declare(strict_types=1);

namespace App\Communications;

/**
 * Correo listo para enviar. `body` y `notices` son texto plano (la vista los
 * escapa). Los avisos los agrega el sistema y no se editan: por ejemplo, que
 * el recibo no es comprobante fiscal.
 */
final readonly class ComposedMessage
{
    /**
     * @param  list<string>  $notices
     * @param  list<array{disk: string, path: string, name: string, mime: string}>  $attachments
     */
    public function __construct(
        public string $subject,
        public string $body,
        public array $notices,
        public array $attachments,
        public bool $usedFallback,
        public ?string $signature,
        public ?string $unsubscribeUrl,
        public ?int $cfdiId = null,
    ) {}

    /**
     * @return list<string>
     */
    public function attachmentNames(): array
    {
        return array_map(fn (array $attachment): string => $attachment['name'], $this->attachments);
    }
}
