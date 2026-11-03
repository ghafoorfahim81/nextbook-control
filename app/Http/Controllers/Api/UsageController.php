<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Instance;
use App\Models\UsageSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageController extends Controller
{
    /**
     * What the ERP uses today, as counters. Sent more than once a day, the
     * latest wins: there is one snapshot per customer per day. Never business
     * data, only numbers.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var Instance $instance */
        $instance = $request->attributes->get('instance');

        $data = $request->validate([
            'taken_on' => ['nullable', 'date'],
            'users' => ['required', 'integer', 'min:0'],
            'branches' => ['required', 'integer', 'min:0'],
            'storage_mb' => ['nullable', 'integer', 'min:0'],
            'documents_this_month' => ['nullable', 'integer', 'min:0'],
            'last_login_at' => ['nullable', 'date'],
            'erp_version' => ['nullable', 'string', 'max:40'],
        ]);

        UsageSnapshot::query()->updateOrCreate(
            ['customer_id' => $instance->customer_id, 'taken_on' => $data['taken_on'] ?? now()->toDateString()],
            [
                'users' => $data['users'],
                'branches' => $data['branches'],
                'storage_mb' => $data['storage_mb'] ?? 0,
                'documents_this_month' => $data['documents_this_month'] ?? 0,
                'last_login_at' => $data['last_login_at'] ?? null,
            ],
        );

        $instance->forceFill(array_filter([
            'last_usage_at' => now(),
            'erp_version' => $data['erp_version'] ?? null,
        ]))->save();

        return response()->json(['ok' => true]);
    }
}
