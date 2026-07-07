<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'name', 'slug', 'description',
        'monthly_price', 'yearly_price', 'currency',
        'leads_limit', 'deals_limit', 'pipelines_limit',
        'team_members_limit', 'departments_limit',
        'bulk_import', 'sso_access', 'api_access',
        'advanced_reports', 'priority_support', 'custom_pipelines',
        'feature_list',
        'is_active', 'is_featured', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price'      => 'decimal:2',
            'yearly_price'       => 'decimal:2',
            'bulk_import'        => 'boolean',
            'sso_access'         => 'boolean',
            'api_access'         => 'boolean',
            'advanced_reports'   => 'boolean',
            'priority_support'   => 'boolean',
            'custom_pipelines'   => 'boolean',
            'is_active'          => 'boolean',
            'is_featured'        => 'boolean',
            'feature_list'       => 'array',
        ];
    }

    /** Human-readable limit label — -1 becomes "Unlimited" */
    public function limitLabel(int $value): string
    {
        return $value === -1 ? 'Unlimited' : number_format($value);
    }

    public function subscriptionsCount(): int
    {
        return \App\Models\Subscription::where('plan', $this->slug)
            ->where('is_active', true)
            ->count();
    }
}
