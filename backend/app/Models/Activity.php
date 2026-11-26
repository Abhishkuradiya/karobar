<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activity extends Model
{
    protected $fillable = ['user_id', 'business_id', 'journal_entry_id', 'entry_date', 'source_type', 'source_id', 'description', 'amount'];

    protected function casts(): array
    {
        return ['entry_date' => 'date:Y-m-d', 'amount' => 'decimal:2'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
}
