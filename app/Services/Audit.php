<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the audit trail. Every change a staff member makes to a customer, a
 * package, a subscription or money goes through here, with the values before
 * and after.
 */
class Audit
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(string $action, ?Model $subject = null, ?array $before = null, ?array $after = null, ?string $customerId = null, ?string $reason = null): AuditLog
    {
        return AuditLog::query()->create([
            'staff_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'customer_id' => $customerId ?? ($subject?->customer_id ?? ($subject instanceof \App\Models\Customer ? $subject->id : null)),
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip' => request()?->ip(),
        ]);
    }
}
