<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBatch extends Model
{
    protected $fillable = [
        'user_id',
        'business_id',
        'product_id',
        'purchase_id',
        'purchase_item_id',
        'batch_number',
        'received_date',
        'expiry_date',
        'quantity_received',
        'quantity_remaining',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'received_date' => 'date:Y-m-d',
            'expiry_date' => 'date:Y-m-d',
            'quantity_received' => 'decimal:3',
            'quantity_remaining' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'reversed_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }
}
