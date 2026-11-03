<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasUlids;

    public const METHODS = ['cash', 'hawala', 'bank', 'other'];

    protected $guarded = [];

    protected $casts = ['paid_on' => 'date'];
}
