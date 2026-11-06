<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionCampaignCustomer extends Model
{
    protected $fillable = ['promotion_campaign_id', 'organization_id'];
}
