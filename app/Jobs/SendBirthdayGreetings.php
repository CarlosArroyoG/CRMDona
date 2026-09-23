<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Communications\QueueCommunication;
use App\Enums\CommunicationKind;
use App\Models\Donor;
use App\Models\OrganizationSetting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Felicitaciones del día (programado a las 09:00 America/Mexico_City).
 * Solo donantes con consentimiento de comunicaciones vigente, no archivados y
 * con correo. Una por donante y año (`birthday:donor:{id}:{año}`): si el
 * scheduler corre dos veces, no se duplica. Quien nació un 29 de febrero se
 * felicita el 28 en años no bisiestos.
 */
class SendBirthdayGreetings implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function handle(QueueCommunication $queue): void
    {
        if (! OrganizationSetting::current()->birthday_emails_enabled) {
            return;
        }

        $today = CarbonImmutable::now(config()->string('communications.timezone'));
        $days = [$today->day];
        if ($today->month === 2 && $today->day === 28 && ! $today->isLeapYear()) {
            $days[] = 29;
        }

        Donor::query()
            ->whereNotNull('birth_date')->whereNull('archived_at')->whereNotNull('email')
            ->where('accepts_communications', true)
            ->whereRaw('extract(month from birth_date) = ?', [$today->month])
            ->whereRaw('extract(day from birth_date)::int in ('.implode(', ', array_fill(0, count($days), '?')).')', $days)
            ->orderBy('id')
            ->each(fn (Donor $donor) => $queue->handle(CommunicationKind::Birthday, $donor, "birthday:donor:{$donor->id}:{$today->year}"));
    }
}
