<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatementTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'business_id',
        'bank_account_id',
        'matched_journal_line_id',
        'transaction_date',
        'description',
        'reference',
        'amount',
        'fingerprint',
        'reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'reconciled_at' => 'datetime',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function matchedJournalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class, 'matched_journal_line_id');
    }
}
