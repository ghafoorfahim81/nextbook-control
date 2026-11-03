<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Package extends Model
{
    use HasUlids, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'modules' => 'array',
        'is_active' => 'boolean',
    ];

    public function prices(): HasMany
    {
        return $this->hasMany(PackagePrice::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** The price in force on a day, in a currency: the latest one that has started. */
    public function priceOn(?Carbon $day = null, string $currency = 'AFN'): ?PackagePrice
    {
        return $this->prices()
            ->where('currency', $currency)
            ->whereDate('effective_from', '<=', ($day ?? now())->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at')
            ->first();
    }
}
