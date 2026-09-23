<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookEvents\Pages;

use App\Filament\Resources\WebhookEvents\WebhookEventResource;
use Filament\Resources\Pages\ViewRecord;

class ViewWebhookEvent extends ViewRecord
{
    protected static string $resource = WebhookEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            WebhookEventResource::retryAction()->after(fn () => $this->getRecord()->refresh()),
        ];
    }
}
