<?php

declare(strict_types=1);

use App\Actions\Accounting\QueueAccountingNotice;
use App\Actions\Communications\IssueDonationReceipt;
use App\Actions\Communications\QueueDonationThankYou;
use App\Actions\Communications\ResendCommunication;
use App\Actions\Donations\ConfirmDonation;
use App\Actions\Users\SetAccountingNoticePreference;
use App\Enums\AccountingNoticeStatus;
use App\Enums\CommunicationKind;
use App\Enums\CommunicationStatus;
use App\Enums\DonationStatus;
use App\Enums\ManualPaymentMethod;
use App\Enums\Role;
use App\Enums\TaxRegime;
use App\Jobs\SendAccountingNotice;
use App\Jobs\SendCommunication;
use App\Mail\DonorMessage;
use App\Models\AccountingNotice;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Communication;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Donor;
use App\Models\OrganizationSetting;
use App\Models\User;
use App\Payments\Gateways\FakeScenario;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/*
 * Flujo definitivo al confirmar un donativo (docs/tecnico/cfdi-externo.md):
 * recibo simple → agradecimiento con el recibo → aviso a Contabilidad. El CRM
 * no emite CFDI; nada espera un CFDI.
 */

beforeEach(function (): void {
    Mail::fake();
    OrganizationSetting::current()->forceFill(['legal_name' => 'FUNDACION DE PRUEBA', 'rfc' => 'FPR010101AAA'])->save();
});

