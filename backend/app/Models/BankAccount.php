<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'business_id',
        'name',
        'bank_name',
        'account_number',
        'account_number_hash',
        'account_last4',
        'ifsc',
        'upi_id',
        'is_active',
    ];

    protected $hidden = [
        'account_number',
        'account_number_hash',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function statementTransactions(): HasMany
    {
        return $this->hasMany(BankStatementTransaction::class);
    }
}
