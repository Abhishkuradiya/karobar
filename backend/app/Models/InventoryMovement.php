<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    protected $fillable = [
        'user_id',
        'business_id',
        'product_id',
        'batch_id',
        'sale_item_id',
        'purchase_item_id',
        'movement_type',
        'source_type',
        'source_id',
        'quantity_delta',
        'unit_cost',
        'notes',
        'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'reversed_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class);
    }
}
