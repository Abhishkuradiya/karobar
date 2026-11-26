<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAccountantSetting extends Model
{
    protected $fillable = [
        'provider', 'api_key', 'base_url', 'model',
        'temperature', 'max_tokens', 'last_verified_at',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
        'temperature' => 'float',
        'max_tokens' => 'integer',
        'last_verified_at' => 'datetime',
    ];

}
