<?php

declare(strict_types=1);

namespace App\Mail\Outgoing;

use App\Enums\MailEncryption;
use App\Models\MailSetting;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Construye el transporte SMTP estándar de Symfony Mailer a partir de la
 * configuración administrativa. No abre conexiones: Symfony conecta al
 * enviar. Las pruebas lo sustituyen en el contenedor para no usar la red.
 */
class SmtpTransportFactory
{
    public function make(MailSetting $settings): TransportInterface
    {
        $encryption = $settings->encryption;

        // `true` = TLS implícito (SMTPS). En los demás casos la conexión empieza en claro.
        $transport = new EsmtpTransport((string) $settings->host, (int) $settings->port, $encryption === MailEncryption::Tls);

        if ($encryption === MailEncryption::StartTls) {
            $transport->setRequireTls(true);
        }

        if ($encryption === MailEncryption::None) {
            $transport->setAutoTls(false);
        }

        if (filled($settings->username)) {
            $transport->setUsername((string) $settings->username);
            $transport->setPassword((string) $settings->password);
        }

        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout($settings->timeout);
        }

        return $transport;
    }
}
