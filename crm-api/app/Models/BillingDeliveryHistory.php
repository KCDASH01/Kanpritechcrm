<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingDeliveryHistory extends Model
{
    protected $table = 'billing_delivery_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
