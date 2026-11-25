<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'business_id',
        'category_id',
        'name',
        'sku',
        'barcode',
        'hsn_sac',
        'unit_code',
        'gst_rate',
        'purchase_price',
        'selling_price',
        'is_service',
        'track_stock',
        'batch_tracking',
        'low_stock_threshold',
        'current_stock',
    ];

    protected function casts(): array
    {
        return [
            'gst_rate' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'is_service' => 'boolean',
            'track_stock' => 'boolean',
            'batch_tracking' => 'boolean',
            'low_stock_threshold' => 'decimal:3',
            'current_stock' => 'decimal:3',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(InventoryBatch::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
