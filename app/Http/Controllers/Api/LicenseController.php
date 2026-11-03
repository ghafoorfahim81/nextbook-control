<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ControlException;
use App\Http\Controllers\Controller;
use App\Models\Instance;
use App\Services\LicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LicenseController extends Controller
{
    public function __construct(private readonly LicenseService $licenses)
    {
    }

    /** The licence for the calling ERP's customer, signed. */
    public function show(Request $request): JsonResponse
    {
        /** @var Instance $instance */
        $instance = $request->attributes->get('instance');

        try {
            $license = $this->licenses->issue($instance->customer);
        } catch (ControlException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        $instance->forceFill(['last_license_pull_at' => now()])->save();

        return response()->json($license + ['grace_days' => config('control.grace_days')]);
    }
}
