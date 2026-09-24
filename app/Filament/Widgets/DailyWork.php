<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\DonationStatus;
use App\Filament\Concerns\ResolvesActor;
use App\Filament\Resources\AccountingControl\AccountingControlResource;
use App\Filament\Resources\Donations\DonationResource;
use App\Filament\Resources\Donors\DonorResource;
use App\Filament\Resources\PaymentIncidents\PaymentIncidentResource;
use App\Models\Donation;
use App\Reports\AccountingControl;
use Filament\Widgets\Widget;

/**
 * Trabajo del día en el Escritorio: accesos rápidos y conteos de pendientes
 * que ya existen como filtros de sus pantallas. Cada elemento respeta la
 * autorización de su Resource; no calcula métricas nuevas.
 */
class DailyWork extends Widget
{
    use ResolvesActor;

    protected static ?int $sort = 0;

    // Es lo primero que se ve: se pinta con la página, sin parpadeo de carga diferida.
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.daily-work';

    public static function canView(): bool
    {
        return self::actor() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $actions = array_values(array_filter([
            DonationResource::canCreate() ? ['label' => 'Registrar donativo', 'icon' => 'heroicon-o-plus', 'url' => DonationResource::getUrl('create'), 'primary' => true] : null,
            DonorResource::canCreate() ? ['label' => 'Nuevo donante', 'icon' => 'heroicon-o-user-plus', 'url' => DonorResource::getUrl('create'), 'primary' => false] : null,
            DonationResource::canViewAny() ? ['label' => 'Ver donativos', 'icon' => 'heroicon-o-gift', 'url' => DonationResource::getUrl(), 'primary' => false] : null,
            DonorResource::canViewAny() ? ['label' => 'Buscar donante', 'icon' => 'heroicon-o-magnifying-glass', 'url' => DonorResource::getUrl(), 'primary' => false] : null,
        ]));

        $pending = array_values(array_filter([
            DonationResource::canViewAny() ? [
                'label' => 'Donativos por confirmar',
                'hint' => 'Registrados que aún no se confirman.',
                'count' => Donation::query()->where('status', DonationStatus::Pending->value)->count(),
                'url' => DonationResource::getUrl(parameters: ['filters' => ['status' => ['value' => DonationStatus::Pending->value]]]),
            ] : null,
            AccountingControlResource::canViewAny() ? [
                'label' => 'Pendientes de Contabilidad',
                'hint' => 'Donativos confirmados sin procesar.',
                'count' => AccountingControl::whereProcessing(AccountingControl::query(), AccountingControl::PENDING)->count(),
                'url' => AccountingControlResource::getUrl(parameters: ['filters' => ['processing' => ['value' => AccountingControl::PENDING]]]),
            ] : null,
            PaymentIncidentResource::canViewAny() ? [
                'label' => 'Incidencias de pago abiertas',
                'hint' => 'Avisos de pagos en línea por revisar.',
                'count' => (int) (PaymentIncidentResource::getNavigationBadge() ?? 0),
                'url' => PaymentIncidentResource::getUrl(),
            ] : null,
        ]));

        return [
            'name' => self::actor()->name ?? '',
            'actions' => $actions,
            'pending' => $pending,
        ];
    }
}
