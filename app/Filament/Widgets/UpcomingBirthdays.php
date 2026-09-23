<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Models\User;
use App\Reports\DashboardMetrics;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;

/**
 * Cumpleaños de hoy y los próximos 6 días (donantes no archivados). Indica
 * si el donante recibirá la felicitación automática.
 */
class UpcomingBirthdays extends Widget
{
    protected static ?int $sort = 2;

    protected string $view = 'filament.widgets.upcoming-birthdays';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasPermission(Permission::ViewDonors);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $metrics = app(DashboardMetrics::class);
        $today = CarbonImmutable::now();

        return [
            'birthdays' => $metrics->upcomingBirthdays($today)->map(fn ($donor): array => [
                'name' => $donor->display_name,
                'date' => $donor->birth_date?->translatedFormat('j \d\e F'),
                'days' => $metrics->daysUntilBirthday($donor, $today),
                'greeted' => $donor->accepts_communications && filled($donor->email),
            ])->all(),
        ];
    }
}
