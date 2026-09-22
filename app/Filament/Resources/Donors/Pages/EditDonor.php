<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donors\Pages;

use App\Actions\Donors\SaveDonor;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Donors\DonorResource;
use App\Models\Donor;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditDonor extends EditRecord
{
    use ReportsActionErrors;

    protected static string $resource = DonorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DonorResource::archiveAction(),
            DonorResource::deleteAction(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Donor $donor */
        $donor = $this->getRecord();

        return [
            ...$data,
            'tag_ids' => $donor->tags()->pluck('tags.id')->all(),
            'privacy_notice_accepted' => $donor->privacy_notice_accepted_at !== null,
            'has_tax_profile' => $donor->taxProfile !== null,
            'tax_profile' => $donor->taxProfile?->formData() ?? [],
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Donor $record */
        /** @var User $actor */
        $actor = auth()->user();

        return self::withFormErrors(fn () => app(SaveDonor::class)->handle($record, $data, $actor));
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Cambios guardados';
    }
}
