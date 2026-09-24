<?php

declare(strict_types=1);

namespace App\Communications;

use App\Actions\Communications\IssueDonationReceipt;
use App\Enums\CommunicationKind;
use App\Models\Communication;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\MessageTemplate;
use App\Models\OrganizationSetting;
use App\Support\Money;
use App\Support\TemplateRenderer;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Arma un correo a partir de su plantilla. Si la plantilla está rota o usa
 * una variable desconocida, se usa el texto predeterminado del tipo (y se
 * marca): un mensaje nunca se pierde por un error de edición.
 */
final class MessageComposer
{
    public function __construct(private readonly IssueDonationReceipt $receipts) {}

    /**
     * El agradecimiento lleva solo el recibo simple: nunca espera ni adjunta un
     * CFDI (el CRM no los emite; docs/tecnico/cfdi-externo.md).
     */
    public function compose(Communication $communication): ComposedMessage
    {
        $donor = $communication->donor;
        $kind = $communication->kind;
        $notices = [];
        $attachments = [];
        $variables = $this->commonVariables($donor);

        if ($kind->isHistorical()) {
            throw new RuntimeException('El envío de CFDI ya no existe en el CRM.');
        }

        if ($kind === CommunicationKind::ThankYou) {
            $donation = $communication->donation ?? throw new RuntimeException('El agradecimiento no tiene donativo.');
            $receipt = $this->receipts->handle($donation);
            $variables += $this->donationVariables($donation) + ['folio_recibo' => (string) $receipt->folio];
            $attachments[] = $this->file(config()->string('communications.disk'), (string) $receipt->pdf_path, "Recibo-{$receipt->folio}.pdf", 'application/pdf');
            $notices[] = IssueDonationReceipt::DISCLAIMER;
        }

        $unsubscribe = null;
        if ($kind === CommunicationKind::Birthday) {
            $unsubscribe = route('communications.unsubscribe', ['token' => $donor->communicationsToken()]);
            $notices[] = 'Si ya no deseas recibir estos mensajes, puedes darte de baja con el enlace al final de este correo.';
        }

        [$subject, $body, $fallback] = $this->render($kind, $variables);

        return new ComposedMessage(
            subject: $subject,
            body: $body,
            notices: $notices,
            attachments: $attachments,
            usedFallback: $fallback,
            signature: OrganizationSetting::current()->email_signature,
            unsubscribeUrl: $unsubscribe,
        );
    }

    /**
     * Vista previa con datos de ejemplo (sin donantes reales).
     *
     * @return array{subject: string, body: string, error: string|null}
     */
    public function preview(CommunicationKind $kind, string $subject, string $body): array
    {
        $sample = [
            'nombre' => 'María', 'organizacion' => OrganizationSetting::current()->legal_name ?? config()->string('app.name'),
            'importe' => '$1,500.00 MXN', 'fecha_donativo' => now()->format('d/m/Y'), 'destino' => 'el fondo general',
            'folio_recibo' => 'R-000123',
        ];
        $variables = array_intersect_key($sample, $kind->variables());

        try {
            return ['subject' => TemplateRenderer::render($subject, $variables), 'body' => TemplateRenderer::render($body, $variables), 'error' => null];
        } catch (InvalidArgumentException $exception) {
            return ['subject' => '', 'body' => '', 'error' => $exception->getMessage()];
        }
    }

    /**
     * @param  array<string, string>  $variables
     * @return array{0: string, 1: string, 2: bool}
     */
    private function render(CommunicationKind $kind, array $variables): array
    {
        $template = MessageTemplate::query()->where('kind', $kind->value)->first();

        try {
            if ($template !== null) {
                return [TemplateRenderer::render($template->subject, $variables), TemplateRenderer::render($template->body, $variables), false];
            }
        } catch (InvalidArgumentException $exception) {
            Log::warning('Plantilla de correo inválida; se usa la predeterminada.', ['kind' => $kind->value, 'error' => $exception->getMessage()]);
        }

        return [TemplateRenderer::render($kind->defaultSubject(), $variables), TemplateRenderer::render($kind->defaultBody(), $variables), $template !== null];
    }

    /**
     * @return array<string, string>
     */
    private function commonVariables(Donor $donor): array
    {
        return [
            'nombre' => $donor->greetingName(),
            'organizacion' => OrganizationSetting::current()->legal_name ?? config()->string('app.name'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function donationVariables(Donation $donation): array
    {
        return [
            'importe' => Money::format($donation->amount).' MXN',
            'fecha_donativo' => $donation->received_on->format('d/m/Y'),
            'destino' => $donation->destinationLabel(),
        ];
    }

    /**
     * @return array{disk: string, path: string, name: string, mime: string}
     */
    private function file(string $disk, string $path, string $name, string $mime): array
    {
        return ['disk' => $disk, 'path' => $path, 'name' => $name, 'mime' => $mime];
    }
}
