<?php

declare(strict_types=1);

namespace App\DonorRelations;

use App\DonorRelations\Data\TimelineEntry;
use App\Enums\PaymentAttemptStatus;
use App\Enums\Permission;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Communications\CommunicationResource;
use App\Filament\Resources\Donations\DonationResource;
use App\Filament\Resources\PaymentDisputes\PaymentDisputeResource;
use App\Filament\Resources\PaymentIncidents\PaymentIncidentResource;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\AccountingNotice;
use App\Models\Communication;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Donor;
use App\Models\DonorActivity;
use App\Models\DonorAssignment;
use App\Models\ExternalCfdi;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentDispute;
use App\Models\PaymentIncident;
use App\Models\PaymentRequest;
use App\Models\Refund;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Timeline 360° de un donante: agregador de consulta, no una tabla copiada
 * (docs/tecnico/gestion-relaciones-donantes.md). Cada fuente hace una sola
 * query acotada por `donor_id` y solo se consulta si el usuario tiene el
 * permiso que ya protege esa fuente en el resto del CRM — nunca se inventa
 * un permiso nuevo que vea más de lo que ya se podía ver.
 *
 * Deliberadamente fuera de alcance: la bitácora cruda del propio donante
 * (consentimientos, archivado) — ya tiene su propia pantalla con
 * `audit.view`; mezclarla aquí crearía una segunda fuente de verdad.
 */
final class Timeline
{
    public const int DEFAULT_LIMIT = 25;

    /**
     * @return Collection<int, TimelineEntry>
     */
    public static function for(Donor $donor, User $viewer, int $limit = self::DEFAULT_LIMIT): Collection
    {
        $entries = collect();

        if ($viewer->hasPermission(Permission::ViewDonorActivities)) {
            $entries = $entries->merge(self::activities($donor, $limit));
            $entries = $entries->merge(self::assignments($donor, $limit));
        }

        if ($viewer->hasPermission(Permission::ViewTasks)) {
            $entries = $entries->merge(self::tasks($donor, $limit));
        }

        if ($viewer->hasPermission(Permission::ViewDonations)) {
            $entries = $entries->merge(self::donations($donor, $limit));
        }

        if ($viewer->hasPermission(Permission::ViewPayments)) {
            $entries = $entries->merge(self::payments($donor, $limit));
        }

        if ($viewer->hasPermission(Permission::ViewPaymentTechnicalDetails)) {
            $entries = $entries->merge(self::failedAttempts($donor, $limit));
        }

        if ($viewer->hasPermission(Permission::ViewSubscriptions)) {
            $entries = $entries->merge(self::subscriptions($donor, $limit));
        }

        if ($viewer->hasPermission(Permission::ViewCommunications)) {
            $entries = $entries->merge(self::communications($donor, $limit));
        }

        if ($viewer->hasPermission(Permission::RequestPayments)) {
            $entries = $entries->merge(self::paymentRequests($donor, $limit));
        }

        if ($viewer->hasPermission(Permission::RequestRefunds)) {
            $entries = $entries->merge(self::refunds($donor, $limit));
        }

        if ($viewer->hasPermission(Permission::ViewDisputes)) {
            $entries = $entries->merge(self::disputes($donor, $limit));
        }

        if ($viewer->hasPermission(Permission::ViewIncidents)) {
            $entries = $entries->merge(self::incidents($donor, $limit));
        }

        if ($viewer->hasPermission(Permission::ViewDonationReceipts)) {
            $entries = $entries->merge(self::receipts($donor, $limit));
        }

        if ($viewer->hasPermission(Permission::ViewCfdis)) {
            $entries = $entries->merge(self::externalCfdis($donor, $limit));
        }

        if ($viewer->hasPermission(Permission::ProcessAccounting)) {
            $entries = $entries->merge(self::accountingNotices($donor, $limit));
        }

        return $entries->sortByDesc(fn (TimelineEntry $entry): int => $entry->occurredAt->getTimestamp())
            ->take($limit)
            ->values();
    }

    /**
     * @return Collection<int, TimelineEntry>
     */
    private static function activities(Donor $donor, int $limit): Collection
    {
        return DonorActivity::query()->where('donor_id', $donor->id)
            ->with('assignedTo')
            ->latest('created_at')->limit($limit)->get()
            ->map(fn (DonorActivity $activity): TimelineEntry => new TimelineEntry(
                source: 'Actividad',
                occurredAt: $activity->completed_at ?? $activity->scheduled_at ?? $activity->created_at,
                title: $activity->type->getLabel().': '.$activity->subject,
                description: $activity->status->getLabel(),
                actorName: $activity->assignedTo->name,
                url: ActivityResource::getUrl('view', ['record' => $activity->id]),
            ));
    }

