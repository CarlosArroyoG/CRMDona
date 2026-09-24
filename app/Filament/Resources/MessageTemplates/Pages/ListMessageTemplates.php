<?php

declare(strict_types=1);

namespace App\Filament\Resources\MessageTemplates\Pages;

use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Models\MessageTemplate;
use Filament\Resources\Pages\ListRecords;

class ListMessageTemplates extends ListRecords
{
    protected static string $resource = MessageTemplateResource::class;

    protected ?string $subheading = 'Textos de los correos automáticos que reciben los donantes.';

    public function mount(): void
    {
        MessageTemplate::ensureDefaults();

        parent::mount();
    }
}
