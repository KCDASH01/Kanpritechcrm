<?php

namespace App\Services;

use App\Models\BillingDocument;
use App\Models\BillingProfile;
use App\Models\Deal;
use App\Models\RecurringBusiness;
use App\Models\User;
use App\Services\Billing\BillingPermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AssistantCollectionService
{
    private const LIMIT_PER_TYPE = 5;

    public function __construct(
        private readonly RecurringRevenueService $revenue,
        private readonly BillingPermissionService $billingPermissions,
    ) {}

    public function reminders(User $viewer): array
    {
        $timezone = $viewer->organization?->timezone ?: config('app.timezone');
        $today = Carbon::parse(Carbon::today($timezone)->toDateString(), config('app.timezone'));
        $through = $today->copy()->addDays(7)->toDateString();
        $invoices = $this->visibleInvoices($viewer);
        $invoiceBalances = $invoices === null ? null : DB::query()
            ->fromSub($invoices, 'outstanding_invoices');

        // Subtract the balance surfaced by a visible invoice from its commercial reminder.
        // The payments credited against that invoice are already in the deal's payment total.
        $invoiceBalanceFor = static fn (string $table) => (clone $invoiceBalances)
            ->where('balance', '>', 0)->whereColumn('outstanding_invoices.currency', $table.'.currency')
            ->where(function ($query) use ($table) {
                if ($table === 'recurring_businesses') {
                    $query->whereColumn('outstanding_invoices.recurring_business_id', 'recurring_businesses.id')
                        ->orWhereColumn('outstanding_invoices.deal_id', 'recurring_businesses.deal_id');
                } else {
                    $query->whereColumn('outstanding_invoices.deal_id', 'deals.id');
                }
            })->selectRaw('SUM(balance)');

        $contracts = RecurringBusiness::query()
            ->where('organization_id', $viewer->organization_id)
            ->when($viewer->isEmployee(), fn (Builder $query) => $query->where('assigned_to', $viewer->id))
            ->where('status', 'ACTIVE')
            ->where('start_date', '<=', $through)
            ->where(fn (Builder $query) => $query->whereNull('deal_id')
                ->orWhereHas('deal', fn (Builder $deal) => $deal->where('deals.organization_id', $viewer->organization_id)->whereNull('deals.deleted_at')))
            ->with(['deal:id,deleted_at', 'deal.payments' => fn ($query) => $query
                ->where('organization_id', $viewer->organization_id)->select('id', 'deal_id', 'amount', 'payment_date')]);
        if ($invoiceBalances !== null) {
            $contracts->addSelect(['invoiced_balance' => $invoiceBalanceFor('recurring_businesses')]);
        }

        $recurring = $contracts->lazyById(100)->map(function (RecurringBusiness $contract) use ($today, $through) {
            $collected = $this->revenue->collectedAmount($contract, to: $today->copy()->endOfDay());
            $due = $this->revenue->dueAmount($contract, $today);
            $balance = round($due - $collected - (float) $contract->invoiced_balance, 2);
            if ($balance > 0) {
                return $this->alert('recurring_collection', $contract->id, $contract->business_name, $balance, $contract->currency, '/recurring-business', 'urgent');
            }

            $next = $contract->start_date->toDateString() > $today->toDateString()
                ? $contract->start_date
                : $this->revenue->upcomingBillingDate($contract, $today);
            $amount = round($balance + (float) $contract->amount, 2);
            if (! $next || $next->toDateString() > $through || $amount <= 0
                || ($contract->billing_cycles !== null && $this->revenue->cyclesThrough($contract, $today) >= $contract->billing_cycles)) {
                return null;
            }

            return $this->alert('recurring_collection', $contract->id, $contract->business_name, $amount, $contract->currency, '/recurring-business', 'important', $next->toDateString());
        })->filter()->take(self::LIMIT_PER_TYPE)->values()->all();

        $deals = Deal::query()->where('organization_id', $viewer->organization_id)
            ->when($viewer->isEmployee(), fn (Builder $query) => $query->where('assigned_to', $viewer->id))
            ->where('status', 'won')->where('value', '>', 0)
            ->where(fn (Builder $query) => $query->whereNull('business_type')->orWhere('business_type', 'ONE_TIME'))
            ->whereDoesntHave('recurringBusiness', fn (Builder $contract) => $contract->where('organization_id', $viewer->organization_id))
            ->withSum(['payments' => fn ($query) => $query->where('organization_id', $viewer->organization_id)], 'amount');
        if ($invoiceBalances !== null) {
            $deals->addSelect(['invoiced_balance' => $invoiceBalanceFor('deals')]);
        }
        $pendingDeals = DB::query()->fromSub($deals, 'won_deals')
            ->select('id', 'title', 'currency')
            ->selectRaw('value - COALESCE(payments_sum_amount, 0)'.($invoiceBalances === null ? '' : ' - COALESCE(invoiced_balance, 0)').' AS balance');
        $dealAlerts = DB::query()->fromSub($pendingDeals, 'pending_deals')->whereRaw('balance >= 0.005')
            ->orderByDesc('id')->limit(self::LIMIT_PER_TYPE)->get()
            ->map(fn ($deal) => $this->alert('deal_collection', $deal->id, $deal->title, $deal->balance, $deal->currency, '/deals?search='.rawurlencode($deal->title), 'important'))->all();

        $invoiceAlerts = $invoiceBalances?->whereRaw('balance >= 0.005')
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')->orderBy('due_date')->orderBy('id')
            ->limit(self::LIMIT_PER_TYPE)->get()
            ->map(fn ($invoice) => $this->alert(
                'invoice_collection', $invoice->id, $invoice->document_number, $invoice->balance, $invoice->currency,
                '/billing-invoicing/documents/'.$invoice->id,
                $invoice->due_date && $invoice->due_date < $today->toDateString() ? 'urgent' : 'important',
                $invoice->due_date,
            ))->all() ?? [];

        return [...$recurring, ...$invoiceAlerts, ...$dealAlerts];
    }

    private function visibleInvoices(User $viewer): ?Builder
    {
        $profile = BillingProfile::query()->where('organization_id', $viewer->organization_id)->first();
        if (! $this->billingPermissions->allows($viewer, $profile, 'view')) {
            return null;
        }

        return BillingDocument::query()->select('id', 'deal_id', 'recurring_business_id', 'document_number', 'currency', 'due_date')
            ->selectRaw('grand_total - COALESCE((
                SELECT SUM(amount) FROM billing_payment_allocations
                WHERE billing_document_id = billing_documents.id
                    AND organization_id = billing_documents.organization_id
                    AND reversed_at IS NULL
            ), 0) AS balance')
            ->where('organization_id', $viewer->organization_id)
            ->where('document_type', 'invoice')->whereNotNull('issued_at')->whereNull('voided_at')
            ->when($viewer->isEmployee(), fn (Builder $query) => $query->where(
                fn (Builder $scope) => $scope->where('assigned_to', $viewer->id)->orWhere('created_by', $viewer->id)
            ));
    }

    private function alert(string $type, int $id, ?string $title, float $amount, ?string $currency, string $navigation, string $severity, ?string $due = null): array
    {
        return [
            'id' => $type.'-'.$id.($type === 'recurring_collection' && $due ? '-'.$due : ''),
            'type' => $type,
            'severity' => $severity,
            'title' => $title ?: (string) $id,
            'record_id' => $id,
            'amount' => round($amount, 2),
            'currency' => $currency ?: 'INR',
            'due_at' => $due,
            'navigation' => $navigation,
        ];
    }
}
