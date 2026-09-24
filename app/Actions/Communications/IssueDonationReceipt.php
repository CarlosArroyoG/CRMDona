<?php

declare(strict_types=1);

namespace App\Actions\Communications;

use App\Enums\DonationKind;
use App\Enums\DonationStatus;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\OrganizationSetting;
use App\Support\Branding;
use App\Support\Money;
use App\Support\SimplePdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Emite el recibo simple de un donativo confirmado (uno por donativo;
 * idempotente). Es un acuse de agradecimiento: el PDF dice expresamente que
 * no es factura ni comprobante fiscal. Solo datos del donante necesarios
 * para identificarlo (nombre), sin RFC ni datos de contacto.
 */
class IssueDonationReceipt
{
    public const string DISCLAIMER = 'Este recibo es un acuse de agradecimiento. No es una factura ni un comprobante fiscal (CFDI).';

    /**
     * @throws ValidationException
     */
    public function handle(Donation $donation): DonationReceipt
    {
        return DB::transaction(function () use ($donation): DonationReceipt {
            $locked = Donation::query()->lockForUpdate()->findOrFail($donation->id);
            if ($locked->status !== DonationStatus::Confirmed) {
                throw ValidationException::withMessages(['receipt' => 'Solo los donativos confirmados tienen recibo.']);
            }

            $receipt = DonationReceipt::query()->where('donation_id', $locked->id)->first()
                ?? DonationReceipt::query()->create(['donation_id' => $locked->id, 'issued_at' => now()]);

            if ($receipt->pdf_path === null) {
                $folio = DonationReceipt::folioFor($receipt->id);
                $path = 'receipts/'.$receipt->issued_at->format('Y/m')."/{$folio}.pdf";
                Storage::disk(config()->string('communications.disk'))->put($path, $this->pdf($locked, $folio, $receipt))
                    || throw new RuntimeException('No se pudo guardar el PDF del recibo.');
                $receipt->forceFill(['folio' => $folio, 'pdf_path' => $path])->save();
            }

            return $receipt;
        });
    }

    private function pdf(Donation $donation, string $folio, DonationReceipt $receipt): string
    {
        $settings = OrganizationSetting::current();
        $organization = Branding::name();
        $amount = Money::format($donation->amount).' MXN';

        $pdf = new SimplePdf;
        // Logotipo institucional proporcional (si está configurado); el recibo sigue siendo no fiscal.
        if (($logo = Branding::logoJpeg()) !== null) {
            $pdf->image($logo['data'], $logo['width'], $logo['height']);
        }

        $pdf->line('RECIBO DE DONATIVO', 18, true)
            ->line(self::DISCLAIMER, 10, true, 6)
            ->space(8)
            ->line("Folio interno: {$folio}", 11, false, 6)
            ->line('Fecha de emisión: '.$receipt->issued_at->timezone(config()->string('communications.timezone'))->format('d/m/Y'))
            ->space(8)
            ->line('Organización', 12, true, 6)
            ->line($organization);

        if ($settings->rfc !== null) {
            $pdf->line("RFC: {$settings->rfc}");
        }

        $pdf->space(8)
            ->line('Donativo', 12, true, 6)
            ->line('Donante: '.$donation->donor->display_name)
            ->line('Fecha de recepción: '.$donation->received_on->format('d/m/Y'))
            ->line('Destino: '.ucfirst($donation->destinationLabel()));

        if ($donation->kind === DonationKind::InKind) {
            $pdf->line('Donativo en especie: '.($donation->in_kind_description ?? 'sin descripción'))
                ->line("Valor registrado por la organización: {$amount}");
        } else {
            $pdf->line("Importe: {$amount}");
        }

        return $pdf->space(16)
            ->line("Gracias por tu generosidad. {$organization}.", 11, false, 6)
            ->space(16)
            ->line(self::DISCLAIMER.' Si solicitaste comprobante fiscal (CFDI), nuestra área de contabilidad lo emite por separado.', 9)
            ->render();
    }
}
