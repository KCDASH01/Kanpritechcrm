<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class GeographicAnalyticsService
{
    private const COUNTRY_ALIASES = [
        'india' => 'India',
        'in' => 'India',
        'bharat' => 'India',
        'usa' => 'United States',
        'us' => 'United States',
        'u.s.' => 'United States',
        'u.s.a.' => 'United States',
        'united states of america' => 'United States',
        'uk' => 'United Kingdom',
        'u.k.' => 'United Kingdom',
        'great britain' => 'United Kingdom',
        'uae' => 'United Arab Emirates',
        'u.a.e.' => 'United Arab Emirates',
    ];

    private const STATE_ALIASES = [
        'orissa' => 'Odisha',
        'odisha' => 'Odisha',
        'nct of delhi' => 'Delhi',
        'new delhi' => 'Delhi',
        'delhi ncr' => 'Delhi',
        'uttaranchal' => 'Uttarakhand',
        'pondicherry' => 'Puducherry',
    ];

    public function __construct(private readonly RevenueRecognitionService $revenue) {}

    /** @param array<string, mixed> $filters */
    public function overview(User $user, array $filters): array
    {
        $market = $filters['market'] ?? 'international';
        $level = $market === 'domestic' && ! empty($filters['state']) ? 'city' : ($market === 'domestic' ? 'state' : 'country');
        $deals = $this->filteredDeals($user, $filters);
        $locations = $this->aggregateLocations($deals, $level);

        $requestedMetric = $filters['rank_by'] ?? 'total_business_value';
        $currencies = $deals->pluck('currency')->filter()->map(fn ($value) => strtoupper((string) $value))->unique()->values();
        $monetaryMetric = in_array($requestedMetric, ['total_business_value', 'won_business_value', 'collected_revenue'], true);
        $rankMetric = $monetaryMetric && $currencies->count() > 1 ? 'total_deals' : $requestedMetric;
        $rankCurrency = $currencies->count() === 1 ? $currencies->first() : null;

        $locations = $locations
            ->sortByDesc(fn (array $row) => $this->rankingValue($row, $rankMetric, $rankCurrency))
            ->values();
        $denominator = (float) $locations->sum(fn (array $row) => $this->rankingValue($row, $rankMetric, $rankCurrency));
        $locations = $locations->map(function (array $row, int $index) use ($denominator, $rankMetric, $rankCurrency) {
            $value = $this->rankingValue($row, $rankMetric, $rankCurrency);
            $row['rank'] = $index + 1;
            $row['contribution_percent'] = $denominator > 0 ? round(($value / $denominator) * 100, 2) : 0.0;

            return $row;
        })->values();

        return [
            'summary' => $this->summarize($deals),
            'locations' => $locations,
            'ranking' => [
                'requested_metric' => $requestedMetric,
                'applied_metric' => $rankMetric,
                'currency' => $rankCurrency,
                'warning' => $monetaryMetric && $currencies->count() > 1
                    ? 'Multiple currencies are present. Ranking uses deal count because no exchange-rate service is configured.'
                    : null,
            ],
            'options' => $this->locationOptions($deals),
            'meta' => [
                'market' => $market,
                'level' => $level,
                'location_attribution' => 'Client business country/state/city, falling back to the linked lead.',
                'locality_level' => 'city',
                'currency_policy' => 'No currency conversion is performed; monetary values are returned as currency-wise subtotals.',
            ],
        ];
    }

    /** @param array<string, mixed> $filters */
    public function dealReport(User $user, array $filters): array
    {
        $deals = $this->filteredDeals($user, $filters);
        $sortBy = $filters['sort_by'] ?? 'date';
        $sortDir = $filters['sort_dir'] ?? 'desc';
        $sorted = $deals->sortBy(
            fn (Deal $deal) => $sortBy === 'amount' ? (float) $deal->value : ($deal->created_at?->timestamp ?? 0),
            SORT_REGULAR,
            $sortDir === 'desc',
        )->values();

        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $paginator = new LengthAwarePaginator(
            $sorted->forPage($page, $perPage)->values(),
            $sorted->count(),
            $perPage,
            $page,
        );

        return [
            'summary' => $this->summarize($sorted),
            'rows' => $paginator->getCollection()->map(fn (Deal $deal) => $this->dealRow($deal))->values(),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /** @param array<string, mixed> $filters */
    public function exportDealRows(User $user, array $filters): Collection
    {
        return $this->filteredDeals($user, $filters)->map(fn (Deal $deal) => $this->dealRow($deal));
    }

    /** @param array<string, mixed> $filters */
    private function filteredDeals(User $user, array $filters): Collection
    {
        $basis = $filters['date_basis'] ?? 'deal_created';
        $from = ! empty($filters['date_from']) ? Carbon::parse($filters['date_from'])->startOfDay() : null;
        $to = ! empty($filters['date_to']) ? Carbon::parse($filters['date_to'])->endOfDay() : null;

        $analyticsPayments = fn ($query) => $query
            ->when($basis === 'payment_collection' && $from, fn ($q) => $q->whereDate('payment_date', '>=', $from->toDateString()))
            ->when($basis === 'payment_collection' && $to, fn ($q) => $q->whereDate('payment_date', '<=', $to->toDateString()));

        $query = Deal::query()
            ->where('deals.organization_id', $user->organization_id)
            ->with([
                'client:id,first_name,last_name,company,email,phone,city,state,country',
                'lead:id,first_name,last_name,company,email,phone,city,state,country,custom_fields',
                'assignedTo:id,name',
                'department:id,name',
                'stage:id,name',
                'payments:id,deal_id,amount,payment_date,payment_mode,txn_or_utr_number',
                'recurringBusiness:id,deal_id,amount,frequency,start_date,end_date,next_billing_date,billing_cycles,status',
            ])
            ->withSum(['payments as analytics_collected' => $analyticsPayments], 'amount')
            ->withSum('payments as total_collected', 'amount')
            ->withCount('payments');

        if ($user->isEmployee()) {
            $query->where('deals.assigned_to', $user->id);
        } elseif (! empty($filters['assigned_to'])) {
            $query->where('deals.assigned_to', (int) $filters['assigned_to']);
        }
        if (! $user->isEmployee() && ! empty($filters['department_id'])) {
            $query->where('deals.department_id', (int) $filters['department_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('deals.status', $filters['status']);
        }
        if (! empty($filters['service_type'])) {
            $query->where('deals.service_type', $filters['service_type']);
        }
        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('deals.title', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($client) => $client->where('company', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%"))
                    ->orWhereHas('lead', fn ($lead) => $lead->where('company', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%"));
            });
        }

        if ($basis === 'deal_won') {
            $query->where('deals.status', 'won')
                ->when($from, fn ($q) => $q->whereDate('deals.closed_at', '>=', $from->toDateString()))
                ->when($to, fn ($q) => $q->whereDate('deals.closed_at', '<=', $to->toDateString()));
        } elseif ($basis === 'payment_collection') {
            $query->whereHas('payments', $analyticsPayments);
        } else {
            $query->when($from, fn ($q) => $q->where('deals.created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('deals.created_at', '<=', $to));
        }

        return $query->get()
            ->filter(fn (Deal $deal) => $this->matchesGeography($deal, $filters))
            ->values();
    }

    /** @param array<string, mixed> $filters */
    private function matchesGeography(Deal $deal, array $filters): bool
    {
        $location = $this->location($deal);
        $market = $filters['market'] ?? 'international';
        $isDomestic = $location['country'] === 'India'
            || ($location['country'] === 'Unassigned Location' && strtoupper((string) $deal->market_type) !== 'INTERNATIONAL');
        if (($market === 'domestic') !== $isDomestic) {
            return false;
        }
        if (! empty($filters['country']) && $location['country'] !== $this->normalizeCountry((string) $filters['country'])) {
            return false;
        }
        if (! empty($filters['state']) && $location['state'] !== $this->normalizeState((string) $filters['state'])) {
            return false;
        }
        if (! empty($filters['city']) && $location['city'] !== $this->normalizeName((string) $filters['city'], 'Unassigned City')) {
            return false;
        }

        return true;
    }

    private function aggregateLocations(Collection $deals, string $level): Collection
    {
        $rows = [];
        foreach ($deals as $deal) {
            $geo = $this->location($deal);
            $name = $geo[$level];
            $rows[$name] ??= $this->emptyLocationRow($name, $geo);
            $row = &$rows[$name];
            $row['total_deals']++;
            $row[$deal->status.'_deals']++;
            if ($deal->lead_id) {
                $row['leads'][$deal->lead_id] = true;
            }
            $currency = strtoupper($deal->currency ?: 'INR');
            $this->addMoney($row['total_business_value'], $currency, (float) $deal->value);
            if ($deal->status === 'won') {
                $this->addMoney($row['won_business_value'], $currency, (float) $deal->value);
            }
            $this->addMoney($row['collected_revenue'], $currency, (float) ($deal->analytics_collected ?? 0));
            $this->addMoney($row['outstanding_receivables'], $currency, $this->outstanding($deal));
            $employee = $deal->assignedTo?->name ?? 'Unassigned Employee';
            $row['employees'][$employee] = ($row['employees'][$employee] ?? 0) + 1;
            unset($row);
        }

        return collect(array_values($rows))->map(function (array $row) {
            $row['leads'] = count($row['leads']);
            foreach (['total_business_value', 'won_business_value', 'collected_revenue', 'outstanding_receivables'] as $field) {
                $row[$field] = $this->moneyList($row[$field]);
            }
            $row['employees'] = collect($row['employees'])->map(fn ($count, $name) => ['name' => $name, 'deals' => $count])->values();

            return $row;
        });
    }

    private function emptyLocationRow(string $name, array $geo): array
    {
        return [
            'name' => $name, 'country' => $geo['country'], 'state' => $geo['state'], 'city' => $geo['city'],
            'leads' => [], 'total_deals' => 0, 'open_deals' => 0, 'won_deals' => 0, 'lost_deals' => 0,
            'total_business_value' => [], 'won_business_value' => [], 'collected_revenue' => [], 'outstanding_receivables' => [],
            'employees' => [],
        ];
    }

    private function summarize(Collection $deals): array
    {
        $total = [];
        $won = [];
        $collected = [];
        $outstanding = [];
        foreach ($deals as $deal) {
            $currency = strtoupper($deal->currency ?: 'INR');
            $this->addMoney($total, $currency, (float) $deal->value);
            if ($deal->status === 'won') {
                $this->addMoney($won, $currency, (float) $deal->value);
            }
            $this->addMoney($collected, $currency, (float) ($deal->analytics_collected ?? 0));
            $this->addMoney($outstanding, $currency, $this->outstanding($deal));
        }

        return [
            'total_deals' => $deals->count(),
            'won_deals' => $deals->where('status', 'won')->count(),
            'total_business_value' => $this->moneyList($total),
            'won_business_value' => $this->moneyList($won),
            'collected_revenue' => $this->moneyList($collected),
            'outstanding_receivables' => $this->moneyList($outstanding),
        ];
    }

    private function dealRow(Deal $deal): array
    {
        $geo = $this->location($deal);
        $client = $deal->client;
        $lead = $deal->lead;

        return [
            'id' => $deal->id,
            'title' => $deal->title,
            'reference' => 'DEAL-'.$deal->id,
            'client' => $client?->company ?: $client?->full_name ?: $lead?->company ?: $lead?->full_name,
            'contact_email' => $client?->email ?: $lead?->email,
            'contact_phone' => $client?->phone ?: $lead?->phone,
            ...$geo,
            'created_at' => $deal->created_at?->toDateString(),
            'closed_at' => $deal->closed_at?->toDateString(),
            'assigned_employee' => $deal->assignedTo?->name,
            'department' => $deal->department?->name,
            'service_type' => $deal->service_type,
            'value' => (float) $deal->value,
            'currency' => strtoupper($deal->currency ?: 'INR'),
            'stage' => $deal->stage?->name,
            'status' => $deal->status,
            'collected' => round((float) ($deal->analytics_collected ?? 0), 2),
            'outstanding' => $this->outstanding($deal),
            'payment_references' => $deal->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'date' => $payment->payment_date?->toDateString(),
                'method' => $payment->payment_mode,
                'reference' => $payment->txn_or_utr_number,
            ])->values(),
        ];
    }

    private function outstanding(Deal $deal): float
    {
        $deal->setAttribute('payments_sum_amount', (float) ($deal->total_collected ?? 0));

        return $this->revenue->dealReceivable($deal, now());
    }

    private function location(Deal $deal): array
    {
        $client = $deal->client;
        $lead = $deal->lead;

        return [
            'country' => $this->normalizeCountry((string) ($client?->country ?: $lead?->country ?: '')),
            'state' => $this->normalizeState((string) ($client?->state ?: $lead?->state ?: '')),
            'city' => $this->normalizeName((string) ($client?->city ?: $lead?->city ?: ''), 'Unassigned City'),
        ];
    }

    private function normalizeCountry(string $value): string
    {
        $key = strtolower(trim($value));
        if ($key === '') {
            return 'Unassigned Location';
        }

        return self::COUNTRY_ALIASES[$key] ?? $this->normalizeName($value, 'Unassigned Location');
    }

    private function normalizeState(string $value): string
    {
        $key = strtolower(trim($value));
        if ($key === '') {
            return 'Unassigned State';
        }

        return self::STATE_ALIASES[$key] ?? $this->normalizeName($value, 'Unassigned State');
    }

    private function normalizeName(string $value, string $fallback): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        return $value === '' ? $fallback : mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    private function addMoney(array &$bucket, string $currency, float $amount): void
    {
        $bucket[$currency] = round(($bucket[$currency] ?? 0) + $amount, 2);
    }

    private function moneyList(array $bucket): array
    {
        ksort($bucket);

        return collect($bucket)->map(fn ($amount, $currency) => ['currency' => $currency, 'amount' => round((float) $amount, 2)])->values()->all();
    }

    private function rankingValue(array $row, string $metric, ?string $currency): float
    {
        if (in_array($metric, ['total_deals', 'won_deals'], true)) {
            return (float) $row[$metric];
        }
        $value = collect($row[$metric] ?? [])->firstWhere('currency', $currency);

        return (float) ($value['amount'] ?? 0.0);
    }

    private function locationOptions(Collection $deals): array
    {
        $locations = $deals->map(fn (Deal $deal) => $this->location($deal));

        return [
            'countries' => $locations->pluck('country')->unique()->sort()->values(),
            'states' => $locations->where('country', 'India')->pluck('state')->unique()->sort()->values(),
            'cities' => $locations->groupBy('state')->map(fn ($rows) => $rows->pluck('city')->unique()->sort()->values()),
            'services' => $deals->pluck('service_type')->filter()
                ->unique()
                ->sort()
                ->values(),
        ];
    }
}
