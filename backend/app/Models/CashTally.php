<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashTally extends Model
{
    protected $fillable = [
        'user_id',
        'business_id',
        'counted_date',
        'book_amount',
        'counted_amount',
        'difference',
        'note',
        'journal_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'counted_date' => 'date:Y-m-d',
            'book_amount' => 'decimal:2',
            'counted_amount' => 'decimal:2',
            'difference' => 'decimal:2',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
