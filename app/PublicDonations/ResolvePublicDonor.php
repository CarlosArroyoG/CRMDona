<?php

declare(strict_types=1);

namespace App\PublicDonations;

use App\Actions\Donors\FindDonorDuplicates;
use App\Actions\Donors\SaveDonorTaxProfile;
use App\Enums\DonorOrigin;
use App\Enums\DonorType;
use App\Models\Donor;
use App\Models\OrganizationSetting;
use Illuminate\Support\Facades\DB;

/**
 * Donante de un donativo público.
 *
 * - Coincidencia evidente (mismo correo, no archivado): se reutiliza ese
 *   donante SIN modificar sus datos, consentimientos ni datos fiscales (quien
 *   escribe un correo en una página pública no prueba ser su dueño). Si capturó
 *   datos fiscales o pidió comunicaciones, queda una nota interna para que el
 *   equipo lo revise, sin copiar el RFC.
 * - Sin coincidencia: se crea con origen "página pública", sin usuario del
 *   CRM, con su aceptación del aviso de privacidad y su consentimiento
 *   (opcional, nunca preseleccionado). Si el RFC coincide con otro donante,
 *   se anota como posible duplicado (nunca se fusiona).
 *
 * Nada de esto se muestra al público. Un bloqueo por correo evita crear dos
 * donantes con un doble envío simultáneo.
 *
 * @phpstan-type Payload array{donor: array<string, string|null>, tax: array<string, mixed>|null, accepts_communications: bool}
 */
class ResolvePublicDonor
{
    public function __construct(
        private readonly SaveDonorTaxProfile $taxProfiles,
        private readonly FindDonorDuplicates $duplicates,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): Donor
    {
        /** @var array<string, string|null> $input */
        $input = $payload['donor'];
        /** @var array<string, mixed>|null $tax */
        $tax = $payload['tax'];
        $email = (string) $input['email'];

        return DB::transaction(function () use ($input, $tax, $email, $payload): Donor {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["public-donor:{$email}"]);

            $existing = Donor::query()->whereNull('archived_at')->whereRaw('lower(email) = ?', [$email])->orderBy('id')->first();
            if ($existing !== null) {
                $differentTax = $tax !== null && $existing->taxProfile?->rfc !== $tax['rfc'];
                $this->noteForReview($existing, $differentTax, (bool) $payload['accepts_communications']);

                return $existing;
            }

            $settings = OrganizationSetting::current();
            $donor = new Donor;
            $donor->forceFill([
                'origin' => DonorOrigin::PublicPage,
                'registered_by_id' => null,
                'type' => DonorType::from((string) $input['type']),
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
                'second_last_name' => $input['second_last_name'],
                'legal_name' => $input['legal_name'],
                'contact_name' => $input['contact_name'],
                'email' => $email,
                'phone' => $input['phone'],
                'privacy_notice_version' => $settings->privacy_notice_version,
                'privacy_notice_accepted_at' => now(),
                'accepts_communications' => (bool) $payload['accepts_communications'],
                'communications_consent_updated_at' => now(),
            ])->save();

            if ($tax !== null) {
                $rfcTaken = $this->duplicates->handle(null, (string) $tax['rfc'], $donor->id)->isNotEmpty();
                $this->taxProfiles->handle($donor, $tax);
                if ($rfcTaken) {
                    $this->appendNote($donor, 'Posible duplicado: el RFC capturado en la página pública coincide con otro donante. Revisar (no se fusionó).');
                }
            }

            return $donor->refresh();
        });
    }

    private function noteForReview(Donor $donor, bool $differentTax, bool $wantsCommunications): void
    {
        if ($differentTax) {
            $this->appendNote($donor, 'Donativo en línea con este correo: se capturaron en la página pública datos fiscales distintos a los registrados. No se aplicaron a la ficha; confirmar con el donante antes de actualizarlos.');
        }

        if ($wantsCommunications && ! $donor->accepts_communications) {
            $this->appendNote($donor, 'Donativo en línea con este correo: se marcó "acepto recibir comunicaciones". No se cambió el consentimiento; confirmarlo con el donante.');
        }
    }

    private function appendNote(Donor $donor, string $note): void
    {
        $line = '['.now()->format('d/m/Y H:i').'] '.$note;
        $donor->forceFill(['notes' => mb_substr(trim(($donor->notes ?? '')."\n".$line), 0, 5000)])->save();
    }
}
