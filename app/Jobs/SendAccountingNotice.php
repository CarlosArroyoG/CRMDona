<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Communications\AccountingNoticeComposer;
use App\Enums\AccountingNoticeStatus;
use App\Enums\Permission;
use App\Filament\Resources\Users\UserResource;
use App\Mail\DonorMessage;
use App\Mail\Outgoing\OutgoingMailConfig;
use App\Models\AccountingNotice;
use App\Models\User;
use App\Support\OperationalAlerts;
use App\Support\SensitiveData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envía el aviso de un donativo confirmado a Contabilidad
 * (docs/tecnico/cfdi-externo.md).
 *
 * - Solo un proceso lo toma (UPDATE condicional, como SendCommunication).
 * - Destinatarios: usuarios activos con `accounting.process` y la
 *   preferencia "Recibe avisos a Contabilidad". Nunca direcciones fijas.
 * - Un reintento no repite el correo a quien ya lo recibió (`delivered_to`).
 * - Un fallo deja "Fallido" y la cola reintenta; nunca toca el donativo, el
 *   recibo ni el agradecimiento.
 */
class SendAccountingNotice implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /** @var list<int> */
    public array $backoff;

    public function __construct(public readonly int $noticeId)
    {
        $this->tries = config()->integer('communications.sending.tries');
        /** @var list<int> $backoff */
        $backoff = config()->array('communications.sending.backoff');
        $this->backoff = $backoff;
    }

    public function handle(AccountingNoticeComposer $composer): void
    {
        $claimed = DB::table('accounting_notices')->where('id', $this->noticeId)
            ->where(fn ($query) => $query->whereIn('status', [AccountingNoticeStatus::Pending->value, AccountingNoticeStatus::Failed->value])
                ->orWhere(fn ($stuck) => $stuck->where('status', AccountingNoticeStatus::Sending->value)
                    ->where('updated_at', '<=', now()->subMinutes(config()->integer('communications.sending.stuck_after_minutes')))))
            ->update(['status' => AccountingNoticeStatus::Sending->value, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $notice = AccountingNotice::query()->with('donation')->findOrFail($this->noticeId);
        $recipients = self::recipients();

        if ($recipients === []) {
            $notice->forceFill([
                'status' => AccountingNoticeStatus::Skipped,
                'skip_reason' => 'No hay usuarios de Contabilidad configurados para recibir avisos.',
            ])->save();

            rescue(fn () => OperationalAlerts::send(
                'accounting:no-recipients:'.now()->toDateString(),
                Permission::ManageUsers,
                'Nadie recibe los avisos a Contabilidad',
                ['Activa "Recibe avisos a Contabilidad" en un Administrador o Contador (Usuarios, Editar). Los avisos no enviados se reenvían desde "Control contable".'],
                UserResource::getUrl('index', panel: 'admin'),
                'warning',
            ));

            return;
        }

        try {
            $message = $composer->compose($notice->donation);
            $delivered = $notice->delivered_to;

            foreach ($recipients as $user) {
                if (in_array($user->id, $delivered, true)) {
                    continue;
                }

                Mail::to((string) $user->email)->send(new DonorMessage($message));
                $delivered[] = $user->id;
                $notice->forceFill(['delivered_to' => $delivered])->save();
            }
        } catch (Throwable $exception) {
            $notice->forceFill([
                'status' => AccountingNoticeStatus::Failed,
                'last_error' => SensitiveData::safeText(app(OutgoingMailConfig::class)->scrub($exception->getMessage()), 500),
            ])->save();

            throw $exception;
        }

        $notice->forceFill([
            'status' => AccountingNoticeStatus::Sent,
            'sent_at' => now(),
            'skip_reason' => null,
            'last_error' => null,
        ])->save();
    }

    /**
     * Usuarios de Contabilidad autorizados y configurados para recibir avisos.
     *
     * @return list<User>
     */
    public static function recipients(): array
    {
        return array_values(User::query()->whereNotNull('role')->whereNull('deactivated_at')->where('receives_accounting_notices', true)
            ->whereNotNull('email')->orderBy('id')->get()
            ->filter(fn (User $user): bool => $user->hasPermission(Permission::ProcessAccounting))
            ->all());
    }
}
