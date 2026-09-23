<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Cfdi\CloseGlobalCfdiPeriod;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CloseGlobalCfdiPeriods implements ShouldQueue
{
    use Queueable;

    public function handle(CloseGlobalCfdiPeriod $close): void
    {
        $close->handle();
    }
}
