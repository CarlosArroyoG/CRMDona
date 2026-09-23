<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CfdiStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GlobalCfdi extends Model
{
    protected $guarded = ['id'];

    /**
     * @return BelongsToMany<Donation, $this>
     */
    public function donations(): BelongsToMany
    {
        return $this->belongsToMany(Donation::class, 'donation_global_cfdi')
            ->withPivot('operation_number')
            ->withTimestamps();
    }

    /**
     * @return HasOne<Cfdi, $this>
     */
    public function cfdi(): HasOne
    {
        return $this->hasOne(Cfdi::class);
    }

    protected function casts(): array
    {
        return [
            'status' => CfdiStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'total' => 'decimal:2',
            'requested_at' => 'datetime',
            'stamped_at' => 'datetime',
        ];
    }
}
