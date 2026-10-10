<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadIntegrationSetting extends Model
{
    protected $fillable = [
        'organization_id', 'default_owner_id', 'meta_enabled', 'whatsapp_enabled',
        'email_enabled', 'auto_import_enabled', 'review_unmatched',
        'raw_content_retention_days', 'field_mappings', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'meta_enabled' => 'boolean', 'whatsapp_enabled' => 'boolean',
            'email_enabled' => 'boolean', 'auto_import_enabled' => 'boolean',
            'review_unmatched' => 'boolean', 'field_mappings' => 'array', 'settings' => 'array',
        ];
    }
}
