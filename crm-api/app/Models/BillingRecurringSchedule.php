<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingRecurringSchedule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'next_run_date' => 'date', 'end_date' => 'date', 'auto_issue' => 'boolean', 'auto_send' => 'boolean', 'delivery_settings' => 'array'];
    }
}
