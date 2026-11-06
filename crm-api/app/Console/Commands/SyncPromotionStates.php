<?php

namespace App\Console\Commands;

use App\Models\PromotionCampaign;
use App\Models\PromotionRedemption;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SyncPromotionStates extends Command
{
    protected $signature = 'promotions:sync-states {--dry-run}';

    protected $description = 'Activate scheduled promotions, expire ended campaigns, and release stale reservations';

    public function handle(): int
    {
        if (! Schema::hasTable('promotion_campaigns')) {
            $this->warn('Promotion tables are not installed.');

            return self::SUCCESS;
        }
        $activationQuery = fn () => PromotionCampaign::query()
            ->where('state', 'scheduled')
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->where(fn ($query) => $query->where('kind', 'automatic')->orWhereNotNull('code'))
            ->whereHas('planPrices')
            ->whereDoesntHave('planPrices', fn ($query) => $query->whereNull('promotion_campaign_plan_prices.gateway_offer_id'));
        $activating = $activationQuery()->count();
        $expiring = PromotionCampaign::whereIn('state', ['active', 'scheduled'])->whereNotNull('ends_at')->where('ends_at', '<=', now())->count();
        $releasing = PromotionRedemption::where('status', 'reserved')->whereNotNull('expires_at')->where('expires_at', '<=', now())->count();
        $this->line("Activate: {$activating}; expire: {$expiring}; release reservations: {$releasing}");
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        $activationQuery()->update(['state' => 'active', 'updated_at' => now()]);
        PromotionCampaign::whereIn('state', ['active', 'scheduled'])->whereNotNull('ends_at')->where('ends_at', '<=', now())->update(['state' => 'expired', 'updated_at' => now()]);
        PromotionRedemption::where('status', 'reserved')->whereNotNull('expires_at')->where('expires_at', '<=', now())->update(['status' => 'released', 'released_at' => now(), 'updated_at' => now()]);

        return self::SUCCESS;
    }
}