    /**
     * @return Collection<int, TimelineEntry>
     */
    private static function tasks(Donor $donor, int $limit): Collection
    {
        return Task::query()->where('donor_id', $donor->id)
            ->with('assignedTo')
            ->latest('created_at')->limit($limit)->get()
            ->map(fn (Task $task): TimelineEntry => new TimelineEntry(
                source: 'Tarea',
                occurredAt: $task->completed_at ?? $task->created_at,
                title: $task->title,
                description: $task->status->getLabel(),
                actorName: $task->assignedTo->name,
                url: TaskResource::getUrl('view', ['record' => $task->id]),
            ));
    }

    /**
     * @return Collection<int, TimelineEntry>
     */
    private static function assignments(Donor $donor, int $limit): Collection
    {
        return DonorAssignment::query()->where('donor_id', $donor->id)
            ->with(['user', 'assignedBy'])
            ->latest('started_at')->limit($limit)->get()
            ->map(fn (DonorAssignment $assignment): TimelineEntry => new TimelineEntry(
                source: 'Responsable',
                occurredAt: $assignment->started_at,
                title: 'Responsable asignado: '.$assignment->user->name,
                description: $assignment->ended_at !== null ? 'Vigente hasta '.$assignment->ended_at->format('d/m/Y') : 'Vigente',
                actorName: $assignment->assignedBy->name,
            ));
    }

    /**
     * @return Collection<int, TimelineEntry>
     */
    private static function donations(Donor $donor, int $limit): Collection
    {
        return Donation::query()->where('donor_id', $donor->id)
            ->latest('created_at')->limit($limit)->get()
            ->map(fn (Donation $donation): TimelineEntry => new TimelineEntry(
                source: 'Donativo',
                occurredAt: $donation->created_at,
                title: 'Donativo de '.Money::format($donation->amount),
                description: $donation->status->getLabel(),
                url: DonationResource::getUrl('view', ['record' => $donation->id]),
            ));
    }

    /**
     * @return Collection<int, TimelineEntry>
     */
    private static function payments(Donor $donor, int $limit): Collection
    {
        return Payment::query()->where('donor_id', $donor->id)
            ->latest('created_at')->limit($limit)->get()
            ->map(fn (Payment $payment): TimelineEntry => new TimelineEntry(
                source: 'Pago',
                occurredAt: $payment->created_at,
                title: 'Pago de '.Money::format($payment->amount),
                description: $payment->status->getLabel(),
                url: PaymentResource::getUrl('view', ['record' => $payment->id]),
            ));
    }

    /**
     * @return Collection<int, TimelineEntry>
     */
    private static function failedAttempts(Donor $donor, int $limit): Collection
    {
        $paymentIds = Payment::query()->where('donor_id', $donor->id)->pluck('id');

        return PaymentAttempt::query()->whereIn('payment_id', $paymentIds)
            ->where('status', PaymentAttemptStatus::Failed->value)
            ->with('payment')
            ->latest('created_at')->limit($limit)->get()
            ->map(fn (PaymentAttempt $attempt): TimelineEntry => new TimelineEntry(
                source: 'Intento fallido',
                occurredAt: $attempt->created_at,
                title: 'Intento de pago fallido',
                description: $attempt->provider_message,
                url: PaymentResource::getUrl('view', ['record' => $attempt->payment_id]),
            ));
    }

    /**
     * @return Collection<int, TimelineEntry>
     */
    private static function subscriptions(Donor $donor, int $limit): Collection
    {
        return Subscription::query()->where('donor_id', $donor->id)
            ->latest('created_at')->limit($limit)->get()
            ->map(fn (Subscription $subscription): TimelineEntry => new TimelineEntry(
                source: 'Donativo mensual',
                occurredAt: $subscription->created_at,
                title: 'Donativo mensual de '.Money::format($subscription->amount),
                description: $subscription->status->getLabel(),
                url: SubscriptionResource::getUrl('view', ['record' => $subscription->id]),
            ));
    }

    /**
     * @return Collection<int, TimelineEntry>
     */
    private static function communications(Donor $donor, int $limit): Collection
    {
        return Communication::query()->where('donor_id', $donor->id)
            ->latest('created_at')->limit($limit)->get()
            ->map(fn (Communication $communication): TimelineEntry => new TimelineEntry(
                source: 'Comunicación',
                occurredAt: $communication->created_at,
                title: $communication->kind->getLabel(),
                description: $communication->status->getLabel(),
                url: CommunicationResource::getUrl('view', ['record' => $communication->id]),
            ));
    }

    /**
     * @return Collection<int, TimelineEntry>
     */
    private static function paymentRequests(Donor $donor, int $limit): Collection
    {
        return PaymentRequest::query()->where('donor_id', $donor->id)
            ->latest('created_at')->limit($limit)->get()
            ->map(fn (PaymentRequest $request): TimelineEntry => new TimelineEntry(
                source: 'Solicitud de pago',
                occurredAt: $request->created_at,
                title: 'Cobro con tarjeta de '.Money::format($request->amount),
                description: $request->effectiveStatus()->getLabel(),
                url: PaymentRequestResource::getUrl('view', ['record' => $request->id]),
            ));
    }

