<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasUlids;

    protected $guarded = [];

    public const ROLES = ['manager', 'finance', 'technical', 'other'];

    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected $casts = [
        'is_primary' => 'boolean',
        'wants_email' => 'boolean',
        'wants_whatsapp' => 'boolean',
    ];
}
