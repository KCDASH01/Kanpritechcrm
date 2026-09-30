'use client';

import { useState } from 'react';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { reportsApi } from '@/lib/api/reports';
import { employeesApi } from '@/lib/api/employees';
import { Badge } from '@/components/ui/Badge';
import { SkeletonTable } from '@/components/ui/Skeleton';
import type { RevenueReportFilters } from '@/types';

const PAYMENT_MODES = [
  { value: 'bank_transfer', label: 'Bank Transfer' },
  { value: 'upi',           label: 'UPI' },
  { value: 'cash',          label: 'Cash' },
  { value: 'cheque',        label: 'Cheque' },
  { value: 'card',          label: 'Card' },
  { value: 'aggregator',    label: 'Aggregator' },
  { value: 'other',         label: 'Other' },
];

function fmtCurrency(n: number) {
  return `₹${Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 })}`;
}

function fmtDate(d?: string | null) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
}

function fmtPaymentMode(mode?: string | null) {
  if (!mode) return '—';
  return PAYMENT_MODES.find((m) => m.value === mode)?.label ?? mode.replace(/_/g, ' ');
}

const inputCls = 'border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500';

interface Props {
  assignedToFilter?: number;
  canFilterByMember?: boolean;
}

export function RevenueReportTab({ assignedToFilter, canFilterByMember = true }: Props) {
  const [assignedTo, setAssignedTo]   = useState('');
  const [dealStatus, setDealStatus]   = useState('');
  const [paymentMode, setPaymentMode] = useState('');
  const [dateFrom, setDateFrom]       = useState('');
  const [dateTo, setDateTo]           = useState('');
  const [page, setPage]               = useState(1);
  const [exporting, setExporting]     = useState<'csv' | 'xlsx' | null>(null);

  const filters: RevenueReportFilters = {
    assigned_to:  canFilterByMember
      ? (assignedTo ? Number(assignedTo) : undefined)
      : assignedToFilter,
    deal_status:  dealStatus || undefined,
    payment_mode: paymentMode || undefined,
    date_from:    dateFrom || undefined,
    date_to:      dateTo || undefined,
    page,
    per_page:     20,
  };

  const { data: employees } = useQuery({
    queryKey: ['employees', { role: 'employee' }],
    queryFn:  () => employeesApi.list({ role: 'employee' }),
    staleTime: 5 * 60_000,
    enabled:  canFilterByMember,
  });

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['reports-revenue', filters],
    queryFn:  () => reportsApi.revenueReport(filters),
    placeholderData: keepPreviousData,
  });

  const summary = data?.data.summary;
  const rows    = data?.data.rows ?? [];
  const meta    = data?.meta;

  const handleExport = async (format: 'csv' | 'xlsx') => {
    setExporting(format);
    try {
      const { page: _page, per_page: _perPage, ...exportFilters } = filters;
      await reportsApi.exportRevenueReport(exportFilters, format);
    } finally {
      setExporting(null);
    }
  };

  const clearFilters = () => {
    setAssignedTo('');
    setDealStatus('');
    setPaymentMode('');
    setDateFrom('');
    setDateTo('');
    setPage(1);
  };

  const hasFilters = !!(assignedTo || dealStatus || paymentMode || dateFrom || dateTo);

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h2 className="text-sm font-semibold text-gray-900">Revenue Report</h2>
          <p className="text-xs text-gray-400 mt-0.5">
            {canFilterByMember
              ? 'Payment transactions across deals in your organisation'
              : 'Payment transactions for your assigned deals'}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <button
            onClick={() => handleExport('csv')}
            disabled={!!exporting}
            className="px-3 py-2 text-xs font-semibold border border-gray-200 rounded-xl hover:bg-gray-50 disabled:opacity-60"
          >
            {exporting === 'csv' ? 'Exporting…' : 'Export CSV'}
          </button>
          <button
            onClick={() => handleExport('xlsx')}
            disabled={!!exporting}
            className="px-3 py-2 text-xs font-semibold bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 disabled:opacity-60"
          >
            {exporting === 'xlsx' ? 'Exporting…' : 'Export Excel'}
          </button>
        </div>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[
          { label: 'Total Revenue',            value: fmtCurrency(summary?.total_revenue ?? 0),            color: 'text-emerald-600', bg: 'bg-emerald-50' },
          { label: 'Revenue This Month',       value: fmtCurrency(summary?.revenue_this_month ?? 0),       color: 'text-blue-600',    bg: 'bg-blue-50'    },
          { label: 'Total Transactions',       value: (summary?.total_transactions ?? 0).toLocaleString(), color: 'text-indigo-600',  bg: 'bg-indigo-50'  },
          { label: 'Avg Revenue Per Deal',     value: fmtCurrency(summary?.average_revenue_per_deal ?? 0), color: 'text-violet-600',  bg: 'bg-violet-50'  },
        ].map((card) => (
          <div key={card.label} className={`${card.bg} rounded-2xl p-4 border border-white`}>
            <p className="text-[11px] font-semibold text-gray-500 uppercase tracking-wide">{card.label}</p>
            <p className={`text-2xl font-bold mt-1 ${card.color}`}>{card.value}</p>
          </div>
        ))}
      </div>

      <div className="flex flex-wrap gap-3 items-center">
        {canFilterByMember && (
          <select value={assignedTo} onChange={(e) => { setAssignedTo(e.target.value); setPage(1); }} className={inputCls}>
            <option value="">All members</option>
            {(employees ?? []).map((emp) => (
              <option key={emp.id} value={emp.id}>{emp.name}</option>
            ))}
          </select>
        )}
        <select value={dealStatus} onChange={(e) => { setDealStatus(e.target.value); setPage(1); }} className={inputCls}>
          <option value="">All deal statuses</option>
          <option value="open">Open</option>
          <option value="won">Won</option>
          <option value="lost">Lost</option>
        </select>
        <select value={paymentMode} onChange={(e) => { setPaymentMode(e.target.value); setPage(1); }} className={inputCls}>
          <option value="">All payment methods</option>
          {PAYMENT_MODES.map((m) => (
            <option key={m.value} value={m.value}>{m.label}</option>
          ))}
        </select>
        <input type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPage(1); }} className={inputCls} />
        <input type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPage(1); }} className={inputCls} />
        {hasFilters && (
          <button onClick={clearFilters} className="px-3 py-2 text-xs font-medium text-gray-600 border border-gray-200 rounded-xl hover:bg-gray-50">
            Clear filters
          </button>
        )}
        {isFetching && !isLoading && (
          <span className="text-xs text-gray-400">Updating…</span>
        )}
      </div>

      <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        {isLoading ? (
          <SkeletonTable rows={8} cols={8} />
        ) : rows.length === 0 ? (
          <div className="px-6 py-16 text-center text-sm text-gray-400">No transactions match your filters.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead>
                <tr className="bg-gray-50 border-b border-gray-100">
                  {['Deal', 'Client', 'Assigned', 'Deal Value', 'Received', 'Remaining', 'Txn Amount', 'Payment Date', 'Method', 'Notes', 'Status'].map((h) => (
                    <th key={h} className="px-4 py-3 text-left text-[11px] font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {rows.map((row) => (
                  <tr key={row.id} className="hover:bg-gray-50/60">
                    <td className="px-4 py-3 font-medium text-gray-900 whitespace-nowrap">{row.deal_name ?? '—'}</td>
                    <td className="px-4 py-3 text-gray-600 whitespace-nowrap">{row.client_name ?? '—'}</td>
                    <td className="px-4 py-3 text-gray-600 whitespace-nowrap">{row.assigned_member ?? 'Unassigned'}</td>
                    <td className="px-4 py-3 text-gray-700 whitespace-nowrap">{row.deal_value != null ? fmtCurrency(row.deal_value) : '—'}</td>
                    <td className="px-4 py-3 text-emerald-700 whitespace-nowrap">{fmtCurrency(row.amount_received)}</td>
                    <td className="px-4 py-3 text-amber-700 whitespace-nowrap">{row.remaining_amount != null ? fmtCurrency(row.remaining_amount) : '—'}</td>
                    <td className="px-4 py-3 font-semibold text-gray-900 whitespace-nowrap">{fmtCurrency(row.transaction_amount)}</td>
                    <td className="px-4 py-3 text-gray-500 whitespace-nowrap">{fmtDate(row.payment_date)}</td>
                    <td className="px-4 py-3 text-gray-600 whitespace-nowrap">{fmtPaymentMode(row.payment_method)}</td>
                    <td className="px-4 py-3 text-gray-500 max-w-[180px] truncate">{row.transaction_notes ?? '—'}</td>
                    <td className="px-4 py-3">{row.deal_status ? <Badge value={row.deal_status} /> : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {meta && meta.last_page > 1 && (
          <div className="px-5 py-4 border-t border-gray-100 flex items-center justify-between">
            <span className="text-xs text-gray-400">Page {meta.current_page} of {meta.last_page} · {meta.total} transactions</span>
            <div className="flex gap-2">
              <button onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page === 1}
                className="px-3 py-1.5 border border-gray-200 rounded-lg text-xs font-medium disabled:opacity-40 hover:bg-gray-50">← Prev</button>
              <button onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))} disabled={page === meta.last_page}
                className="px-3 py-1.5 border border-gray-200 rounded-lg text-xs font-medium disabled:opacity-40 hover:bg-gray-50">Next →</button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
