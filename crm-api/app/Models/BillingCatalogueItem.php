<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BillingCatalogueItem extends Model
{
    use SoftDeletes;

    protected $fillable = ['organization_id', 'type', 'code', 'name', 'category', 'description', 'unit', 'hsn_sac', 'default_tax_rate', 'prices', 'is_recurring', 'is_active', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['prices' => 'array', 'default_tax_rate' => 'decimal:4', 'is_recurring' => 'boolean', 'is_active' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
