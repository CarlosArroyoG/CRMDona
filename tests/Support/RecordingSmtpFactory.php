<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Mail\Outgoing\SmtpTransportFactory;
use App\Models\MailSetting;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Sustituye al SMTP real en las pruebas: registra con qué servidor se habría
 * enviado cada correo y puede simular un error del servidor. Nunca usa la red.
 */
final class RecordingSmtpFactory extends SmtpTransportFactory
{
    /** @var list<array{host: string|null, port: int|null, from: string, to: array<string>, reply_to: array<string>, subject: string, attachments: array<string>, body: string}> */
    public array $sent = [];

    public ?Throwable $failWith = null;

    public function make(MailSetting $settings): TransportInterface
    {
        $factory = $this;
        $host = $settings->host;
        $port = $settings->port;

        return new class($factory, $host, $port) extends AbstractTransport
        {
            public function __construct(private readonly RecordingSmtpFactory $factory, private readonly ?string $host, private readonly ?int $port)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                if ($this->factory->failWith !== null) {
                    throw $this->factory->failWith;
                }

                $email = $message->getOriginalMessage();
                assert($email instanceof Email);
                $addresses = fn (array $list): array => array_map(fn (Address $address): string => $address->getAddress(), $list);

                $this->factory->sent[] = [
                    'host' => $this->host,
                    'port' => $this->port,
                    'from' => $addresses($email->getFrom())[0] ?? '',
                    'to' => $addresses($email->getTo()),
                    'reply_to' => $addresses($email->getReplyTo()),
                    'subject' => (string) $email->getSubject(),
                    'attachments' => array_map(fn ($part): string => (string) $part->getFilename(), $email->getAttachments()),
                    'body' => (string) $email->getTextBody().(string) $email->getHtmlBody(),
                ];
            }

            public function __toString(): string
            {
                return 'recording-smtp';
            }
        };
    }
}
