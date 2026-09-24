<?php

declare(strict_types=1);

namespace App\Communications;

use App\Actions\Communications\IssueDonationReceipt;
use App\Enums\PaymentAttemptStatus;
use App\Filament\Resources\Donations\DonationResource;
use App\Models\Donation;
use App\Models\PaymentAttempt;
use App\Support\Money;

/**
 * Correo interno a Contabilidad por cada donativo confirmado
 * (docs/tecnico/cfdi-externo.md). Dice sin ambigüedad si el donante solicitó
 * CFDI y, solo en ese caso, incluye los datos fiscales ya capturados en el
 * CRM. Si no lo solicitó, no clasifica el donativo (ni como factura global):
 * Contabilidad decide fuera del sistema.
 *
 * Solo va a los usuarios de Contabilidad autorizados; nunca al donante.
 */
final class AccountingNoticeComposer
{
    public function __construct(private readonly IssueDonationReceipt $receipts) {}

    public function compose(Donation $donation): ComposedMessage
    {
        $donation->loadMissing(['donor.taxProfile', 'campaign.program', 'program', 'payment.attempts']);
        $receipt = $this->receipts->handle($donation);
        $requested = $donation->tax_receipt_requested;

        $lines = [
            'Folio del recibo simple: '.$receipt->folio,
            'Donativo: #'.$donation->id,
            'Donante: '.$donation->donor->display_name,
            'Fecha de recepción: '.$donation->received_on->format('d/m/Y'),
            'Importe: '.Money::format($donation->amount).' MXN',
            'Destino: '.$this->destination($donation),
            'Forma de pago: '.$this->paymentMethod($donation),
            'CFDI solicitado: '.($requested ? 'SÍ' : 'NO'),
        ];

        $lines[] = $requested
            ? $this->taxData($donation)
            : 'El donante no solicitó CFDI. El CRM no clasifica este donativo ni emite nada: su tratamiento fiscal lo decide Contabilidad.';

        $lines[] = 'Ver el donativo en el CRM: '.DonationResource::getUrl('view', ['record' => $donation->id], panel: 'admin');

        return new ComposedMessage(
            subject: "[CRM] Donativo confirmado {$receipt->folio} — CFDI solicitado: ".($requested ? 'SÍ' : 'NO'),
            body: implode("\n\n", $lines),
            notices: [
                'Aviso interno para Contabilidad. El CRM no emite, timbra, cancela ni sustituye CFDI.',
                'Contiene datos personales y fiscales: no lo reenvíes fuera de Contabilidad.',
            ],
            attachments: [],
            usedFallback: false,
            signature: null,
            unsubscribeUrl: null,
        );
    }

    private function destination(Donation $donation): string
    {
        if ($donation->campaign !== null) {
            return 'Campaña "'.$donation->campaign->name.'"'.($donation->campaign->program !== null ? ' (programa "'.$donation->campaign->program->name.'")' : '');
        }

        return $donation->program !== null ? 'Programa "'.$donation->program->name.'"' : 'Fondo general';
    }

    private function paymentMethod(Donation $donation): string
    {
        if ($donation->manual_payment_method !== null) {
            return $donation->manual_payment_method->getLabel();
        }

        $payment = $donation->payment;
        if ($payment === null) {
            return $donation->isOnline() ? 'Pago en línea' : 'No aplica (especie)';
        }

        $attempt = $payment->attempts->first(fn (PaymentAttempt $attempt): bool => $attempt->status === PaymentAttemptStatus::Succeeded);
        $card = $attempt?->card_funding !== null ? ', tarjeta de '.mb_strtolower($attempt->card_funding->getLabel()) : '';

        return 'Pago en línea ('.$payment->provider->getLabel().$card.')';
    }

    private function taxData(Donation $donation): string
    {
        $donor = $donation->donor;
        $profile = $donor->taxProfile;
        if ($profile === null) {
            return 'Datos fiscales: el donante no tiene datos fiscales capturados en el CRM. Solicítalos directamente'
                .(filled($donor->email) ? ' ('.$donor->email.').' : '.');
        }

        return implode("\n", [
            'Datos fiscales capturados en el CRM:',
            'RFC: '.$profile->rfc,
            'Nombre o razón social: '.$profile->tax_name,
            'Régimen fiscal: '.$profile->tax_regime->getLabel(),
            'Código postal fiscal: '.$profile->tax_postal_code,
            'Uso del CFDI: '.($profile->cfdi_use?->getLabel() ?? 'No indicado'),
            filled($donor->email) ? 'Correo del donante: '.$donor->email : 'Correo del donante: no registrado',
        ]);
    }
}
