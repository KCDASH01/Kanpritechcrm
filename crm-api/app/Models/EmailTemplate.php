<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'subject',
        'body',
        'stage_trigger',
    ];
}
