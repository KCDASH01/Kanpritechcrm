'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { customerGrowthApi, type GrowthMoney, type GrowthRecommendation, type GrowthSettings } from '@/lib/api/customerGrowth';
import { useAuthStore } from '@/store/authStore';

type Tab = 'opportunities' | 'retention' | 'settings';
const STATUSES = ['new', 'under_review', 'contact_planned', 'contacted', 'interested', 'converted', 'not_interested', 'snoozed'];

function money(values: GrowthMoney[]) {
  return values.length ? values.map((row) => new Intl.NumberFormat('en-IN', { style: 'currency', currency: row.currency, maximumFractionDigits: 0 }).format(row.amount)).join(' · ') : 'Estimate required';
}
function label(value: string) { return value.replaceAll('_', ' ').replace(/\b\w/g, (character) => character.toUpperCase()); }

export default function CustomerGrowthPage() {
  const qc = useQueryClient();
  const searchParams = useSearchParams();
  const { isAdmin } = useAuthStore();
  const canManage = isAdmin();
  const clientId = Number(searchParams.get('client_id')) || undefined;
  const [tab, setTab] = useState<Tab>(searchParams.get('tab') === 'retention' ? 'retention' : 'opportunities');
  const [status, setStatus] = useState('');
  const [linkRecommendation, setLinkRecommendation] = useState<GrowthRecommendation | null>(null);
  const overview = useQuery({ queryKey: ['customer-growth', status, clientId], queryFn: () => customerGrowthApi.overview({ status: status || undefined, client_id: clientId }) });
  const update = useMutation({ mutationFn: ({ id, payload }: { id: number; payload: Record<string, unknown> }) => customerGrowthApi.updateRecommendation(id, payload), onSuccess: () => qc.invalidateQueries({ queryKey: ['customer-growth'] }) });
  const refresh = useMutation({ mutationFn: customerGrowthApi.refresh, onSuccess: () => qc.invalidateQueries({ queryKey: ['customer-growth'] }) });
  const task = useMutation({ mutationFn: customerGrowthApi.createRetentionTask, onSuccess: () => qc.invalidateQueries({ queryKey: ['customer-growth'] }) });
  const candidateDeals = useQuery({ queryKey: ['customer-growth-deals', linkRecommendation?.id], queryFn: () => customerGrowthApi.candidateDeals(linkRecommendation!.id), enabled: Boolean(linkRecommendation) });
  const linkDeal = useMutation({ mutationFn: ({ recommendationId, dealId }: { recommendationId: number; dealId: number }) => customerGrowthApi.linkDeal(recommendationId, dealId), onSuccess: () => { setLinkRecommendation(null); qc.invalidateQueries({ queryKey: ['customer-growth'] }); } });
  const data = overview.data;

  return <div className="space-y-5">
    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div><h1 className="text-xl font-bold text-gray-900">Customer Growth & Retention</h1><p className="mt-1 text-xs text-gray-500">Rule-based opportunities, transparent customer health and contract renewals</p></div>
      {canManage && <button onClick={() => refresh.mutate()} disabled={refresh.isPending} className="min-h-10 rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white disabled:opacity-50">Refresh recommendations</button>}
    </div>

    <div className="grid grid-cols-3 gap-1 rounded-xl bg-gray-100 p-1">
      {([['opportunities', 'Growth Opportunities'], ['retention', 'Customer Retention'], ['settings', 'Automation Settings']] as const).map(([value, title]) =>
        <button key={value} onClick={() => setTab(value)} className={`min-h-11 rounded-lg px-2 text-xs font-semibold sm:text-sm ${tab === value ? 'bg-white text-indigo-700 shadow-sm' : 'text-gray-500'}`}>{title}</button>)}
    </div>

    {overview.isLoading && <div className="h-64 animate-pulse rounded-2xl bg-gray-100" />}
    {overview.isError && <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">Customer Growth data could not be loaded. Apply the module migration before opening this page.</div>}

    {data && tab === 'opportunities' && <>
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        {[
          ['Total Opportunities', String(data.summary.total)], ['Estimated Potential', money(data.summary.potential_revenue)],
          ['Cross-Sell', String(data.summary.cross_sell)], ['Upsell', String(data.summary.upsell)],
          ['Converted', String(data.summary.converted)], ['Actual Won Value', money(data.summary.actual_won_value)],
        ].map(([title, value]) => <div key={title} className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm"><p className="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{title}</p><p className="mt-2 break-words text-lg font-bold text-gray-900">{value}</p></div>)}
      </div>
      <div className="flex justify-end"><select value={status} onChange={(event) => setStatus(event.target.value)} className="min-h-10 w-full rounded-xl border border-gray-200 px-3 text-sm sm:w-56"><option value="">All statuses</option>{STATUSES.map((item) => <option key={item} value={item}>{label(item)}</option>)}</select></div>
      <div className="grid gap-4 xl:grid-cols-2">
        {data.recommendations.length === 0 ? <div className="rounded-2xl border border-dashed border-gray-200 bg-white p-10 text-center text-sm text-gray-500 xl:col-span-2">No recommendations yet. Administrators can configure service mappings, then refresh recommendations.</div> :
          data.recommendations.map((row) => <article key={row.id} className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-2"><div><p className="text-xs font-semibold uppercase text-indigo-600">{label(row.recommendation_type)}</p><h2 className="mt-1 font-bold text-gray-900">{row.client?.company || [row.client?.first_name, row.client?.last_name].filter(Boolean).join(' ')}</h2></div><span className="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold">{label(row.priority)}</span></div>
            <p className="mt-3 text-sm text-gray-700"><strong>{row.existing_service}</strong> → <strong>{row.suggested_service}</strong></p>
            <p className="mt-2 text-xs text-gray-500">{row.reason}</p>
            <div className="mt-3 rounded-xl bg-gray-50 p-3 text-xs"><p className="text-gray-500">Potential value</p><strong className="text-sm text-gray-900">{row.potential_value != null && row.currency ? money([{ currency: row.currency, amount: Number(row.potential_value) }]) : 'Estimate required'}</strong><p className="mt-1 text-gray-400">{row.estimate_source || 'No reliable comparable price found'}</p></div>
            <div className="mt-4 grid gap-2 sm:grid-cols-2">
              <select value={row.status} onChange={(event) => update.mutate({ id: row.id, payload: { status: event.target.value } })} className="min-h-10 rounded-xl border border-gray-200 px-3 text-sm">{STATUSES.map((item) => <option key={item} value={item}>{label(item)}</option>)}</select>
              <input type="date" value={row.suggested_follow_up_date?.slice(0, 10) || ''} onChange={(event) => update.mutate({ id: row.id, payload: { suggested_follow_up_date: event.target.value || null } })} className="min-h-10 rounded-xl border border-gray-200 px-3 text-sm" />
            </div>
            <div className="mt-3 flex flex-wrap gap-2">
              <Link href={`/clients/${row.client_id}`} className="flex min-h-10 items-center rounded-xl border border-gray-200 px-3 text-xs font-semibold">Customer 360°</Link>
              <Link href={`/leads?new=1&client_id=${row.client_id}`} className="flex min-h-10 items-center rounded-xl bg-indigo-600 px-3 text-xs font-semibold text-white">Create lead opportunity</Link>
              <button onClick={() => setLinkRecommendation(row)} className="min-h-10 rounded-xl border border-indigo-200 px-3 text-xs font-semibold text-indigo-700">Link created deal</button>
            </div>
          </article>)}
      </div>
    </>}

    {data && tab === 'retention' && <div className="space-y-5">
      <section><h2 className="mb-3 font-bold text-gray-900">Customer Health</h2><div className="grid gap-3 lg:grid-cols-2">{data.health.map((row) => <article key={row.client_id} className="rounded-2xl border border-gray-100 bg-white p-4"><div className="flex justify-between gap-3"><Link href={`/clients/${row.client_id}`} className="font-semibold text-gray-900 hover:text-indigo-600">{row.client_name}</Link><span className="rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold">{label(row.status)}</span></div><ul className="mt-3 list-disc space-y-1 pl-5 text-xs text-gray-500">{row.reasons.length ? row.reasons.map((reason) => <li key={reason}>{reason}</li>) : <li>Insufficient Data</li>}</ul></article>)}</div></section>
      <section><h2 className="mb-3 font-bold text-gray-900">Contract Renewals</h2><div className="overflow-hidden rounded-2xl border border-gray-100 bg-white"><div className="divide-y divide-gray-100">{data.renewals.map((row) => <div key={row.id} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center"><div className="min-w-0 flex-1"><p className="font-semibold text-gray-900">{row.client_name}</p><p className="text-xs text-gray-500">{row.service} · {row.renewal_date} · {label(row.status)}</p></div><p className="text-sm font-semibold">{row.days_remaining} days</p><button onClick={() => task.mutate({ client_id: row.client_id, recurring_business_id: row.id, task_type: 'renewal', due_date: row.renewal_date, reason: `Renewal follow-up for ${row.service}` })} className="min-h-10 rounded-xl border border-indigo-200 px-3 text-xs font-semibold text-indigo-700">Create retention task</button></div>)}</div></div></section>
    </div>}

    {data && tab === 'settings' && (canManage ? <Settings initial={data.settings} /> : <div className="rounded-2xl border border-amber-200 bg-amber-50 p-6 text-sm text-amber-800">Only Business Owners and Administrators can configure organization-wide automation.</div>)}

    {linkRecommendation && <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4" onMouseDown={(event) => { if (event.target === event.currentTarget) setLinkRecommendation(null); }}>
      <div role="dialog" aria-modal="true" aria-labelledby="link-deal-title" className="max-h-[85vh] w-full overflow-y-auto rounded-t-2xl bg-white p-4 shadow-xl sm:max-w-xl sm:rounded-2xl sm:p-6">
        <div className="flex items-start justify-between gap-4"><div><h2 id="link-deal-title" className="font-bold text-gray-900">Select the created deal</h2><p className="mt-1 text-xs text-gray-500">Choose a deal belonging to this customer. No deal ID is required.</p></div><button onClick={() => setLinkRecommendation(null)} aria-label="Close" className="min-h-10 min-w-10 rounded-lg text-xl text-gray-500 hover:bg-gray-100">×</button></div>
        {candidateDeals.isLoading && <div className="mt-4 h-28 animate-pulse rounded-xl bg-gray-100" />}
        {candidateDeals.isError && <p className="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">Deals could not be loaded. Please close this window and try again.</p>}
        {candidateDeals.data?.length === 0 && <div className="mt-4 rounded-xl border border-dashed border-gray-200 p-5 text-center"><p className="text-sm text-gray-600">No deals are linked to this customer yet.</p><Link href={`/leads?new=1&client_id=${linkRecommendation.client_id}`} className="mt-3 inline-flex min-h-10 items-center rounded-xl bg-indigo-600 px-4 text-xs font-semibold text-white">Create lead opportunity</Link></div>}
        <div className="mt-4 space-y-2">{candidateDeals.data?.map((deal) => <div key={deal.id} className="flex flex-col gap-3 rounded-xl border border-gray-200 p-3 sm:flex-row sm:items-center"><div className="min-w-0 flex-1"><p className="break-words text-sm font-semibold text-gray-900">{deal.title}</p><p className="mt-1 text-xs text-gray-500">{label(deal.status)} · {money([{ currency: deal.currency || 'INR', amount: Number(deal.value) }])}{deal.closed_at ? ` · ${deal.closed_at}` : ''}</p></div><button onClick={() => linkDeal.mutate({ recommendationId: linkRecommendation.id, dealId: deal.id })} disabled={linkDeal.isPending} className="min-h-10 rounded-xl bg-indigo-600 px-4 text-xs font-semibold text-white disabled:opacity-50">Link this deal</button></div>)}</div>
        {linkDeal.isError && <p className="mt-3 rounded-xl bg-red-50 p-3 text-sm text-red-700">This deal could not be linked. Confirm it belongs to the same customer and try again.</p>}
      </div>
    </div>}
  </div>;
}

function Settings({ initial }: { initial: GrowthSettings }) {
  const qc = useQueryClient();
  const [settings, setSettings] = useState(initial);
  const [mappings, setMappings] = useState(JSON.stringify(initial.service_mappings ?? [], null, 2));
  useEffect(() => { setSettings(initial); setMappings(JSON.stringify(initial.service_mappings ?? [], null, 2)); }, [initial]);
  const save = useMutation({ mutationFn: () => customerGrowthApi.updateSettings({ ...settings, service_mappings: JSON.parse(mappings) }), onSuccess: () => qc.invalidateQueries({ queryKey: ['customer-growth'] }) });
  const toggle = (key: keyof GrowthSettings) => setSettings((current) => ({ ...current, [key]: !current[key] }));

  return <div className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm sm:p-6"><div className="grid gap-3 sm:grid-cols-2">{[
    ['cross_sell_enabled', 'Cross-sell recommendations'], ['upsell_enabled', 'Upsell recommendations'],
    ['renewal_reminders_enabled', 'Renewal reminders'], ['health_alerts_enabled', 'Customer health alerts'],
  ].map(([key, title]) => <label key={key} className="flex min-h-12 items-center justify-between rounded-xl border border-gray-200 px-3 text-sm font-semibold"><span>{title}</span><input type="checkbox" checked={Boolean(settings[key as keyof GrowthSettings])} onChange={() => toggle(key as keyof GrowthSettings)} /></label>)}</div>
    <label className="mt-4 block text-sm font-semibold text-gray-700">Reminder intervals (comma-separated days)<input value={settings.reminder_intervals.join(', ')} onChange={(event) => setSettings((current) => ({ ...current, reminder_intervals: event.target.value.split(',').map(Number).filter(Number.isFinite) }))} className="mt-1 min-h-10 w-full rounded-xl border border-gray-200 px-3 font-normal" /></label>
    <label className="mt-4 block text-sm font-semibold text-gray-700">Service recommendation mappings (JSON)<textarea value={mappings} onChange={(event) => setMappings(event.target.value)} rows={12} className="mt-1 w-full rounded-xl border border-gray-200 p-3 font-mono text-xs font-normal" /></label>
    {save.isError && <p className="mt-2 text-xs text-red-600">Settings could not be saved. Check that the mapping JSON is valid.</p>}
    <button onClick={() => { try { JSON.parse(mappings); save.mutate(); } catch { alert('Service mappings must be valid JSON.'); } }} disabled={save.isPending} className="mt-4 min-h-11 w-full rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white sm:w-auto">Save Automation Settings</button>
  </div>;
}

