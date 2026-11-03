<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One customer is one company in a NextBook. `status` holds the part of the
 * life cycle that staff decide; the running state of its subscription
 * (expiring, expired, blocked) is derived from dates, see Subscription::state().
 */
class Customer extends Model
{
    use HasUlids, SoftDeletes;

    public const STATUSES = ['lead', 'trial', 'active', 'cancelled', 'archived'];

    // Only staff forms, validated, write to this model.
    protected $guarded = [];

    protected $casts = [
        'tags' => 'array',
        'cancelled_on' => 'date',
        'archived_on' => 'date',
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function instance(): HasOne
    {
        return $this->hasOne(Instance::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function usageSnapshots(): HasMany
    {
        return $this->hasMany(UsageSnapshot::class);
    }

    public function displayName(): string
    {
        return $this->name_fa ?: $this->name_en;
    }
}
