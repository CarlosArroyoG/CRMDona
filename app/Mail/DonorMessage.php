<?php

declare(strict_types=1);

namespace App\Mail;

use App\Communications\ComposedMessage;
use App\Support\Branding;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Correo a un donante con el sistema de Mail de Laravel. El proveedor real
 * se define solo con MAIL_*. No se encola aquí: el envío ya corre en el Job
 * SendCommunication, que registra el resultado.
 */
class DonorMessage extends Mailable
{
    public function __construct(public readonly ComposedMessage $message) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->message->subject);
    }

    public function headers(): Headers
    {
        return new Headers(text: $this->message->unsubscribeUrl !== null
            ? ['List-Unsubscribe' => '<'.$this->message->unsubscribeUrl.'>']
            : []);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.donor-message',
            text: 'mail.donor-message-text',
            with: [
                'paragraphs' => preg_split("/\n\s*\n/", trim($this->message->body)) ?: [],
                'notices' => $this->message->notices,
                'signature' => $this->message->signature,
                'unsubscribeUrl' => $this->message->unsubscribeUrl,
                'body' => $this->message->body,
                'actionUrl' => $this->message->actionUrl,
                'actionLabel' => $this->message->actionLabel,
                // Identidad institucional: logo configurado por Administración o, sin él, el nombre.
                'organization' => Branding::name(),
                'logoUrl' => Branding::logoUrl(),
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $file): Attachment => Attachment::fromStorageDisk($file['disk'], $file['path'])->as($file['name'])->withMime($file['mime']),
            $this->message->attachments,
        );
    }
}
