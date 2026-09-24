<?php

declare(strict_types=1);

namespace App\Mail\Outgoing;

use Throwable;

/**
 * Traduce un error de Symfony Mailer a una categoría y un mensaje útil para
 * el Administrador. Nunca devuelve el texto original (puede traer usuario,
 * servidor interno o detalles del proveedor) ni la traza.
 */
final class SmtpErrorTranslator
{
    /**
     * @return array{category: string, message: string}
     */
    public static function translate(Throwable $exception): array
    {
        $text = mb_strtolower($exception->getMessage());

        $category = match (true) {
            self::has($text, ['timed out', 'timeout']) => 'timeout',
            self::has($text, ['authenticat', ' 535', '"535"', ' 534', '"534"', ' 530 ', 'username and password']) => 'authentication',
            self::has($text, ['tls', 'ssl', 'crypto', 'certificate', 'starttls']) => 'tls',
            self::has($text, ['getaddrinfo', 'name or service not known', 'php_network_getaddresses', 'could not be established', 'connection refused', 'network is unreachable', 'no route to host', 'unable to connect']) => 'connection',
            self::has($text, ['mail from', 'sender', 'from address']) => 'sender_rejected',
            self::has($text, ['rcpt to', 'recipient', 'mailbox unavailable', 'user unknown']) => 'recipient_rejected',
            default => 'other',
        };

        return ['category' => $category, 'message' => self::MESSAGES[$category]];
    }

    private const array MESSAGES = [
        'connection' => 'No se pudo conectar con el servidor SMTP. Revisa el servidor (nombre DNS) y el puerto, y que el servidor acepte conexiones desde el CRM.',
        'timeout' => 'El servidor SMTP no respondió a tiempo. Revisa el servidor, el puerto y el tiempo de espera; puede haber un firewall en medio.',
        'authentication' => 'El servidor SMTP rechazó el usuario o la contraseña. Verifica las credenciales (algunos proveedores exigen una contraseña de aplicación).',
        'tls' => 'Falló la conexión segura (TLS). Revisa que la seguridad elegida corresponda al puerto: STARTTLS suele usarse con 587 y SSL/TLS con 465.',
        'sender_rejected' => 'El servidor SMTP rechazó el remitente. Usa un correo remitente autorizado por el proveedor para esa cuenta o dominio.',
        'recipient_rejected' => 'El servidor SMTP rechazó el destinatario. Revisa la dirección de prueba.',
        'other' => 'El servidor SMTP rechazó el envío. Revisa la configuración con tu proveedor de correo.',
    ];

    /**
     * @param  list<string>  $needles
     */
    private static function has(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
