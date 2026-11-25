<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataImport extends Model
{
    protected $fillable = [
        'user_id',
        'business_id',
        'import_type',
        'original_name',
        'status',
        'preview_data',
        'result_data',
        'committed_at',
    ];

    protected function casts(): array
    {
        return [
            'preview_data' => 'array',
            'result_data' => 'array',
            'committed_at' => 'datetime',
        ];
    }
}
