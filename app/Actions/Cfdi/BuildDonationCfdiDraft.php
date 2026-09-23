<?php

declare(strict_types=1);

namespace App\Actions\Cfdi;

use App\Cfdi\Data\CfdiDraft;
use App\Cfdi\Exceptions\CfdiNotReadyException;
use App\Enums\DonationKind;
use App\Enums\DonationOrigin;
use App\Enums\DonationStatus;
use App\Enums\DonorType;
use App\Enums\ManualPaymentMethod;
use App\Enums\TaxRegime;
use App\Models\Cfdi;
use App\Models\Donation;
use App\Models\OrganizationSetting;

/**
 * Arma el contenido fiscal del CFDI de un donativo (docs/tecnico/fase-3-cfdi.md).
 *
 * Reglas [V] — SAT, Donatarias Autorizadas, "Preguntas frecuentes: emisión de
 * facturas electrónicas" (§4–6, 13): ingreso, PUE, clave 84101600, unidad
 * M4, cantidad 1, descripción "Donativo", ObjetoImp 01, uso D04 (persona
 * física; S01 si tributa en RESICO 626) o G03 (persona moral), complemento
 * de donatarias obligatorio con número y fecha de oficio y la leyenda.
 *
 * Lo que la normativa leída no resuelve se bloquea con un motivo [F] en
 * lugar de inventar una regla.
 */
class BuildDonationCfdiDraft
{
    /**
     * [V] Leyenda del complemento (SAT, pregunta 5; CFF 29-A fr. V inciso b).
     */
    public const string DONATARIA_LEGEND = 'Este comprobante ampara un donativo, el cual será destinado por la donataria a los fines propios de su objeto social. En el caso de que los bienes donados hayan sido deducidos previamente para los efectos del impuesto sobre la renta, este donativo no es deducible.';

    /**
     * @throws CfdiNotReadyException
     */
    public function handle(Donation $donation, Cfdi|int|null $folio = null): CfdiDraft
    {
        $reasons = [];
        $settings = OrganizationSetting::current();
        $profile = $donation->donor->taxProfile;

        if ($donation->status !== DonationStatus::Confirmed) {
            $reasons[] = 'Solo se emite CFDI de donativos confirmados (el SAT no permite emitirlo antes de recibir el donativo).';
        }

        if ($donation->kind === DonationKind::InKind) {
            $reasons[] = '[F] Los donativos en especie requieren definir con el contador la clave del bien, la unidad y la valuación.';
        }

        foreach ([
            'legal_name' => 'la razón social', 'rfc' => 'el RFC', 'tax_regime' => 'el régimen fiscal',
            'tax_postal_code' => 'el código postal fiscal', 'authorization_number' => 'el número de oficio de autorización',
            'authorization_date' => 'la fecha de autorización',
        ] as $field => $label) {
            if (blank($settings->{$field})) {
                $reasons[] = "Falta {$label} de la organización (Administración → Organización).";
            }
        }

        if ($profile === null) {
            $reasons[] = '[F] El donante no tiene datos fiscales. La emisión a público en general (XAXX010101000) está pendiente de decisión fiscal.';
        }

        $paymentForm = $this->paymentForm($donation, $reasons);
        $usage = $profile !== null ? $this->usage($donation, $profile->tax_regime, $profile->cfdi_use?->value, $reasons) : null;

        if ($reasons !== [] || $profile === null || $usage === null || $paymentForm === null) {
            throw new CfdiNotReadyException($reasons);
        }

        return new CfdiDraft(
            donationId: $donation->id,
            series: config()->string('cfdi.series'),
            folio: (string) ($folio instanceof Cfdi ? $folio->id : ($folio ?? $donation->id)),
            issuerRfc: (string) $settings->rfc,
            issuerName: (string) $settings->legal_name,
            issuerRegime: (string) $settings->tax_regime?->value,
            expeditionPostalCode: (string) $settings->tax_postal_code,
            receiverRfc: $profile->rfc,
            receiverName: $profile->tax_name,
            receiverRegime: $profile->tax_regime->value,
            receiverPostalCode: $profile->tax_postal_code,
            cfdiUse: $usage,
            paymentForm: $paymentForm,
            paymentMethod: 'PUE',
            voucherType: 'I',
            currency: 'MXN',
            productCode: '84101600',
            unitCode: 'M4',
            description: 'Donativo',
            quantity: '1',
            unitValue: $donation->amount,
            total: $donation->amount,
            taxObject: '01',
            authorizationNumber: (string) $settings->authorization_number,
            authorizationDate: (string) $settings->authorization_date?->toDateString(),
            legend: self::DONATARIA_LEGEND,
        );
    }

    /**
     * [V] "La que corresponda de acuerdo al catálogo de forma de pago":
     * 01 efectivo, 02 cheque nominativo, 03 transferencia. Depósito bancario
     * y tarjeta en línea (04 crédito / 28 débito) quedan [F]/[S].
     *
     * @param  list<string>  $reasons
     */
    private function paymentForm(Donation $donation, array &$reasons): ?string
    {
        if ($donation->origin === DonationOrigin::Online) {
            $reasons[] = '[F] Forma de pago de donativos en línea con tarjeta: falta decidir cómo distinguir crédito (04) de débito (28).';

            return null;
        }

        return match ($donation->manual_payment_method) {
            ManualPaymentMethod::Cash => '01',
            ManualPaymentMethod::Check => '02',
            ManualPaymentMethod::BankTransfer => '03',
            ManualPaymentMethod::BankDeposit => $this->unresolved($reasons, '[F] Forma de pago de un depósito bancario (efectivo o cheque depositado): requiere decisión del contador.'),
            null => $this->unresolved($reasons, 'El donativo no tiene forma de pago.'),
        };
    }

    /**
     * [V] D04 para persona física (S01 si su régimen es 626 RESICO); G03 para
     * persona moral. Si el perfil fiscal del donante trae otro uso, se pide
     * corregirlo en lugar de sobrescribirlo.
     *
     * @param  list<string>  $reasons
     */
    private function usage(Donation $donation, TaxRegime $regime, ?string $profileUse, array &$reasons): ?string
    {
        $expected = match (true) {
            $donation->donor->type === DonorType::Organization => 'G03',
            $regime === TaxRegime::SimplifiedTrust => 'S01',
            default => 'D04',
        };

        if ($profileUse !== null && $profileUse !== $expected) {
            $reasons[] = "El uso de CFDI del donante ({$profileUse}) no corresponde; para su tipo y régimen debe ser {$expected}. Corrígelo en sus datos fiscales.";

            return null;
        }

        return $expected;
    }

    /**
     * @param  list<string>  $reasons
     */
    private function unresolved(array &$reasons, string $reason): null
    {
        $reasons[] = $reason;

        return null;
    }
}
