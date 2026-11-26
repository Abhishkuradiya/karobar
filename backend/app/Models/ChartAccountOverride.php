<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChartAccountOverride extends Model
{
    protected $fillable = [
        'user_id',
        'business_id',
        'account_code',
        'name',
        'group_name',
        'is_hidden',
    ];

    protected function casts(): array
    {
        return [
            'is_hidden' => 'boolean',
        ];
    }
}
