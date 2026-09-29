<?php

declare(strict_types=1);

namespace App\Communications;

use App\Enums\DonationStatus;
use App\Enums\DonorType;
use App\Models\Donor;
use Illuminate\Database\Eloquent\Builder;

/**
 * Audiencia de un envío masivo. Es la única pieza que decide a quién le
 * llega: los filtros elegidos (todos se combinan con "y") más las reglas
 * fijas de las comunicaciones informativas (con correo, no archivado y con
 * "Acepta comunicaciones" verificado; decisión #34). Un donante de la página
 * pública sin donativo confirmado cuenta como "sin consentimiento".
 *
 * - Etiquetas: el donante tiene al menos una de las elegidas.
 * - Campañas, programas y fechas: tiene al menos un donativo confirmado que
 *   cumple todos esos filtros. El programa cuenta directo o a través de la
 *   campaña (como en los reportes).
 */
final readonly class BulkAudience
{
    /**
     * @param  list<int>  $tagIds
     * @param  list<int>  $campaignIds
     * @param  list<int>  $programIds
     */
    public function __construct(
        public ?DonorType $donorType = null,
        public array $tagIds = [],
        public array $campaignIds = [],
        public array $programIds = [],
        public ?string $donatedFrom = null,
        public ?string $donatedUntil = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $ids = fn (string $key): array => array_values(array_map('intval', array_filter((array) ($data[$key] ?? []), 'is_numeric')));
        $date = fn (string $key): ?string => is_string($data[$key] ?? null) && $data[$key] !== '' ? substr($data[$key], 0, 10) : null;

        return new self(
            donorType: DonorType::tryFrom(is_string($data['donor_type'] ?? null) ? $data['donor_type'] : ''),
            tagIds: $ids('tag_ids'),
            campaignIds: $ids('campaign_ids'),
            programIds: $ids('program_ids'),
            donatedFrom: $date('donated_from'),
            donatedUntil: $date('donated_until'),
        );
    }

    /**
     * @return array{donor_type: string|null, tag_ids: list<int>, campaign_ids: list<int>, program_ids: list<int>, donated_from: string|null, donated_until: string|null}
     */
    public function toArray(): array
    {
        return [
            'donor_type' => $this->donorType?->value,
            'tag_ids' => $this->tagIds,
            'campaign_ids' => $this->campaignIds,
            'program_ids' => $this->programIds,
            'donated_from' => $this->donatedFrom,
            'donated_until' => $this->donatedUntil,
        ];
    }

    /**
     * Donantes activos que cumplen los filtros (reciban o no el correo).
     *
     * @return Builder<Donor>
     */
    public function matching(): Builder
    {
        return Donor::query()
            ->whereNull('archived_at')
            ->when($this->donorType !== null, fn (Builder $query) => $query->where('type', $this->donorType))
            ->when($this->tagIds !== [], fn (Builder $query) => $query->whereHas('tags', fn (Builder $tags) => $tags->whereIn('tags.id', $this->tagIds)))
            ->when($this->filtersDonations(), fn (Builder $query) => $query->whereHas('donations', function (Builder $donations): void {
                $donations->where('status', DonationStatus::Confirmed)
                    ->when($this->campaignIds !== [], fn (Builder $inner) => $inner->whereIn('campaign_id', $this->campaignIds))
                    ->when($this->programIds !== [], fn (Builder $inner) => $inner->where(fn (Builder $program) => $program
                        ->whereIn('program_id', $this->programIds)
                        ->orWhereHas('campaign', fn (Builder $campaign) => $campaign->whereIn('program_id', $this->programIds))))
                    ->when($this->donatedFrom !== null, fn (Builder $inner) => $inner->whereDate('received_on', '>=', (string) $this->donatedFrom))
                    ->when($this->donatedUntil !== null, fn (Builder $inner) => $inner->whereDate('received_on', '<=', (string) $this->donatedUntil));
            }));
    }

    /**
     * Quienes sí recibirán el correo.
     *
     * @return Builder<Donor>
     */
    public function recipients(): Builder
    {
        return $this->matching()->whereNotNull('email')->where('email', '<>', '')->withVerifiedCommunicationsConsent();
    }

    /**
     * @return array{matching: int, recipients: int, without_email: int, without_consent: int}
     */
    public function summary(): array
    {
        $matching = $this->matching()->count();
        $withoutEmail = $this->matching()->where(fn (Builder $query) => $query->whereNull('email')->orWhere('email', ''))->count();
        $recipients = $this->recipients()->count();

        return [
            'matching' => $matching,
            'recipients' => $recipients,
            'without_email' => $withoutEmail,
            'without_consent' => $matching - $withoutEmail - $recipients,
        ];
    }

    private function filtersDonations(): bool
    {
        return $this->campaignIds !== [] || $this->programIds !== [] || $this->donatedFrom !== null || $this->donatedUntil !== null;
    }
}
