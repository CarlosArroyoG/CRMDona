<?php

declare(strict_types=1);

namespace App\Communications;

use App\Actions\Cfdi\ResolveDonationFiscalRoute;
use App\Actions\Communications\IssueDonationReceipt;
use App\Cfdi\CfdiProviderRegistry;
use App\Enums\CfdiStatus;
use App\Enums\CommunicationKind;
use App\Enums\CommunicationStatus;
use App\Enums\FiscalRoute;
use App\Models\Cfdi;
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
    public function __construct(
        private readonly IssueDonationReceipt $receipts,
        private readonly ResolveDonationFiscalRoute $route,
        private readonly CfdiProviderRegistry $registry,
    ) {}

    public function compose(Communication $communication): ComposedMessage
    {
        $donor = $communication->donor;
        $kind = $communication->kind;
        $notices = [];
        $attachments = [];
        $cfdi = null;
        $variables = $this->commonVariables($donor);

        if ($kind === CommunicationKind::ThankYou) {
            $donation = $communication->donation ?? throw new RuntimeException('El agradecimiento no tiene donativo.');
            $receipt = $this->receipts->handle($donation);
            $variables += $this->donationVariables($donation) + ['folio_recibo' => (string) $receipt->folio];
            $attachments[] = $this->file(config()->string('communications.disk'), (string) $receipt->pdf_path, "Recibo-{$receipt->folio}.pdf", 'application/pdf');
            $notices[] = IssueDonationReceipt::DISCLAIMER;

            $cfdi = $this->stampedCfdi($donation);
            if ($cfdi !== null && $this->deliveredSeparately($cfdi, $communication)) {
                $cfdi = null;
                $notices[] = 'El comprobante fiscal (CFDI) de este donativo se envió en un correo aparte.';
            } elseif ($cfdi !== null) {
                $attachments = [...$attachments, ...$this->cfdiFiles($cfdi)];
                $notices[] = 'Adjuntamos también el comprobante fiscal (CFDI) de este donativo.';
            } elseif ($this->registry->isConfigured() && $this->route->handle($donation)->route === FiscalRoute::Individual) {
                $notices[] = 'Tu comprobante fiscal (CFDI) te llegará en un correo aparte en cuanto esté listo.';
            }
        }

        if ($kind === CommunicationKind::Cfdi) {
            $cfdi = $communication->cfdi ?? throw new RuntimeException('El envío de CFDI no tiene CFDI.');
            if (! $cfdi->status->isStamped()) {
                throw new RuntimeException('El CFDI no está timbrado.');
            }
            $donation = $cfdi->donation;
            if ($donation === null) {
                throw new RuntimeException('La factura global no se entrega como CFDI individual a un donante.');
            }
            $variables += $this->donationVariables($donation) + ['folio_fiscal' => strtoupper((string) $cfdi->uuid)];
            $attachments = $this->cfdiFiles($cfdi);
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
            cfdiId: $cfdi?->id,
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
            'folio_recibo' => 'R-000123', 'folio_fiscal' => 'A1B2C3D4-0000-4000-8000-000000000001',
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

    private function stampedCfdi(Donation $donation): ?Cfdi
    {
        $cfdi = $donation->activeCfdi();

        return $cfdi !== null && $cfdi->status === CfdiStatus::Stamped ? $cfdi : null;
    }

    private function deliveredSeparately(Cfdi $cfdi, Communication $current): bool
    {
        return Communication::query()->where('cfdi_id', $cfdi->id)->whereKeyNot($current->id)
            ->whereIn('status', [CommunicationStatus::Queued->value, CommunicationStatus::Sending->value, CommunicationStatus::Sent->value])
            ->exists();
    }

    /**
     * @return list<array{disk: string, path: string, name: string, mime: string}>
     */
    private function cfdiFiles(Cfdi $cfdi): array
    {
        $disk = config()->string('cfdi.disk');
        $name = 'CFDI-'.strtoupper((string) $cfdi->uuid);
        $files = [$this->file($disk, (string) $cfdi->xml_path, "{$name}.xml", 'application/xml')];
        if ($cfdi->pdf_path !== null) {
            $files[] = $this->file($disk, $cfdi->pdf_path, "{$name}.pdf", 'application/pdf');
        }

        return $files;
    }

    /**
     * @return array{disk: string, path: string, name: string, mime: string}
     */
    private function file(string $disk, string $path, string $name, string $mime): array
    {
        return ['disk' => $disk, 'path' => $path, 'name' => $name, 'mime' => $mime];
    }
}
