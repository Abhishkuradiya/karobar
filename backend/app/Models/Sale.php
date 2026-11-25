<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'customer_id',
        'bank_account_id',
        'current_journal_entry_id',
        'invoice_number',
        'customer_name_snapshot',
        'sale_date',
        'payment_method',
        'prices_include_gst',
        'export_under_lut',
        'notes',
        'subtotal',
        'discount_total',
        'taxable_amount',
        'tax_amount',
        'total',
        'amount_paid',
        'balance_due',
        'due_date',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'prices_include_gst' => 'boolean',
            'export_under_lut' => 'boolean',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function currentJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'current_journal_entry_id');
    }
}
