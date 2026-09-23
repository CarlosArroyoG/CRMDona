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
use App\Enums\PaymentAttemptStatus;
use App\Enums\RefundStatus;
use App\Enums\TaxRegime;
use App\Models\Cfdi;
use App\Models\Donation;
use App\Models\OrganizationSetting;

/**
 * Arma el CFDI individual (nominativo) de un donativo en dinero
 * (docs/tecnico/fase-3-cfdi.md).
 *
 * Reglas [V] — SAT, "Donatarias Autorizadas: Emisión de CFDI" (2026), caso A
 * "Donativo recibido en dinero": ingreso, PUE, forma de pago del catálogo,
 * clave 84101600, unidad M4, cantidad 1, descripción con el propósito del
 * donativo, valor = monto, ObjetoImp 01, uso
 * D04 (persona física) o G03 (persona moral); S01 si el donante tributa en
 * RESICO 626 (FAQ SAT 2024; D04 no admite el régimen 626). Complemento
 * Donatarias con número y fecha de oficio y la leyenda (CFF 29-A fr. V
 * inciso b; RMF 2026 3.10.1.2).
 *
 * Lo que la normativa no resuelve se bloquea con un motivo [F] en lugar de
 * inventar una regla.
 */
class BuildDonationCfdiDraft
{
    /**
     * [V] Leyenda del complemento (CFF 29-A fr. V inciso b).
     */
    public const string DONATARIA_LEGEND = 'Este comprobante ampara un donativo, el cual será destinado por la donataria a los fines propios de su objeto social. En el caso de que los bienes donados hayan sido deducidos previamente para los efectos del impuesto sobre la renta, este donativo no es deducible.';

    /** RFC genéricos del SAT (RMF 2026 2.7.1.23). */
    public const string GENERIC_RFC = 'XAXX010101000';

    public const string FOREIGN_RFC = 'XEXX010101000';

    /**
     * Bloqueos que aplican a cualquier CFDI del donativo (individual o global).
     *
     * @return list<string>
     */
    public function blockingReasons(Donation $donation): array
    {
        $reasons = [];

        if ($donation->status !== DonationStatus::Confirmed) {
            $reasons[] = 'Solo se emite CFDI de donativos confirmados (el SAT no permite emitirlo antes de recibir el donativo).';
        }

        if ($donation->kind === DonationKind::InKind) {
            $reasons[] = '[F] Donativo en especie: falta definir con el contador la clave del bien, la unidad y la valuación (el SAT indica forma de pago 12).';
        }

        $payment = $donation->payment;
        if ($payment !== null && $payment->refunds()->whereIn('status', [RefundStatus::Pending->value, RefundStatus::Succeeded->value])->exists()) {
            $reasons[] = '[F] El pago tiene un reembolso: el tratamiento del CFDI en reembolsos está pendiente de decisión fiscal.';
        }

        if ($payment !== null && $payment->disputes()->exists()) {
            $reasons[] = '[F] El pago tiene una disputa o contracargo: el tratamiento del CFDI está pendiente de decisión fiscal.';
        }

        return $reasons;
    }

    /**
     * Sin datos fiscales o con el RFC genérico: operación con el público en
     * general (factura global, RMF 2026 2.7.1.21).
     */
    public function isPublicGeneral(Donation $donation): bool
    {
        $profile = $donation->donor->taxProfile;

        return $profile === null || strtoupper($profile->rfc) === self::GENERIC_RFC;
    }

    /**
     * @throws CfdiNotReadyException
     */
    public function handle(Donation $donation, Cfdi|int|null $folio = null): CfdiDraft
    {
        $reasons = $this->blockingReasons($donation);
        $settings = OrganizationSetting::current();
        $profile = $donation->donor->taxProfile;

        foreach ([
            'legal_name' => 'la razón social', 'rfc' => 'el RFC', 'tax_regime' => 'el régimen fiscal',
            'tax_postal_code' => 'el código postal fiscal', 'authorization_number' => 'el número de oficio de autorización',
            'authorization_date' => 'la fecha de autorización',
        ] as $field => $label) {
            if (blank($settings->{$field})) {
                $reasons[] = "Falta {$label} de la organización (Administración → Organización).";
            }
        }

        if ($this->isPublicGeneral($donation)) {
            $reasons[] = '[F] El donante no tiene datos fiscales: corresponde a público en general (factura global), que aún no está habilitada.';
        } elseif ($profile !== null && strtoupper($profile->rfc) === self::FOREIGN_RFC) {
            $reasons[] = '[F] Donante residente en el extranjero (XEXX010101000): pendiente de decisión fiscal.';
        }

        $paymentForm = $this->paymentForm($donation, $reasons);
        $usage = $profile !== null ? $this->usage($donation, $profile->tax_regime, $profile->cfdi_use?->value, $reasons) : null;
        $related = $this->related($folio, $reasons);

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
            description: $this->description($donation),
            quantity: '1',
            unitValue: $donation->amount,
            total: $donation->amount,
            taxObject: '01',
            authorizationNumber: (string) $settings->authorization_number,
            authorizationDate: (string) $settings->authorization_date?->toDateString(),
            legend: self::DONATARIA_LEGEND,
            relatedUuids: $related,
            relationType: $related !== [] ? '04' : null,
        );
    }

    /**
     * [V] "La que corresponda conforme al catálogo de formas de pago":
     * 01 efectivo, 02 cheque nominativo, 03 transferencia, 04 tarjeta de
     * crédito, 28 tarjeta de débito. Depósito bancario, tarjeta de prepago o
     * de tipo desconocido quedan [F].
     *
     * @param  list<string>  $reasons
     */
    private function paymentForm(Donation $donation, array &$reasons): ?string
    {
        if ($donation->origin === DonationOrigin::Online) {
            $attempt = $donation->payment?->attempts()->where('status', PaymentAttemptStatus::Succeeded->value)->latest('id')->first();
            $form = $attempt?->card_funding?->cfdiPaymentForm();

            return $form ?? $this->unresolved($reasons, '[F] Forma de pago del donativo en línea: el proveedor no informó si la tarjeta es de crédito (04) o de débito (28), o es de prepago.');
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
     * [V] SAT 2026: "Descripción: registrar cuál es el propósito del
     * donativo". Se toma del destino del donativo.
     */
    public function description(Donation $donation): string
    {
        $destination = $donation->campaign->name ?? $donation->effectiveProgram()?->name;

        return mb_substr($destination !== null ? "Donativo para {$destination}" : 'Donativo para el fondo general', 0, 1000);
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
     * [V] Sustitución (motivo 01): el CFDI nuevo relaciona al original con
     * TipoRelacion 04 antes de cancelarlo.
     *
     * @param  list<string>  $reasons
     * @return list<string>
     */
    private function related(Cfdi|int|null $cfdi, array &$reasons): array
    {
        if (! $cfdi instanceof Cfdi || $cfdi->substitutes_cfdi_id === null) {
            return [];
        }

        $uuid = $cfdi->substitutes?->uuid;
        if ($uuid === null) {
            $reasons[] = 'El CFDI que se sustituye no tiene folio fiscal.';

            return [];
        }

        return [$uuid];
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
