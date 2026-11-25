<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ScanBill extends Model
{
    protected $hidden = [
        'file_path',
    ];

    protected $fillable = [
        'business_id',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
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

    public function expense(): HasOne
    {
        return $this->hasOne(Expense::class);
    }
}
