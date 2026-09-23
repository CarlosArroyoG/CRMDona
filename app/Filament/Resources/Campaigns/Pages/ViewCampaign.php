<?php

declare(strict_types=1);

namespace App\Filament\Resources\Campaigns\Pages;

use App\Filament\Resources\Campaigns\CampaignResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCampaign extends ViewRecord
{
    protected static string $resource = CampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publicPage')->label('Abrir página pública')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                ->url(fn (): string => route('donate.campaign', ['campaign' => $this->getRecord()->getAttribute('slug')]), shouldOpenInNewTab: true),
            EditAction::make(),
            CampaignResource::deleteAction(),
        ];
    }
}
