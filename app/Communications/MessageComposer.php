<?php

declare(strict_types=1);

namespace App\Communications;

use App\Actions\Communications\IssueDonationReceipt;
use App\Enums\CommunicationKind;
use App\Models\BulkMessage;
use App\Models\Communication;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\MessageTemplate;
use App\Models\OrganizationSetting;
use App\Models\PaymentRequest;
use App\Support\Branding;
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
    public const string UNSUBSCRIBE_NOTICE = 'Si ya no deseas recibir estos mensajes, puedes darte de baja con el enlace al final de este correo.';

    private const string SAMPLE_NAME = 'María';

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

        $action = null;
        if ($kind === CommunicationKind::PaymentRequest) {
            $request = $communication->paymentRequest ?? throw new RuntimeException('El correo no tiene solicitud de pago.');
            if (! $request->isUsable()) {
                throw new RuntimeException('La solicitud de pago ya no está vigente.');
            }
            $variables += $this->paymentRequestVariables($request);
            // El enlace se arma aquí, al enviar: no queda en el historial ni en la bitácora.
            $action = ['url' => $request->url(), 'label' => 'Completar mi donativo'];
            $notices[] = 'El pago se hace en la página segura del proveedor de pago. Nunca te pediremos los datos de tu tarjeta por correo, teléfono o WhatsApp.';
        }

        $unsubscribe = null;
        if ($kind->hasUnsubscribeLink()) {
            $unsubscribe = route('communications.unsubscribe', ['token' => $donor->communicationsToken()]);
            $notices[] = self::UNSUBSCRIBE_NOTICE;
        }

        [$subject, $body, $fallback] = $kind === CommunicationKind::BulkMessage
            ? $this->renderBulk($communication->bulkMessage ?? throw new RuntimeException('El correo no tiene envío masivo.'), $variables)
            : $this->render($kind, $variables);

        return new ComposedMessage(
            subject: $subject,
            body: $body,
            notices: $notices,
            attachments: $attachments,
            usedFallback: $fallback,
            signature: OrganizationSetting::current()->email_signature,
            unsubscribeUrl: $unsubscribe,
            actionUrl: $action['url'] ?? null,
            actionLabel: $action['label'] ?? null,
        );
    }

    /**
     * Correo de prueba de un envío masivo para quien lo prepara: datos de
     * ejemplo (nunca de un donante real) y sin enlace de baja funcional.
     */
    public function bulkTest(BulkMessage $message): ComposedMessage
    {
        [$subject, $body] = $this->renderBulk($message, ['nombre' => self::SAMPLE_NAME, 'organizacion' => Branding::name()]);

        return new ComposedMessage(
            subject: '[Prueba] '.$subject,
            body: $body,
            notices: [
                'Correo de prueba: en el envío real, cada donante verá su propio nombre y un enlace para darse de baja.',
                self::UNSUBSCRIBE_NOTICE,
            ],
            attachments: [],
            usedFallback: false,
            signature: OrganizationSetting::current()->email_signature,
            unsubscribeUrl: null,
        );
    }

    /**
     * Texto de la felicitación de cumpleaños para un donante, con la misma
     * plantilla del correo (sin enlace de baja ni avisos). Lo usa "Preparar
     * WhatsApp" para no duplicar el contenido.
     */
    public function birthdayText(Donor $donor): string
    {
        return $this->render(CommunicationKind::Birthday, $this->commonVariables($donor))[1];
    }

    /**
     * Vista previa con datos de ejemplo (sin donantes reales).
     *
     * @return array{subject: string, body: string, error: string|null}
     */
    public function preview(CommunicationKind $kind, string $subject, string $body): array
    {
        $sample = [
            'nombre' => self::SAMPLE_NAME, 'organizacion' => Branding::name(),
            'importe' => '$1,500.00 MXN', 'fecha_donativo' => now()->format('d/m/Y'), 'destino' => 'el fondo general',
            'folio_recibo' => 'R-000123', 'frecuencia' => 'una sola vez', 'vigencia' => now()->addDays(PaymentRequest::VALID_DAYS)->format('d/m/Y'),
        ];
        $variables = array_intersect_key($sample, $kind->variables());

        try {
            return ['subject' => TemplateRenderer::render($subject, $variables), 'body' => TemplateRenderer::render($body, $variables), 'error' => null];
        } catch (InvalidArgumentException $exception) {
            return ['subject' => '', 'body' => '', 'error' => $exception->getMessage()];
        }
    }

    /**
     * El texto de un envío masivo se valida al guardarlo; si aun así fallara,
     * no hay texto predeterminado que tenga sentido: el envío queda fallido.
     *
     * @param  array<string, string>  $variables
     * @return array{0: string, 1: string, 2: bool}
     */
    private function renderBulk(BulkMessage $message, array $variables): array
    {
        return [TemplateRenderer::render($message->subject, $variables), TemplateRenderer::render($message->body, $variables), false];
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
            'organizacion' => Branding::name(),
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
     * @return array<string, string>
     */
    private function paymentRequestVariables(PaymentRequest $request): array
    {
        return [
            'importe' => Money::format($request->amount).' MXN',
            'frecuencia' => mb_strtolower($request->frequency->getLabel()),
            'destino' => $request->destinationLabel(),
            'vigencia' => $request->expires_at->timezone(config()->string('communications.timezone'))->format('d/m/Y'),
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
