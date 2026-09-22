<?php

declare(strict_types=1);

namespace App\Actions\Donors;

use App\Actions\Concerns\NormalizesInput;
use App\Enums\AuditEvent;
use App\Enums\DonorType;
use App\Enums\Permission;
use App\Models\Donor;
use App\Models\OrganizationSetting;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

/**
 * Crea o actualiza un donante con sus etiquetas y, si quien guarda tiene
 * permiso, sus datos fiscales. Todo en una transacción (ADR-003).
 *
 * Consentimientos: la aceptación del aviso de privacidad guarda la versión
 * vigente y la fecha; sin aviso configurado no se puede registrar.
 */
class SaveDonor
{
    use NormalizesInput;

    public const string PRIVACY_NOTICE_MISSING = 'No hay un aviso de privacidad configurado (URL y versión). Pide al Administrador que lo registre en la configuración de la organización.';

    public function __construct(private readonly SaveDonorTaxProfile $taxProfiles) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(?Donor $donor, array $input, User $actor): Donor
    {
        $data = $this->validate($this->normalize($input));
        $type = DonorType::from($data['type']);

        $manageTax = $actor->hasPermission(Permission::ManageDonorTaxProfiles) && array_key_exists('has_tax_profile', $input);
        $taxInput = null;
        if ($manageTax && (bool) ($data['has_tax_profile'] ?? false)) {
            $taxInput = is_array($input['tax_profile'] ?? null) ? $input['tax_profile'] : [];
            $this->taxProfiles->validate(new Donor(['type' => $type]), $taxInput, 'tax_profile.');
        }

        return DB::transaction(function () use ($donor, $data, $type, $actor, $manageTax, $taxInput): Donor {
            $donor ??= new Donor;
            $isNew = ! $donor->exists;
            $individual = $type === DonorType::Individual;

            $donor->fill([
                'type' => $type,
                'first_name' => $individual ? $data['first_name'] : null,
                'last_name' => $individual ? $data['last_name'] : null,
                'second_last_name' => $individual ? ($data['second_last_name'] ?? null) : null,
                'birth_date' => $individual ? ($data['birth_date'] ?? null) : null,
                'legal_name' => $individual ? null : $data['legal_name'],
                'contact_name' => $individual ? null : ($data['contact_name'] ?? null),
                'email' => isset($data['email']) ? Str::lower($data['email']) : null,
                'phone' => $data['phone'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $this->applyConsents($donor, (bool) ($data['privacy_notice_accepted'] ?? false), (bool) ($data['accepts_communications'] ?? false));

            if ($isNew) {
                $donor->forceFill(['registered_by_id' => $actor->id]);
            }
            $donor->save();

            $this->syncTags($donor, array_values(array_map('intval', $data['tag_ids'] ?? [])), $isNew);

            if ($manageTax) {
                $this->taxProfiles->handle($donor, $taxInput);
            }

            return $donor->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validate(array $input): array
    {
        $type = DonorType::tryFrom(is_string($input['type'] ?? null) ? $input['type'] : '');

        return Validator::make($input, [
            'type' => ['required', new Enum(DonorType::class)],
            'first_name' => [Rule::requiredIf($type === DonorType::Individual), 'nullable', 'string', 'max:100'],
            'last_name' => [Rule::requiredIf($type === DonorType::Individual), 'nullable', 'string', 'max:100'],
            'second_last_name' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'legal_name' => [Rule::requiredIf($type === DonorType::Organization), 'nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^[0-9 +()\-]{7,30}$/'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'privacy_notice_accepted' => ['boolean'],
            'accepts_communications' => ['boolean'],
            'tag_ids' => ['array'],
            'tag_ids.*' => ['integer', Rule::exists(Tag::class, 'id')],
            'has_tax_profile' => ['boolean'],
        ], [
            'phone.regex' => 'El teléfono solo puede tener números, espacios, +, paréntesis y guiones (7 a 30 caracteres).',
        ], [
            'type' => 'tipo de persona',
            'first_name' => 'nombre',
            'last_name' => 'apellido paterno',
            'second_last_name' => 'apellido materno',
            'birth_date' => 'fecha de nacimiento',
            'legal_name' => 'razón social',
            'contact_name' => 'persona de contacto',
            'phone' => 'teléfono',
            'notes' => 'notas',
            'tag_ids' => 'etiquetas',
        ])->validate();
    }

    private function applyConsents(Donor $donor, bool $privacyAccepted, bool $acceptsCommunications): void
    {
        $alreadyAccepted = $donor->privacy_notice_accepted_at !== null;

        if ($privacyAccepted && ! $alreadyAccepted) {
            $settings = OrganizationSetting::current();
            if (! $settings->hasPrivacyNotice()) {
                throw ValidationException::withMessages(['privacy_notice_accepted' => self::PRIVACY_NOTICE_MISSING]);
            }
            $donor->privacy_notice_version = $settings->privacy_notice_version;
            $donor->privacy_notice_accepted_at = now();
        } elseif (! $privacyAccepted && $alreadyAccepted) {
            $donor->privacy_notice_version = null;
            $donor->privacy_notice_accepted_at = null;
        }

        if (! $donor->exists || $donor->accepts_communications !== $acceptsCommunications) {
            $donor->accepts_communications = $acceptsCommunications;
            $donor->communications_consent_updated_at = now();
        }
    }

    /**
     * @param  list<int>  $tagIds
     */
    private function syncTags(Donor $donor, array $tagIds, bool $isNew): void
    {
        $before = $isNew ? [] : $donor->tags()->orderBy('name')->pluck('name')->all();
        $donor->tags()->sync($tagIds);
        $after = $donor->tags()->orderBy('name')->pluck('name')->all();

        if ($before !== $after) {
            $donor->recordAudit(AuditEvent::TagsChanged, ['tags' => $before], ['tags' => $after]);
        }
    }
}
