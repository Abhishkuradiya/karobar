<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{
    protected $fillable = ['user_id', 'business_id', 'name'];

    public function getNameAttribute(?string $value): ?string
    {
        return $value === null
            ? null
            : mb_convert_case(trim(preg_replace('/\s+/', ' ', $value)), MB_CASE_TITLE, 'UTF-8');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
