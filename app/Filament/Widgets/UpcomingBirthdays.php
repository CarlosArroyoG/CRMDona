<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Actions\Donors\PrepareBirthdayWhatsApp;
use App\Enums\Permission;
use App\Filament\Actions\PrepareWhatsAppAction;
use App\Filament\Concerns\ResolvesActor;
use App\Models\Donor;
use App\Reports\DashboardMetrics;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;

/**
 * Cumpleaños de hoy y los próximos 6 días (donantes no archivados). Indica
 * si el donante recibirá la felicitación automática por correo y permite
 * preparar la felicitación por WhatsApp (envío manual).
 */
class UpcomingBirthdays extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use ResolvesActor;

    protected static ?int $sort = 2;

    protected string $view = 'filament.widgets.upcoming-birthdays';

    public static function canView(): bool
    {
        return self::actorCan(Permission::ViewDonors);
    }

    public function prepareWhatsAppAction(): Action
    {
        return PrepareWhatsAppAction::make(fn (array $arguments): ?Donor => is_numeric($arguments['donor'] ?? null)
            ? Donor::query()->whereNull('archived_at')->find((int) $arguments['donor'])
            : null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $metrics = app(DashboardMetrics::class);
        $today = CarbonImmutable::now();
        $actor = self::actor();

        return [
            'birthdays' => $metrics->upcomingBirthdays($today)->map(fn ($donor): array => [
                'id' => $donor->id,
                'name' => $donor->display_name,
                'date' => $donor->birth_date?->translatedFormat('j \d\e F'),
                'days' => $metrics->daysUntilBirthday($donor, $today),
                'greeted' => $donor->accepts_communications && filled($donor->email),
                'whatsapp' => PrepareBirthdayWhatsApp::canPrepare($donor, $actor),
            ])->all(),
        ];
    }
}
