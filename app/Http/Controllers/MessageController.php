<?php

namespace App\Http\Controllers;

use App\Jobs\SendMessageJob;
use App\Models\Message;
use App\Services\Audit;
use Illuminate\Support\Facades\Gate;

class MessageController extends Controller
{
    /**
     * Tries a failed message again, after whatever made it fail has been put
     * right (a number corrected, WhatsApp set up). Only a failed one.
     */
    public function retry(Message $message)
    {
        Gate::authorize('customers.manage');

        if ($message->status !== 'failed') {
            return back()->with('error', __('control.message.only_failed'));
        }

        $message->update(['status' => 'queued', 'error' => null, 'attempts' => 0]);
        SendMessageJob::dispatch($message->id);
        Audit::record('message.retried', $message, null, ['kind' => $message->kind, 'channel' => $message->channel], $message->customer_id);

        return back()->with('success', __('control.saved'));
    }
}
