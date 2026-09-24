<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FwaPlan extends Model
{
    protected $fillable = [
        'source_id',
        'crm_id',
        'cbs_id',
        'plan_name',
        'plan_type',
        'amount',
        'data_cap',
        'max_speed',
        'default_speed',
        'status',
        'source_synced_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'status' => 'boolean',
        'source_synced_at' => 'datetime',
    ];
}
