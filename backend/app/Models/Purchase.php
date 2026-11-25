<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'supplier_id',
        'bank_account_id',
        'current_journal_entry_id',
        'purchase_number',
        'supplier_bill_number',
        'supplier_name_snapshot',
        'purchase_date',
        'supplier_bill_date',
        'payment_method',
        'prices_include_gst',
        'claim_input_tax_credit',
        'reverse_charge',
        'notes',
        'subtotal',
        'discount_total',
        'taxable_amount',
        'tax_amount',
        'total',
        'supplier_amount',
        'amount_paid',
        'balance_due',
        'due_date',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date:Y-m-d',
            'supplier_bill_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'prices_include_gst' => 'boolean',
            'claim_input_tax_credit' => 'boolean',
            'reverse_charge' => 'boolean',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'supplier_amount' => 'decimal:2',
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

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function currentJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'current_journal_entry_id');
    }
}
