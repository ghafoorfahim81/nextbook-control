<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Where a customer's NextBook runs. The ERP identifies itself to the control
 * center with an API token; only its hash is stored here.
 */
class Instance extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $hidden = ['api_token_hash'];

    protected $casts = [
        'last_license_pull_at' => 'datetime',
        'last_usage_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Makes a new token, stores its hash and returns the token once. */
    public function issueToken(): string
    {
        $token = Str::random(48);
        $this->forceFill(['api_token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    public static function findByToken(string $token): ?self
    {
        return self::query()->where('api_token_hash', hash('sha256', $token))->first();
    }
}
