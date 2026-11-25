<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChartAccount extends Model
{
    protected $fillable = [
        'user_id',
        'business_id',
        'display_code',
        'account_code',
        'name',
        'account_type',
        'group_name',
    ];
}
