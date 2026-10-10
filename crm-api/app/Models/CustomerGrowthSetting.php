<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerGrowthSetting extends Model
{
    protected $fillable = ['organization_id', 'cross_sell_enabled', 'upsell_enabled', 'renewal_reminders_enabled', 'health_alerts_enabled', 'reminder_intervals', 'service_mappings', 'health_thresholds', 'notification_channels', 'default_assignment'];

    protected function casts(): array
    {
        return ['cross_sell_enabled' => 'boolean', 'upsell_enabled' => 'boolean', 'renewal_reminders_enabled' => 'boolean', 'health_alerts_enabled' => 'boolean', 'reminder_intervals' => 'array', 'service_mappings' => 'array', 'health_thresholds' => 'array', 'notification_channels' => 'array'];
    }
}
