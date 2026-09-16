<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiRequestLog extends Model
{
    protected $fillable = [
        'user_id',
        'request_id',
        'method',
        'path',
        'ip_address',
        'user_agent',
        'status_code',
        'duration_ms',
        'requested_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
    ];
}