function accountingRecipient(Role $role = Role::Accountant): User
{
    $user = userWithRole($role);
    app(SetAccountingNoticePreference::class)->handle($user, true, userWithRole(Role::Administrator));

    return $user;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function manualDonation(array $attributes = [], bool $taxProfile = false): Donation
{
    $donor = ($taxProfile ? Donor::factory()->withTaxProfile() : Donor::factory())->create(['email' => 'donante@example.com']);

    return Donation::factory()->create(['donor_id' => $donor->id, 'manual_payment_method' => ManualPaymentMethod::BankTransfer, 'amount' => '2500.00', ...$attributes]);
}

/**
 * @return list<DonorMessage>
 */
function mailsTo(string $email): array
{
    /** @var list<DonorMessage> $mails */
    $mails = Mail::sent(DonorMessage::class, fn (DonorMessage $mail): bool => $mail->hasTo($email))->values()->all();

    return $mails;
}

it('donativo manual confirmado: un recibo con folio único y PDF privado, agradecimiento con el recibo, historial, reenvío sin recibo nuevo e idempotencia', function (): void {
    $accountant = accountingRecipient();
    $donation = app(ConfirmDonation::class)->handle(manualDonation(), $accountant);                                // 1

    $receipt = DonationReceipt::query()->where('donation_id', $donation->id)->sole();                            // 2
    expect($receipt->folio)->toBe(DonationReceipt::folioFor($receipt->id))                                      // 3
        ->and(DonationReceipt::query()->where('folio', $receipt->folio)->count())->toBe(1);
    $pdf = (string) Storage::disk('local')->get((string) $receipt->pdf_path);                                     // 4
    expect($pdf)->toStartWith('%PDF-')->toContain((string) $receipt->folio)->toContain('$2,500.00 MXN')->toContain('No es una factura')
        ->and(Storage::disk('public')->exists((string) $receipt->pdf_path))->toBeFalse();                         // 5

    $thankYou = mailsTo('donante@example.com');                                                                  // 6
    expect($thankYou)->toHaveCount(1)
        ->and($thankYou[0]->message->attachmentNames())->toBe(["Recibo-{$receipt->folio}.pdf"]);                // 7
    $communication = Communication::query()->where('kind', CommunicationKind::ThankYou->value)->sole();          // 8
    expect($communication->status)->toBe(CommunicationStatus::Sent)->and($communication->donation_id)->toBe($donation->id)
        ->and($communication->attachments)->toBe(["Recibo-{$receipt->folio}.pdf"]);

    $resent = app(ResendCommunication::class)->handle($communication, $accountant);                              // 9
    expect($resent->refresh()->status)->toBe(CommunicationStatus::Sent)
        ->and(mailsTo('donante@example.com'))->toHaveCount(2)
        ->and(mailsTo('donante@example.com')[1]->message->attachmentNames())->toBe(["Recibo-{$receipt->folio}.pdf"])
        ->and(DonationReceipt::query()->count())->toBe(1);

    app(QueueDonationThankYou::class)->handle($donation);                                                        // 10
    app(QueueAccountingNotice::class)->handle($donation);
    dispatch_sync(new SendCommunication($communication->id));
    dispatch_sync(new SendAccountingNotice($donation->accountingNotice()->sole()->id));
    expect(app(IssueDonationReceipt::class)->handle($donation)->id)->toBe($receipt->id)
        ->and(DonationReceipt::query()->count())->toBe(1)
        ->and(mailsTo('donante@example.com'))->toHaveCount(2)
        ->and(mailsTo($accountant->email))->toHaveCount(1)
        ->and(AccountingNotice::query()->count())->toBe(1);
});

it('donativo en línea confirmado y cada mensualidad: su propio donativo, recibo, agradecimiento y aviso contable', function (): void {
    $accountant = accountingRecipient();
    $donor = Donor::factory()->create(['email' => 'enlinea@example.com']);

    $payment = startFakeDonation(['donor_id' => $donor->id], FakeScenario::Success);                              // 11
    $online = Donation::query()->where('payment_id', $payment->id)->sole();
    expect($online->status)->toBe(DonationStatus::Confirmed)->and($online->receipt)->not->toBeNull()
        ->and(mailsTo('enlinea@example.com'))->toHaveCount(1)
        ->and($online->accountingNotice?->status)->toBe(AccountingNoticeStatus::Sent);

    $subscription = startFakeMonthlyDonation(['donor_id' => $donor->id, 'tax_receipt_requested' => true], FakeScenario::Success);
    $next = CarbonImmutable::now()->startOfMonth()->addMonth();
    $chargeId = fakeGateway()->chargeSubscription((string) $subscription->external_id, $next, FakeScenario::Success);  // 12
    deliverFakeWebhook('invoice.paid', 'payment', $chargeId)->assertOk();

    $monthly = Donation::query()->whereHas('payment', fn ($query) => $query->where('subscription_id', $subscription->id))->orderBy('id')->get();
    expect($monthly)->toHaveCount(2)
        ->and($monthly->pluck('receipt.folio')->unique()->filter()->count())->toBe(2)
        ->and($monthly->every(fn (Donation $donation): bool => $donation->tax_receipt_requested))->toBeTrue()
        ->and(mailsTo('enlinea@example.com'))->toHaveCount(3)
        ->and(AccountingNotice::query()->whereIn('donation_id', $monthly->pluck('id'))->where('status', 'sent')->count())->toBe(2)
        ->and(mailsTo($accountant->email))->toHaveCount(3);
});

it('un fallo del correo no afecta la confirmación ni el recibo; el reintento envía cada correo una sola vez', function (): void {
    $accountant = accountingRecipient();
    $manager = new MailManager(app());
    $manager->extend('failing', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('SMTP 421 servicio no disponible');
        }

        public function __toString(): string
        {
            return 'failing';
        }
    });
    config(['mail.default' => 'failing', 'mail.mailers.failing' => ['transport' => 'failing']]);
    Mail::swap($manager);

    $donation = app(ConfirmDonation::class)->handle(manualDonation(), $accountant);                               // 13

    $notice = $donation->accountingNotice()->sole();
    $communication = Communication::query()->sole();
    expect($donation->refresh()->status)->toBe(DonationStatus::Confirmed)
        ->and($donation->receipt)->not->toBeNull()
        ->and($communication->status)->toBe(CommunicationStatus::Failed)
        ->and($notice->status)->toBe(AccountingNoticeStatus::Failed)->and($notice->last_error)->toContain('SMTP 421');

    Mail::fake();
    dispatch_sync(new SendCommunication($communication->id));
    dispatch_sync(new SendAccountingNotice($notice->id));
    dispatch_sync(new SendAccountingNotice($notice->id));

    expect($communication->refresh()->status)->toBe(CommunicationStatus::Sent)
        ->and($notice->refresh()->status)->toBe(AccountingNoticeStatus::Sent)
        ->and(mailsTo('donante@example.com'))->toHaveCount(1)
        ->and(mailsTo($accountant->email))->toHaveCount(1)
        ->and(DonationReceipt::query()->count())->toBe(1);
});

it('sin CFDI el agradecimiento no se retrasa ni lo menciona como pendiente', function (): void {
    config(['queue.default' => 'database']);
    $donation = app(ConfirmDonation::class)->handle(manualDonation(['tax_receipt_requested' => true], taxProfile: true), accountingRecipient());

    runQueueWorker();                                                                                             // 14

    expect(Communication::query()->sole()->status)->toBe(CommunicationStatus::Sent)
        ->and($donation->externalCfdis()->exists())->toBeFalse()
        ->and(mailsTo('donante@example.com')[0]->message->notices)->toBe([IssueDonationReceipt::DISCLAIMER]);
});

