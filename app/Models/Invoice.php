<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $casts = [
        'issued_on' => 'date',
        'due_on' => 'date',
        'period_from' => 'date',
        'period_to' => 'date',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** What is to be paid: the amount less the discount. */
    public function payable(): string
    {
        return bcsub((string) $this->amount, (string) $this->discount, 4);
    }

    public function paidAmount(): string
    {
        $paid = '0';
        foreach ($this->payments as $payment) {
            $paid = bcadd($paid, (string) $payment->amount, 4);
        }

        return $paid;
    }

    public function balance(): string
    {
        return bcsub($this->payable(), $this->paidAmount(), 4);
    }

    /** void, paid, partial or unpaid. */
    public function paymentState(): string
    {
        if ($this->status === 'void') {
            return 'void';
        }
        if (bccomp($this->balance(), '0', 4) <= 0) {
            return 'paid';
        }

        return bccomp($this->paidAmount(), '0', 4) > 0 ? 'partial' : 'unpaid';
    }
}
