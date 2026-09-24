<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donations\Pages;

use App\Actions\Donations\RegisterDonation;
use App\Actions\PaymentRequests\CreatePaymentRequest;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Donations\DonationResource;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Models\PaymentRequest;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * "Crear donativo". Con "Ya se recibió" registra el donativo manual como
 * siempre. Con "Cobrar con tarjeta en línea" NO crea un donativo: prepara
 * una solicitud de pago con los mismos datos (donante, importe, destino, CFDI
 * solicitado) y lleva a su ficha para abrir el pago, copiar o enviar el enlace.
 */
class CreateDonation extends CreateRecord
{
    use ReportsActionErrors;

    protected static string $resource = DonationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        if (($data['collection'] ?? DonationResource::COLLECT_RECEIVED) === DonationResource::COLLECT_CARD) {
            return self::withFormErrors(fn () => app(CreatePaymentRequest::class)->handle([
                'donor_id' => $data['donor_id'] ?? null,
                'amount' => $data['amount'] ?? null,
                'frequency' => $data['frequency'] ?? null,
                'campaign_id' => $data['campaign_id'] ?? null,
                'program_id' => $data['program_id'] ?? null,
                'tax_receipt_requested' => $data['tax_receipt_requested'] ?? false,
            ], $actor));
        }

        return self::withFormErrors(fn () => app(RegisterDonation::class)->handle($data, $actor));
    }

    protected function getRedirectUrl(): string
    {
        $record = $this->getRecord();

        return $record instanceof PaymentRequest
            ? PaymentRequestResource::getUrl('view', ['record' => $record])
            : DonationResource::getUrl('view', ['record' => $record]);
    }

    protected function getCreatedNotificationTitle(): string
    {
        return $this->getRecord() instanceof PaymentRequest
            ? 'Cobro con tarjeta preparado: abre el pago, copia el enlace o envíalo por correo'
            : 'Donativo registrado "Por confirmar"';
    }
}
