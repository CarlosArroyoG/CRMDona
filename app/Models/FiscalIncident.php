<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalIncident extends Model
{
    protected $guarded = ['id'];

    /** @return BelongsTo<Donation, $this> */
    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
