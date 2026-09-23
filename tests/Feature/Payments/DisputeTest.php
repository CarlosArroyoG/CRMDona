<?php

declare(strict_types=1);

use App\Enums\DisputeStatus;
use App\Enums\DonationStatus;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentType;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\WebhookEventStatus;
use App\Models\Donation;
use App\Models\PaymentDispute;
use App\Models\PaymentIncident;
use App\Models\WebhookEvent;
use App\Payments\Gateways\FakeScenario;
use Illuminate\Notifications\DatabaseNotification;

it('una disputa nueva se registra, abre una incidencia crítica y alerta una sola vez', function (): void {
    $admin = userWithRole(Role::Administrator);
    $payment = startFakeDonation(['amount' => '500.00'], FakeScenario::Success);
    $disputeId = fakeGateway()->openDispute((string) $payment->external_id);

    deliverFakeWebhook('charge.dispute.created', 'dispute', $disputeId, 'evt_d1');
    deliverFakeWebhook('charge.dispute.created', 'dispute', $disputeId, 'evt_d1');
    deliverFakeWebhook('charge.dispute.updated', 'dispute', $disputeId, 'evt_d2');

    $dispute = PaymentDispute::query()->sole();
    expect($dispute->payment_id)->toBe($payment->id)
        ->and($dispute->status)->toBe(DisputeStatus::Open)
        ->and($dispute->external_id)->toBe($disputeId)
        ->and($dispute->provider_reason)->toBe('fraudulent')
        ->and($dispute->evidence_due_at)->not->toBeNull();

    $incident = PaymentIncident::query()->sole();
    expect($incident->type)->toBe(IncidentType::DisputeOpened)
        ->and($incident->severity)->toBe(IncidentSeverity::Critical)
        ->and($incident->dispute_id)->toBe($dispute->id)
        ->and(DatabaseNotification::query()->where('notifiable_id', $admin->id)->count())->toBe(1);
});

it('no modifica el pago ni el donativo', function (): void {
    $payment = startFakeDonation(['amount' => '500.00'], FakeScenario::Success);
    $disputeId = fakeGateway()->openDispute((string) $payment->external_id);
    deliverFakeWebhook('charge.dispute.created', 'dispute', $disputeId);

    fakeGateway()->setDisputeStatus($disputeId, DisputeStatus::Lost);
    deliverFakeWebhook('charge.dispute.closed', 'dispute', $disputeId);

    expect(PaymentDispute::query()->sole()->status)->toBe(DisputeStatus::Lost)
        ->and(PaymentDispute::query()->sole()->closed_at)->not->toBeNull()
        ->and($payment->fresh()?->status)->toBe(PaymentStatus::Succeeded)
        ->and(Donation::query()->sole()->status)->toBe(DonationStatus::Confirmed);
});

it('sigue el ciclo del proveedor, incluida una disputa perdida que luego se gana', function (): void {
    $payment = startFakeDonation([], FakeScenario::Success);
    $disputeId = fakeGateway()->openDispute((string) $payment->external_id);
    deliverFakeWebhook('charge.dispute.created', 'dispute', $disputeId);

    foreach ([DisputeStatus::UnderReview, DisputeStatus::Lost, DisputeStatus::Won] as $status) {
        fakeGateway()->setDisputeStatus($disputeId, $status);
        deliverFakeWebhook('charge.dispute.updated', 'dispute', $disputeId);
        expect(PaymentDispute::query()->sole()->status)->toBe($status);
    }

    expect(PaymentIncident::query()->count())->toBe(1);
});

it('una disputa sobre un pago aún desconocido se reintenta y queda fallida si nunca aparece', function (): void {
    $payment = startFakeDonation([], FakeScenario::Success);
    $disputeId = fakeGateway()->openDispute((string) $payment->external_id);
    $payment->forceFill(['external_id' => 'otro_id'])->saveQuietly();
    useDatabaseQueue(tries: 1);

    deliverFakeWebhook('charge.dispute.created', 'dispute', $disputeId)->assertOk();
    runQueueWorker();

    expect(WebhookEvent::query()->sole()->status)->toBe(WebhookEventStatus::Failed)
        ->and(WebhookEvent::query()->sole()->last_error)->toContain('todavía no conoce')
        ->and(PaymentIncident::query()->sole()->type)->toBe(IncidentType::WebhookUnprocessable);
});
