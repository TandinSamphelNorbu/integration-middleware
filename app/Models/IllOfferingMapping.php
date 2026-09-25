<?php

namespace App\Models;

use Database\Factories\IllOfferingMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IllOfferingMapping extends Model
{
    /** @use HasFactory<IllOfferingMappingFactory> */
    use HasFactory;

    protected $fillable = [
        'base_plan_id',
        'base_plan_name',
        'addon_id',
        'addon_name',
    ];
}
