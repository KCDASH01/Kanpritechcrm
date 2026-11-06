<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\PromotionCampaign;
use App\Models\SubscriptionPlanPrice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PromotionController extends Controller
{
    public function index(Request $request)
    {
        $campaigns = PromotionCampaign::query()
            ->withCount('redemptions')
            ->withCount(['redemptions as successful_redemptions' => fn ($query) => $query->whereIn('status', ['applied', 'exhausted'])])
            ->when($request->filled('state'), fn ($query) => $query->where('state', $request->string('state')))
            ->latest()->paginate(25)->withQueryString();
        $analytics = [
            'campaigns' => PromotionCampaign::count(),
            'active' => PromotionCampaign::where('state', 'active')->count(),
            'redemptions' => DB::table('promotion_redemptions')->whereIn('status', ['applied', 'exhausted'])->count(),
            'discount_by_currency' => DB::table('promotion_redemptions')
                ->whereIn('status', ['applied', 'exhausted'])
                ->whereNotNull('actual_discount_minor')
                ->selectRaw('currency, SUM(actual_discount_minor) as total_minor')
                ->groupBy('currency')
                ->pluck('total_minor', 'currency'),
        ];

        return view('promotions.index', compact('campaigns', 'analytics'));
    }

    public function create()
    {
        return view('promotions.form', ['campaign' => new PromotionCampaign, 'planPrices' => $this->planPrices(), 'organizations' => Organization::orderBy('name')->get(['id', 'name'])]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by_admin_id'] = auth('admin')->id();
        $campaign = DB::transaction(function () use ($request, $data) {
            $campaign = PromotionCampaign::create($data);
            $this->syncPlans($campaign, $request);
            $this->syncCustomers($campaign, $request);
            $this->audit($campaign, 'created', null, $campaign->toArray());

            return $campaign;
        });

        return redirect()->route('promotions.show', $campaign)->with('success', 'Promotion saved as '.$campaign->state.'.');
    }

    public function show(PromotionCampaign $promotion)
    {
        $promotion->load('planPrices')->loadCount('redemptions');
        $redemptions = $promotion->redemptions()->latest()->paginate(30);
        $audits = DB::table('promotion_audit_events')->where('promotion_campaign_id', $promotion->id)->latest('created_at')->limit(50)->get();

        return view('promotions.show', compact('promotion', 'redemptions', 'audits'));
    }

    public function edit(PromotionCampaign $promotion)
    {
        $promotion->load('planPrices');

        $promotion->setAttribute('selected_organization_ids', DB::table('promotion_campaign_customers')->where('promotion_campaign_id', $promotion->id)->pluck('organization_id')->all());

        return view('promotions.form', ['campaign' => $promotion, 'planPrices' => $this->planPrices(), 'organizations' => Organization::orderBy('name')->get(['id', 'name'])]);
    }

    public function update(Request $request, PromotionCampaign $promotion)
    {
        abort_if(in_array($promotion->state, ['active', 'archived', 'expired'], true) || $promotion->redemptions()->exists(), 422, 'Active, expired, archived, or previously redeemed promotions cannot be edited. Disable and duplicate the campaign instead.');
        $before = $promotion->toArray();
        $data = $this->validated($request, $promotion);
        $data['updated_by_admin_id'] = auth('admin')->id();
        DB::transaction(function () use ($request, $promotion, $data, $before) {
            $promotion->update($data);
            $this->syncPlans($promotion, $request);
            $this->syncCustomers($promotion, $request);
            $this->audit($promotion, 'updated', $before, $promotion->fresh()->toArray());
        });

        return redirect()->route('promotions.show', $promotion)->with('success', 'Promotion updated.');
    }

    public function state(Request $request, PromotionCampaign $promotion)
    {
        $data = $request->validate(['state' => ['required', Rule::in(['draft', 'scheduled', 'active', 'disabled', 'archived'])]]);
        $allowedTransitions = [
            'draft' => ['scheduled', 'active', 'disabled', 'archived'],
            'scheduled' => ['draft', 'active', 'disabled', 'archived'],
            'active' => ['disabled', 'archived'],
            'disabled' => ['draft', 'scheduled', 'active', 'archived'],
            'expired' => ['archived'],
            'archived' => [],
        ];
        abort_unless($data['state'] === $promotion->state || in_array($data['state'], $allowedTransitions[$promotion->state] ?? [], true), 422, 'That promotion state transition is not allowed.');
        if (in_array($data['state'], ['active', 'scheduled'], true)) {
            $this->assertProviderReady($promotion, $data['state'] === 'scheduled');
        }
        $before = $promotion->toArray();
        $promotion->update(['state' => $data['state'], 'archived_at' => $data['state'] === 'archived' ? now() : null]);
        $this->audit($promotion, 'state_changed', $before, $promotion->fresh()->toArray());

        return back()->with('success', 'Promotion state changed to '.$data['state'].'.');
    }

    public function duplicate(PromotionCampaign $promotion)
    {
        $copy = DB::transaction(function () use ($promotion) {
            $copy = $promotion->replicate(['code', 'state', 'archived_at']);
            $copy->name = $promotion->name.' Copy';
            $copy->code = null;
            $copy->state = 'draft';
            $copy->created_by_admin_id = auth('admin')->id();
            $copy->save();
            foreach ($promotion->planPrices as $price) {
                $copy->planPrices()->attach($price->id, [
                    'gateway_offer_id' => null,
                    'supported_payment_methods' => $price->pivot->supported_payment_methods,
                ]);
            }
            $this->audit($copy, 'duplicated', null, ['source_campaign_id' => $promotion->id]);

            return $copy;
        });

        return redirect()->route('promotions.edit', $copy)->with('success', 'Draft copy created; gateway mappings were intentionally cleared.');
    }

    private function validated(Request $request, ?PromotionCampaign $campaign = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('promotion_campaigns', 'code')->ignore($campaign?->id)],
            'generate_code' => ['nullable', 'boolean'],
            'kind' => ['required', Rule::in(['coupon', 'automatic'])],
            'discount_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'percentage' => ['nullable', 'numeric', 'min:0', 'max:100', 'required_if:discount_type,percentage'],
            'fixed_amount_minor' => ['nullable', 'integer', 'gt:0', 'required_if:discount_type,fixed'],
            'maximum_discount_minor' => ['nullable', 'integer', 'min:0'],
            'minimum_eligible_minor' => ['nullable', 'integer', 'min:0'],
            'eligible_currencies' => ['required', 'array', 'min:1'],
            'eligible_currencies.*' => ['in:INR,USD'],
            'audience' => ['required', Rule::in(['all', 'new_customers', 'selected_customers', 'referral', 'loyalty'])],
            'duration' => ['required', Rule::in(['first_cycle', 'limited_cycles', 'recurring'])],
            'discount_cycles' => ['nullable', 'integer', 'min:1', 'max:120', 'required_if:duration,limited_cycles'],
            'stackable' => ['nullable', 'boolean'],
            'priority' => ['required', 'integer', 'min:1', 'max:1000'],
            'total_redemption_limit' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['required', 'integer', 'min:1', 'max:100'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'state' => ['required', Rule::in(['draft', 'scheduled', 'disabled'])],
            'plan_prices' => ['required', 'array', 'min:1'],
            'plan_prices.*' => ['integer', 'exists:subscription_plan_prices,id'],
            'gateway_offer_ids' => ['nullable', 'array'],
            'gateway_offer_ids.*' => ['nullable', 'string', 'max:191', 'regex:/^offer_[A-Za-z0-9]+$/'],
            'selected_organizations' => ['nullable', 'array', 'required_if:audience,selected_customers'],
            'selected_organizations.*' => ['integer', 'exists:organizations,id'],
            'referral_code' => ['nullable', 'string', 'max:64', 'required_if:audience,referral'],
            'minimum_successful_payments' => ['nullable', 'integer', 'min:1', 'required_if:audience,loyalty'],
        ]);
        $code = $request->boolean('generate_code') ? Str::upper(Str::random(10)) : Str::upper(trim((string) ($data['code'] ?? '')));
        if ($data['kind'] === 'coupon' && $code === '') {
            throw ValidationException::withMessages(['code' => 'Enter a coupon code or select secure code generation.']);
        }
        if ($data['state'] === 'scheduled') {
            if (empty($data['starts_at'])) {
                throw ValidationException::withMessages(['starts_at' => 'A scheduled campaign requires a start date.']);
            }
            foreach ($data['plan_prices'] as $planPriceId) {
                if (! $request->filled("gateway_offer_ids.$planPriceId")) {
                    throw ValidationException::withMessages([
                        "gateway_offer_ids.$planPriceId" => 'Every scheduled plan requires an approved Razorpay offer mapping.',
                    ]);
                }
            }
        }

        return [
            'name' => $data['name'], 'code' => $data['kind'] === 'automatic' ? null : ($code ?: null),
            'kind' => $data['kind'], 'discount_type' => $data['discount_type'],
            'percentage_basis_points' => $data['discount_type'] === 'percentage' ? (int) round($data['percentage'] * 100) : null,
            'fixed_amount_minor' => $data['discount_type'] === 'fixed' ? (int) $data['fixed_amount_minor'] : null,
            'maximum_discount_minor' => $data['maximum_discount_minor'] ?? null,
            'minimum_eligible_minor' => $data['minimum_eligible_minor'] ?? 0,
            'eligible_currencies' => $data['eligible_currencies'], 'audience' => $data['audience'],
            'duration' => $data['duration'], 'discount_cycles' => $data['duration'] === 'first_cycle' ? 1 : ($data['discount_cycles'] ?? null),
            'stackable' => $request->boolean('stackable'), 'priority' => $data['priority'],
            'total_redemption_limit' => $data['total_redemption_limit'] ?? null,
            'per_customer_limit' => $data['per_customer_limit'], 'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null, 'state' => $data['state'],
            'rules' => array_filter([
                'referral_code' => $data['referral_code'] ?? null,
                'minimum_successful_payments' => $data['minimum_successful_payments'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
        ];
    }

    private function syncPlans(PromotionCampaign $campaign, Request $request): void
    {
        $mappings = collect($request->input('plan_prices', []))->mapWithKeys(fn ($id) => [(int) $id => [
            'gateway_offer_id' => $request->input("gateway_offer_ids.$id") ?: null,
        ]])->all();
        $campaign->planPrices()->sync($mappings);
    }

    private function assertProviderReady(PromotionCampaign $campaign, bool $scheduled): void
    {
        abort_if($campaign->kind === 'coupon' && ! $campaign->code, 422, 'Coupon campaigns require a code.');
        abort_if(! $campaign->planPrices()->exists(), 422, 'At least one plan is required.');
        abort_if($campaign->planPrices()->where(fn ($query) => $query->whereNull('promotion_campaign_plan_prices.gateway_offer_id')->orWhere('promotion_campaign_plan_prices.gateway_offer_id', ''))->exists(), 422, 'Every eligible plan needs an approved Razorpay offer mapping.');
        abort_if($scheduled && ! $campaign->starts_at, 422, 'Scheduled campaigns require a start date.');
        abort_if(! $scheduled && $campaign->starts_at?->isFuture(), 422, 'A campaign cannot be activated before its scheduled start.');
        abort_if($campaign->ends_at?->isPast(), 422, 'An expired campaign cannot be activated or scheduled.');
    }

    private function syncCustomers(PromotionCampaign $campaign, Request $request): void
    {
        DB::table('promotion_campaign_customers')->where('promotion_campaign_id', $campaign->id)->delete();
        if ($request->input('audience') !== 'selected_customers') {
            return;
        }
        foreach (array_unique($request->input('selected_organizations', [])) as $organizationId) {
            DB::table('promotion_campaign_customers')->insert([
                'promotion_campaign_id' => $campaign->id,
                'organization_id' => $organizationId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function planPrices()
    {
        return SubscriptionPlanPrice::where('is_active', true)->orderBy('plan')->orderBy('billing_cycle')->orderBy('currency')->get();
    }

    private function audit(PromotionCampaign $campaign, string $event, ?array $before, ?array $after): void
    {
        DB::table('promotion_audit_events')->insert([
            'promotion_campaign_id' => $campaign->id, 'actor_admin_id' => auth('admin')->id(),
            'event_type' => $event, 'before' => $before ? json_encode($before) : null,
            'after' => $after ? json_encode($after) : null, 'created_at' => now(),
        ]);
    }
}
