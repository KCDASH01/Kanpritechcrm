<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadImport extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['sheets' => 'encrypted:array', 'mapping' => 'encrypted:array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(LeadImportRow::class);
    }
}
