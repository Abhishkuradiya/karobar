<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    protected $fillable = [
        'account_code',
        'bank_account_id',
        'debit',
        'credit',
        'reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'reconciled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (JournalLine $line): void {
            $entry = $line->journalEntry()->first();
            if (!$entry) return;

            Activity::updateOrCreate(
                ['journal_entry_id' => $entry->id],
                [
                    'user_id' => $entry->user_id,
                    'business_id' => $entry->business_id,
                    'entry_date' => $entry->entry_date,
                    'source_type' => $entry->source_type,
                    'source_id' => $entry->source_id,
                    'description' => $entry->description,
                    'amount' => round((float) $entry->lines()->sum('debit'), 2),
                ]
            );
        });
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
