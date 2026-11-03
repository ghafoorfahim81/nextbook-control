<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PackagePrice extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $casts = ['effective_from' => 'date'];
}
