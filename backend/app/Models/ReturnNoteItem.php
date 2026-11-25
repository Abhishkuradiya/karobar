<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnNoteItem extends Model
{
    protected $fillable = [
        'return_note_id',
        'original_sale_item_id',
        'original_purchase_item_id',
        'product_id',
        'description',
        'quantity',
        'unit_rate',
        'gst_rate',
        'taxable_amount',
        'tax_amount',
        'line_total',
        'inventory_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_rate' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'inventory_cost' => 'decimal:2',
        ];
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(ReturnNote::class, 'return_note_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
