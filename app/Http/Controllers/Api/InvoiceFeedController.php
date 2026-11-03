<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Instance;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The invoices and payments of the calling ERP's customer, for the "My
 * subscription" page in its NextBook. Display only: nothing in the ERP is
 * decided from them (the licence is what is signed and enforced), so they are
 * not signed. A customer sees its own and no one else's.
 */
class InvoiceFeedController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Instance $instance */
        $instance = $request->attributes->get('instance');

        $invoices = Invoice::query()->with('payments')->where('customer_id', $instance->customer_id)
            ->orderByDesc('issued_on')->orderByDesc('number')->get()
            ->map(fn (Invoice $invoice) => [
                'number' => $invoice->number,
                'issued_on' => $invoice->issued_on->toDateString(),
                'due_on' => $invoice->due_on?->toDateString(),
                'period_from' => $invoice->period_from?->toDateString(),
                'period_to' => $invoice->period_to?->toDateString(),
                'amount' => (float) $invoice->amount,
                'discount' => (float) $invoice->discount,
                'currency' => $invoice->currency,
                'description' => $invoice->description,
                'state' => $invoice->paymentState(),
                'paid' => (float) $invoice->paidAmount(),
                'balance' => (float) $invoice->balance(),
                'payments' => $invoice->payments->sortBy('paid_on')->map(fn ($payment) => [
                    'paid_on' => $payment->paid_on->toDateString(),
                    'amount' => (float) $payment->amount,
                    'method' => $payment->method,
                    'reference' => $payment->reference,
                ])->values(),
            ])->values();

        return response()->json(['invoices' => $invoices]);
    }
}
