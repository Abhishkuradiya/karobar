<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReturnNote extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'business_id',
        'sale_id',
        'purchase_id',
        'current_journal_entry_id',
        'note_type',
        'note_number',
        'counterparty_name_snapshot',
        'note_date',
        'settlement_method',
        'reason',
        'taxable_amount',
        'tax_amount',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'note_date' => 'date:Y-m-d',
            'taxable_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class)->withTrashed();
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnNoteItem::class);
    }

    public function currentJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'current_journal_entry_id');
    }
}
