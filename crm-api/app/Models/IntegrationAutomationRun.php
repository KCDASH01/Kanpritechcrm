<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationAutomationRun extends Model
{
    protected $fillable = [
        'organization_id', 'automation_id', 'integration_event_id', 'idempotency_key',
        'status', 'attempts', 'result', 'error_message', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['result' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
