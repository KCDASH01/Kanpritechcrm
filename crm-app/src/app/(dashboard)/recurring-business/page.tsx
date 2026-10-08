'use client';

import { useState } from 'react';
import Link from 'next/link';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { recurringBusinessApi } from '@/lib/api/recurringBusiness';
import { LEAD_TYPE_LABELS } from '@/lib/leadTypes';
import type { RecurringBusiness } from '@/types';

const money = (value: number, currency = 'INR') => new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 0 }).format(value);
const displayDate = (value?: string) => value ? new Date(`${value}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

export default function RecurringBusinessPage() {
  const qc = useQueryClient();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [frequency, setFrequency] = useState('');
  const [page, setPage] = useState(1);
  const params = { search: search || undefined, status: status || undefined, frequency: frequency || undefined, page };
  const { data, isLoading, isFetching } = useQuery({ queryKey: ['recurring-businesses', params], queryFn: () => recurringBusinessApi.list(params), placeholderData: keepPreviousData });
  const update = useMutation({
    mutationFn: ({ id, status }: { id: number; status: RecurringBusiness['status'] }) => recurringBusinessApi.update(id, { status }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['recurring-businesses'] }),
  });
  const summary = data?.data.summary;
  const cards: [string, string | number][] = [
    ['Active Recurring Businesses', summary?.active_count ?? 0], ['Monthly Recurring Revenue', money(summary?.mrr ?? 0)],
    ['Annual Recurring Revenue', money(summary?.arr ?? 0)], ['Expected This Month', money(summary?.expected_this_month ?? 0)],
    ['Collected This Month', money(summary?.collected_this_month ?? 0)], ['Overdue Recurring Revenue', money(summary?.overdue ?? 0)],
    ['Upcoming Renewals', summary?.upcoming_renewals ?? 0], ['Cancelled / Expired', summary?.cancelled_or_expired ?? 0],
  ];

  return <div className="space-y-6 animate-fade-in">
    <div><h1 className="text-2xl font-bold text-gray-900">Recurring Business</h1><p className="text-sm text-gray-500 mt-1">Active contracts, billing expectations, and collected recurring revenue.</p></div>
    <div className="grid grid-cols-2 xl:grid-cols-4 gap-3">{cards.map(([label, value]) => <div key={label} className="bg-white border border-gray-100 rounded-2xl p-4 shadow-sm"><p className="text-xs text-gray-500">{label}</p><p className="text-xl font-bold text-gray-900 mt-1">{value}</p></div>)}</div>
    <div className="flex flex-wrap gap-3">
      <input value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} placeholder="Search client, company or service…" className="w-72 border border-gray-200 rounded-xl px-3 py-2.5 text-sm" />
      <select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} className="border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white"><option value="">All statuses</option>{['ACTIVE','PAUSED','EXPIRED','CANCELLED','COMPLETED'].map((s) => <option key={s}>{s}</option>)}</select>
      <select value={frequency} onChange={(e) => { setFrequency(e.target.value); setPage(1); }} className="border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white"><option value="">All frequencies</option>{['MONTHLY','QUARTERLY','HALF_YEARLY','YEARLY'].map((s) => <option key={s}>{s}</option>)}</select>
      {isFetching && <span className="text-xs text-gray-400 self-center">Updating…</span>}
    </div>
    <div className="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-x-auto">
      {isLoading ? <p className="p-8 text-center text-gray-400">Loading recurring business…</p> : <table className="min-w-[1200px] w-full text-sm">
        <thead><tr className="bg-gray-50 border-b border-gray-100">{['Client','Service / Product','Recurring Amount','Frequency','Start','Next Billing','End','Owner / Department','Status','Collected','Outstanding','Actions'].map((h) => <th key={h} className="px-4 py-3 text-left text-[11px] uppercase tracking-wide text-gray-500">{h}</th>)}</tr></thead>
        <tbody className="divide-y divide-gray-50">{data?.data.rows.map((row) => <tr key={row.id} className="hover:bg-indigo-50/20">
          <td className="px-4 py-3"><Link href={`/clients/${row.client?.id}`} className="font-semibold text-gray-900 hover:text-indigo-600">{row.client?.company || row.client?.full_name || '—'}</Link><p className="text-xs text-gray-400">{row.client?.full_name}</p></td>
          <td className="px-4 py-3 text-gray-600">{row.service_type ? (LEAD_TYPE_LABELS[row.service_type as keyof typeof LEAD_TYPE_LABELS] ?? row.service_type) : '—'}</td>
          <td className="px-4 py-3 font-semibold">{money(row.amount, row.currency)}</td><td className="px-4 py-3 text-gray-600">{row.frequency.replace('_', '-')}</td>
          <td className="px-4 py-3 text-gray-600">{displayDate(row.start_date)}</td><td className="px-4 py-3 text-gray-600">{displayDate(row.next_billing_date)}</td><td className="px-4 py-3 text-gray-600">{displayDate(row.end_date)}</td>
          <td className="px-4 py-3 text-gray-600">{row.assigned_to?.name || '—'}<p className="text-xs text-gray-400">{row.department?.name}</p></td>
          <td className="px-4 py-3"><span className={`text-xs font-semibold px-2 py-1 rounded-full ${row.status === 'ACTIVE' ? 'bg-emerald-50 text-emerald-700' : row.status === 'PAUSED' ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-600'}`}>{row.status}</span></td>
          <td className="px-4 py-3 text-emerald-700 font-medium">{money(row.collected_revenue, row.currency)}</td><td className="px-4 py-3 text-rose-600 font-medium">{money(row.outstanding, row.currency)}</td>
          <td className="px-4 py-3"><div className="flex gap-2"><Link href={`/deals?search=${encodeURIComponent(row.business_name)}`} className="text-xs text-indigo-600 hover:underline">View deal</Link>{row.status === 'ACTIVE' ? <button onClick={() => update.mutate({ id: row.id, status: 'PAUSED' })} className="text-xs text-amber-600">Pause</button> : row.status === 'PAUSED' ? <button onClick={() => update.mutate({ id: row.id, status: 'ACTIVE' })} className="text-xs text-emerald-600">Resume</button> : null}{!['CANCELLED','COMPLETED'].includes(row.status) && <button onClick={() => update.mutate({ id: row.id, status: 'CANCELLED' })} className="text-xs text-red-500">Cancel</button>}</div></td>
        </tr>)}</tbody>
      </table>}
      {!isLoading && !data?.data.rows.length && <p className="p-10 text-center text-gray-400">No recurring businesses match these filters.</p>}
    </div>
    {data?.meta && data.meta.last_page > 1 && <div className="flex justify-end gap-2"><button disabled={page === 1} onClick={() => setPage((p) => p - 1)} className="px-3 py-2 border rounded-lg disabled:opacity-40">Previous</button><button disabled={page === data.meta.last_page} onClick={() => setPage((p) => p + 1)} className="px-3 py-2 border rounded-lg disabled:opacity-40">Next</button></div>}
  </div>;
}