    /**
     * @return Collection<int, TimelineEntry>
     */
    private static function refunds(Donor $donor, int $limit): Collection
    {
        $paymentIds = Payment::query()->where('donor_id', $donor->id)->pluck('id');

        return Refund::query()->whereIn('payment_id', $paymentIds)
            ->latest('requested_at')->limit($limit)->get()
            ->map(fn (Refund $refund): TimelineEntry => new TimelineEntry(
                source: 'Reembolso',
                occurredAt: $refund->requested_at,
                title: 'Reembolso de '.Money::format($refund->amount),
                description: $refund->status->getLabel(),
                url: RefundResource::getUrl('view', ['record' => $refund->id]),
            ));
    }

    /**
     * @return Collection<int, TimelineEntry>
     */
    private static function disputes(Donor $donor, int $limit): Collection
    {
        $paymentIds = Payment::query()->where('donor_id', $donor->id)->pluck('id');

        return PaymentDispute::query()->whereIn('payment_id', $paymentIds)
            ->latest('opened_at')->limit($limit)->get()
            ->map(fn (PaymentDispute $dispute): TimelineEntry => new TimelineEntry(
                source: 'Disputa',
                occurredAt: $dispute->opened_at ?? $dispute->created_at,
                title: 'Disputa de pago',
                description: $dispute->status->getLabel(),
                url: PaymentDisputeResource::getUrl('view', ['record' => $dispute->id]),
            ));
    }

    /**
     * @return Collection<int, TimelineEntry>
     */
    private static function incidents(Donor $donor, int $limit): Collection
    {
        $paymentIds = Payment::query()->where('donor_id', $donor->id)->pluck('id');
        $subscriptionIds = Subscription::query()->where('donor_id', $donor->id)->pluck('id');

        return PaymentIncident::query()
            ->where(fn (Builder $query): Builder => $query->whereIn('payment_id', $paymentIds)->orWhereIn('subscription_id', $subscriptionIds))
            ->latest('detected_at')->limit($limit)->get()
            ->map(fn (PaymentIncident $incident): TimelineEntry => new TimelineEntry(
                source: 'Incidencia',
                occurredAt: $incident->detected_at,
                title: $incident->type->getLabel(),
                description: $incident->status->getLabel(),
                url: PaymentIncidentResource::getUrl('view', ['record' => $incident->id]),
            ));
    }

    /**
     * @return Collection<int, TimelineEntry>
     */
    private static function receipts(Donor $donor, int $limit): Collection
    {
        $donationIds = Donation::query()->where('donor_id', $donor->id)->pluck('id');

        return DonationReceipt::query()->whereIn('donation_id', $donationIds)
            ->latest('issued_at')->limit($limit)->get()
            ->map(fn (DonationReceipt $receipt): TimelineEntry => new TimelineEntry(
                source: 'Recibo',
                occurredAt: $receipt->issued_at,
                title: 'Recibo simple emitido',
                description: $receipt->folio,
                url: DonationResource::getUrl('view', ['record' => $receipt->donation_id]),
            ));
    }

    /**
     * @return Collection<int, TimelineEntry>
     */
    private static function externalCfdis(Donor $donor, int $limit): Collection
    {
        $donationIds = Donation::query()->where('donor_id', $donor->id)->pluck('id');

        return ExternalCfdi::query()->whereIn('donation_id', $donationIds)
            ->latest('uploaded_at')->limit($limit)->get()
            ->map(fn (ExternalCfdi $cfdi): TimelineEntry => new TimelineEntry(
                source: 'CFDI externo',
                occurredAt: $cfdi->uploaded_at,
                title: $cfdi->removed_at !== null ? 'CFDI externo retirado' : 'CFDI externo adjuntado',
                description: $cfdi->uuid,
                url: DonationResource::getUrl('view', ['record' => $cfdi->donation_id]),
            ));
    }

    /**
     * @return Collection<int, TimelineEntry>
     */
    private static function accountingNotices(Donor $donor, int $limit): Collection
    {
        $donationIds = Donation::query()->where('donor_id', $donor->id)->pluck('id');

        return AccountingNotice::query()->whereIn('donation_id', $donationIds)
            ->latest('created_at')->limit($limit)->get()
            ->map(fn (AccountingNotice $notice): TimelineEntry => new TimelineEntry(
                source: 'Aviso a Contabilidad',
                occurredAt: $notice->created_at,
                title: 'Aviso a Contabilidad',
                description: $notice->status->getLabel(),
                url: DonationResource::getUrl('view', ['record' => $notice->donation_id]),
            ));
    }
}