it('aviso a Contabilidad con CFDI solicitado: folio, donante, fecha, importe, destino, forma de pago y datos fiscales; el donante no los recibe', function (): void {
    $accountant = accountingRecipient();
    $admin = accountingRecipient(Role::Administrator);
    $optedOut = userWithRole(Role::Accountant);
    $campaign = Campaign::factory()->create(['name' => 'Becas 2026']);
    $donation = manualDonation(['tax_receipt_requested' => true, 'campaign_id' => $campaign->id, 'received_on' => '2026-09-15'], taxProfile: true);
    $donation->donor->taxProfile?->forceFill(['rfc' => 'LOHM800101AB1', 'tax_name' => 'MARIA LOPEZ', 'tax_regime' => TaxRegime::Wages, 'tax_postal_code' => '62000'])->save();

    $donation = app(ConfirmDonation::class)->handle($donation, $accountant);

    $folio = (string) $donation->receipt?->folio;
    $notice = mailsTo($accountant->email)[0]->message;
    expect($notice->subject)->toContain($folio)->toContain('CFDI solicitado: SÍ')
        ->and($notice->body)->toContain("Folio del recibo simple: {$folio}")->toContain('Donativo: #'.$donation->id)
        ->toContain('Donante: '.$donation->donor->display_name)->toContain('Fecha de recepción: 15/09/2026')
        ->toContain('Importe: $2,500.00 MXN')->toContain('Campaña "Becas 2026"')->toContain('Forma de pago: Transferencia bancaria')
        ->toContain('CFDI solicitado: SÍ')->toContain('RFC: LOHM800101AB1')->toContain('MARIA LOPEZ')
        ->toContain('605 - Sueldos y Salarios')->toContain('Código postal fiscal: 62000')
        ->and(mailsTo($admin->email))->toHaveCount(1)
        ->and(mailsTo($optedOut->email))->toHaveCount(0);

    $toDonor = mailsTo('donante@example.com')[0]->message;
    expect(str_contains($toDonor->body, 'LOHM800101AB1'))->toBeFalse()
        ->and(str_contains($toDonor->body, 'Régimen'))->toBeFalse()
        ->and(str_contains((string) Storage::disk('local')->get((string) $donation->receipt?->pdf_path), 'LOHM800101AB1'))->toBeFalse();

    $record = $donation->accountingNotice()->sole();
    expect($record->delivered_to)->toBe([$accountant->id, $admin->id])
        ->and(AuditLog::query()->where('auditable_type', 'accounting_notice')->where('auditable_id', $record->id)->exists())->toBeTrue();
});

it('aviso sin CFDI solicitado: dice NO, no incluye datos fiscales y no clasifica el donativo como factura global', function (): void {
    $accountant = accountingRecipient();

    app(ConfirmDonation::class)->handle(manualDonation(taxProfile: true), $accountant);

    $notice = mailsTo($accountant->email)[0]->message;
    expect($notice->subject)->toContain('CFDI solicitado: NO')
        ->and($notice->body)->toContain('CFDI solicitado: NO')->toContain('lo decide Contabilidad')
        ->and(str_contains($notice->body, 'RFC:'))->toBeFalse()
        ->and(str_contains($notice->body, 'factura global'))->toBeFalse();
});

it('CFDI solicitado sin datos fiscales capturados: el aviso lo dice sin inventarlos', function (): void {
    $accountant = accountingRecipient();

    app(ConfirmDonation::class)->handle(manualDonation(['tax_receipt_requested' => true]), $accountant);

    expect(mailsTo($accountant->email)[0]->message->body)->toContain('CFDI solicitado: SÍ')->toContain('no tiene datos fiscales capturados');
});

it('solo Administrador o Contador pueden recibir avisos contables, y solo el Administrador lo configura', function (): void {
    $coordinator = userWithRole(Role::FundraisingCoordinator);
    $admin = userWithRole(Role::Administrator);

    expect(fn () => app(SetAccountingNoticePreference::class)->handle($coordinator, true, $admin))->toThrow(ValidationException::class)
        ->and(fn () => app(SetAccountingNoticePreference::class)->handle(userWithRole(Role::Accountant), true, userWithRole(Role::Accountant)))
        ->toThrow(AuthorizationException::class);

    // Si el rol cambia después, deja de recibirlos aunque conserve la preferencia.
    $accountant = accountingRecipient();
    $accountant->forceFill(['role' => Role::FundraisingCoordinator])->save();
    expect(SendAccountingNotice::recipients())->toBe([]);
});
