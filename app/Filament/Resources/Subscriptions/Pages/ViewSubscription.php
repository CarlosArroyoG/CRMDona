<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSubscription extends ViewRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SubscriptionResource::pauseAction()->after(fn () => $this->getRecord()->refresh()),
            SubscriptionResource::resumeAction()->after(fn () => $this->getRecord()->refresh()),
            SubscriptionResource::cancelAction()->after(fn () => $this->getRecord()->refresh()),
        ];
    }
}
