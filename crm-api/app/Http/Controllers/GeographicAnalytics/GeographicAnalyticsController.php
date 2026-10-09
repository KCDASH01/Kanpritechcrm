<?php

namespace App\Http\Controllers\GeographicAnalytics;

use App\Http\Controllers\Controller;
use App\Services\GeographicAnalyticsService;
use App\Support\ReportExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GeographicAnalyticsController extends Controller
{
    public function __construct(private readonly GeographicAnalyticsService $analytics) {}

    public function overview(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);

        return response()->json(['data' => $this->analytics->overview($request->user(), $filters)]);
    }

    public function deals(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request, true);
        $result = $this->analytics->dealReport($request->user(), $filters);

        return response()->json([
            'data' => ['summary' => $result['summary'], 'rows' => $result['rows']],
            'meta' => $result['meta'],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->validatedFilters($request, true);
        $scope = $request->validate(['scope' => ['nullable', 'in:summary,details']])['scope'] ?? 'summary';
        $contextHeaders = ['Business', 'Market', 'Date Basis', 'Date From', 'Date To'];
        $context = [
            $request->user()->organization?->name ?? 'CRM',
            ucfirst($filters['market'] ?? 'international'),
            str_replace('_', ' ', $filters['date_basis'] ?? 'deal_created'),
            $filters['date_from'] ?? 'All time',
            $filters['date_to'] ?? 'Present',
        ];

        if ($scope === 'details') {
            $rows = $this->analytics->exportDealRows($request->user(), $filters);
            $headers = [...$contextHeaders,
                'Deal Reference', 'Deal', 'Client', 'Contact Email', 'Contact Phone', 'Country', 'State', 'City',
                'Created Date', 'Won / Closed Date', 'Employee', 'Department', 'Service', 'Status', 'Stage',
                'Currency', 'Deal Value', 'Collected', 'Outstanding', 'Payment References',
            ];
            $data = $rows->map(fn (array $row) => $this->safeRow([...$context,
                $row['reference'], $row['title'], $row['client'], $row['contact_email'], $row['contact_phone'],
                $row['country'], $row['state'], $row['city'], $row['created_at'], $row['closed_at'],
                $row['assigned_employee'], $row['department'], $row['service_type'], $row['status'], $row['stage'],
                $row['currency'], $row['value'], $row['collected'], $row['outstanding'],
                collect($row['payment_references'])->map(fn ($payment) => implode(' / ', array_filter([
                    '#'.$payment['id'], $payment['date'], $payment['method'], $payment['reference'],
                ])))->implode('; '),
            ]));
        } else {
            $overview = $this->analytics->overview($request->user(), $filters);
            $headers = [...$contextHeaders,
                'Rank', 'Location', 'Country', 'State', 'City', 'Leads', 'Total Deals', 'Open Deals', 'Won Deals', 'Lost Deals',
                'Business Value', 'Won Business Value', 'Collected Revenue', 'Outstanding', 'Contribution %', 'Employees',
            ];
            $data = collect($overview['locations'])->map(fn (array $row) => $this->safeRow([...$context,
                $row['rank'], $row['name'], $row['country'], $row['state'], $row['city'], $row['leads'],
                $row['total_deals'], $row['open_deals'], $row['won_deals'], $row['lost_deals'],
                $this->moneyText($row['total_business_value']), $this->moneyText($row['won_business_value']),
                $this->moneyText($row['collected_revenue']), $this->moneyText($row['outstanding_receivables']),
                $row['contribution_percent'], collect($row['employees'])->pluck('name')->implode(', '),
            ]));
        }

        $market = $filters['market'] ?? 'international';
        $filename = "geographic-{$market}-{$scope}-".now()->format('Y-m-d').'.xls';

        return ReportExporter::excel($filename, $headers, $data);
    }

    /** @return array<string, mixed> */
    private function validatedFilters(Request $request, bool $details = false): array
    {
        $rules = [
            'market' => ['nullable', 'in:international,domestic'],
            'date_basis' => ['nullable', 'in:deal_created,deal_won,payment_collection'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'assigned_to' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:open,won,lost'],
            'service_type' => ['nullable', 'string', 'max:191'],
            'country' => ['nullable', 'string', 'max:191'],
            'state' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:191'],
            'rank_by' => ['nullable', 'in:total_business_value,won_business_value,collected_revenue,total_deals,won_deals'],
            'search' => ['nullable', 'string', 'max:191'],
            'sort_by' => ['nullable', 'in:date,amount'],
            'sort_dir' => ['nullable', 'in:asc,desc'],
        ];
        if ($details) {
            $rules['page'] = ['nullable', 'integer', 'min:1'];
            $rules['per_page'] = ['nullable', 'integer', 'min:1', 'max:100'];
        }

        return $request->validate($rules);
    }

    private function moneyText(array $values): string
    {
        return collect($values)->map(fn ($value) => $value['currency'].' '.number_format((float) $value['amount'], 2, '.', ''))->implode(' | ');
    }

    /** @param list<mixed> $row */
    private function safeRow(array $row): array
    {
        return array_map(function ($value) {
            if (! is_string($value)) {
                return $value;
            }

            return preg_match('/^[=+\-@]/', $value) ? "'{$value}" : $value;
        }, $row);
    }
}
