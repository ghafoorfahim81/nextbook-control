<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The record of who did what. Append only: a row can be added and read, never
 * changed or removed, so the trail can be trusted.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['before' => 'array', 'after' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log rows cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit log rows cannot be deleted.'));
    }
}
