<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'supplier_id',
        'bank_account_id',
        'scan_bill_id',
        'current_journal_entry_id',
        'expense_number',
        'category',
        'supplier_name_snapshot',
        'expense_date',
        'payment_method',
        'amount',
        'gst_rate',
        'claim_input_tax_credit',
        'tax_amount',
        'total',
        'amount_paid',
        'balance_due',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'claim_input_tax_credit' => 'boolean',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function scanBill(): BelongsTo
    {
        return $this->belongsTo(ScanBill::class);
    }

    public function currentJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'current_journal_entry_id');
    }
}
