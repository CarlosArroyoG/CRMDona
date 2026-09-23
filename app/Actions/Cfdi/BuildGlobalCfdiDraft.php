<?php

declare(strict_types=1);

namespace App\Actions\Cfdi;

use App\Cfdi\Data\CfdiDraft;
use App\Cfdi\Exceptions\CfdiNotReadyException;
use App\Enums\DonationKind;
use App\Enums\DonationOrigin;
use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentAttemptStatus;
use App\Models\Donation;
use App\Models\GlobalCfdi;
use App\Models\OrganizationSetting;
use Illuminate\Support\Collection;

/** Builds the public-general CFDI draft from the exact operations in a period. */
final class BuildGlobalCfdiDraft
{
    public function __construct(private readonly BuildDonationCfdiDraft $individual) {}

    /**
     * @param  Collection<int, Donation>  $donations
     *
     * @throws CfdiNotReadyException
     */
    public function handle(GlobalCfdi $global, Collection $donations): CfdiDraft
    {
        $settings = OrganizationSetting::current();
        $reasons = [];
        foreach (['legal_name' => 'la razón social', 'rfc' => 'el RFC', 'tax_regime' => 'el régimen fiscal', 'tax_postal_code' => 'el código postal fiscal', 'authorization_number' => 'el número de oficio de autorización', 'authorization_date' => 'la fecha de autorización', 'donation_legend' => 'la leyenda de donataria'] as $field => $label) {
            if (blank($settings->{$field})) {
                $reasons[] = "Falta {$label} de la organización (Administración → Organización).";
            }
        }
        if ($donations->isEmpty()) {
            $reasons[] = 'La factura global no tiene operaciones.';
        }

        $largest = $donations->sortByDesc(fn (Donation $donation): string => $donation->amount)->first();
        $first = $donations->first();
        $paymentForm = $largest !== null ? $this->paymentForm($largest, $reasons) : null;
        if ($reasons !== [] || $largest === null || $first === null || $paymentForm === null) {
            throw new CfdiNotReadyException($reasons);
        }

        $items = $donations->map(function (Donation $donation): array {
            $inKind = $donation->kind === DonationKind::InKind;

            return [
                'description' => $inKind ? (string) $donation->in_kind_description : $this->individual->description($donation),
                'productCode' => $inKind ? (string) $donation->in_kind_product_service_code : '01010101',
                'unitCode' => $inKind ? (string) $donation->in_kind_unit_code : 'ACT',
                'unitName' => $inKind ? (string) $donation->in_kind_unit_code : 'Actividad',
                'quantity' => $inKind ? (string) $donation->in_kind_quantity : '1',
                'unitValue' => $inKind ? (string) $donation->in_kind_unit_value : $donation->amount,
                'total' => $inKind ? (string) $donation->in_kind_total_value : $donation->amount,
                'taxObject' => '01',
            ];
        })->values()->all();

        $total = '0.00';
        foreach ($items as $item) {
            /** @var numeric-string $lineTotal */
            $lineTotal = (string) $item['total'];
            $total = bcadd($total, $lineTotal, 2);
        }

        return new CfdiDraft(
            donationId: (int) $first->id,
            series: config()->string('cfdi.series'),
            folio: (string) $global->id,
            issuerRfc: (string) $settings->rfc,
            issuerName: (string) $settings->legal_name,
            issuerRegime: (string) $settings->tax_regime?->value,
            expeditionPostalCode: (string) $settings->tax_postal_code,
            receiverRfc: BuildDonationCfdiDraft::GENERIC_RFC,
            receiverName: 'PUBLICO EN GENERAL',
            receiverRegime: '616',
            receiverPostalCode: '00000',
            cfdiUse: 'S01',
            paymentForm: $paymentForm,
            paymentMethod: 'PUE',
            voucherType: 'I',
            currency: 'MXN',
            productCode: '01010101',
            unitCode: 'ACT',
            description: 'Donativos del '.substr((string) $global->period_start, 0, 10).' al '.substr((string) $global->period_end, 0, 10),
            quantity: '1',
            unitValue: $total,
            total: $total,
            taxObject: '01',
            authorizationNumber: (string) $settings->authorization_number,
            authorizationDate: (string) $settings->authorization_date?->toDateString(),
            legend: (string) $settings->donation_legend,
            unitName: 'Actividad',
            items: array_values($items),
        );
    }

    /** @param list<string> $reasons */
    private function paymentForm(Donation $donation, array &$reasons): ?string
    {
        if ($donation->kind === DonationKind::InKind) {
            return '12';
        }
        if ($donation->origin === DonationOrigin::Online) {
            $attempt = $donation->payment?->attempts()->where('status', PaymentAttemptStatus::Succeeded->value)->latest('id')->first();
            $form = $attempt?->card_funding?->cfdiPaymentForm();
            if ($form !== null) {
                return $form;
            }
            $reasons[] = "La operación {$donation->id} no tiene forma de pago inequívoca para la factura global.";

            return null;
        }

        return match ($donation->manual_payment_method) {
            ManualPaymentMethod::Cash => '01',
            ManualPaymentMethod::Check => '02',
            ManualPaymentMethod::BankTransfer => '03',
            default => tap(null, fn () => $reasons[] = "La operación {$donation->id} no tiene forma de pago inequívoca para la factura global."),
        };
    }
}
