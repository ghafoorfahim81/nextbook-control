<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Subscription extends Model
{
    use HasUlids;

    /** Days before the end at which a subscription counts as "expiring". */
    public const EXPIRING_DAYS = 14;

    protected $guarded = [];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'blocked_at' => 'datetime',
        'overrides' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(SubscriptionChange::class);
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    public function isTrial(): bool
    {
        return $this->kind === 'trial';
    }

    public function isExpired(?Carbon $on = null): bool
    {
        return $this->ends_on->lt(($on ?? now())->startOfDay());
    }

    public function daysLeft(?Carbon $on = null): int
    {
        return (int) ($on ?? now())->startOfDay()->diffInDays($this->ends_on, false);
    }

    /** blocked, expired, expiring or active. */
    public function state(?Carbon $on = null): string
    {
        return match (true) {
            $this->isBlocked() => 'blocked',
            $this->isExpired($on) => 'expired',
            $this->daysLeft($on) <= self::EXPIRING_DAYS => 'expiring',
            default => 'active',
        };
    }

    /** Whether the customer may write in its NextBook: not blocked, not expired. */
    public function allowsWrites(?Carbon $on = null): bool
    {
        return ! $this->isBlocked() && ! $this->isExpired($on);
    }

    /**
     * What the customer actually gets: the package, with any limit that was
     * changed for this customer only laid over it. A null limit is unlimited.
     *
     * @return array{modules: array<int, string>, max_users: ?int, max_branches: ?int, max_storage_mb: ?int, max_monthly_documents: ?int}
     */
    public function limits(): array
    {
        $package = $this->package;
        $overrides = $this->overrides ?? [];

        $limits = ['modules' => $overrides['modules'] ?? ($package->modules ?? [])];
        foreach (['max_users', 'max_branches', 'max_storage_mb', 'max_monthly_documents'] as $key) {
            $limits[$key] = array_key_exists($key, $overrides) ? $overrides[$key] : $package->{$key};
        }

        return $limits;
    }
}
