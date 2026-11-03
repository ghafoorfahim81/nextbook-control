<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The trail of who did what. Read only, and for the owner (audit.view). */
class AuditController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('audit.view');

        $filters = [
            'action' => trim((string) $request->query('action', '')),
            'customer' => trim((string) $request->query('customer', '')),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
        ];

        $logs = AuditLog::query()
            ->with(['staff:id,name', 'customer' => fn ($query) => $query->withTrashed()->select('id', 'name_en', 'name_fa')])
            ->when($filters['action'] !== '', fn ($query) => $query->where('action', 'like', $filters['action'].'%'))
            ->when($filters['customer'] !== '', fn ($query) => $query->whereHas('customer', fn ($inner) => $inner->withTrashed()
                ->where(fn ($q) => $q->where('name_en', 'ilike', "%{$filters['customer']}%")->orWhere('name_fa', 'ilike', "%{$filters['customer']}%"))))
            ->when($filters['from'] !== '', fn ($query) => $query->whereDate('created_at', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn ($query) => $query->whereDate('created_at', '<=', $filters['to']))
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'at' => $log->created_at->toDateTimeString(),
                'staff' => $log->staff?->name,
                'action' => $log->action,
                'subject' => $log->subject_type,
                'customer_id' => $log->customer_id,
                'customer' => $log->customer?->displayName(),
                'reason' => $log->reason,
                'before' => $log->before,
                'after' => $log->after,
                'ip' => $log->ip,
            ]);

        return inertia('Audit/Index', [
            'logs' => $logs,
            'filters' => $filters,
            // The first part of each action ("subscription", "invoice"…) is what is filtered on.
            'groups' => AuditLog::query()->distinct()->pluck('action')->map(fn ($a) => explode('.', $a)[0])->unique()->sort()->values(),
        ]);
    }
}
