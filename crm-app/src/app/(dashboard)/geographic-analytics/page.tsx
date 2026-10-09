'use client';

import { useMemo, useState } from 'react';
import Link from 'next/link';
import { useQuery } from '@tanstack/react-query';
import { geographicAnalyticsApi } from '@/lib/api/geographicAnalytics';
import { employeesApi } from '@/lib/api/employees';
import { departmentsApi } from '@/lib/api/departments';
import { useAuthStore } from '@/store/authStore';
import { Badge } from '@/components/ui/Badge';
import type {
  GeographicDealRow,
  GeographicFilters,
  GeographicLocationRow,
  GeographicMarket,
  GeographicMoney,
  GeographicRankMetric,
} from '@/types';

type DatePreset = 'all_time' | 'today' | 'this_week' | 'this_month' | 'last_month' | 'this_quarter' | 'financial_year' | 'last_financial_year' | 'custom';

const DATE_PRESETS: { value: DatePreset; label: string }[] = [
  { value: 'all_time', label: 'All Time' },
  { value: 'today', label: 'Today' },
  { value: 'this_week', label: 'This Week' },
  { value: 'this_month', label: 'This Month' },
  { value: 'last_month', label: 'Last Month' },
  { value: 'this_quarter', label: 'This Quarter' },
  { value: 'financial_year', label: 'This Financial Year' },
  { value: 'last_financial_year', label: 'Last Financial Year' },
  { value: 'custom', label: 'Custom Range' },
];

const RANK_OPTIONS: { value: GeographicRankMetric; label: string }[] = [
  { value: 'total_business_value', label: 'Highest business value' },
  { value: 'won_business_value', label: 'Highest won value' },
  { value: 'collected_revenue', label: 'Highest collected revenue' },
  { value: 'total_deals', label: 'Highest deal count' },
  { value: 'won_deals', label: 'Highest won deals' },
];

const COUNTRY_CODES: Record<string, string> = {
  'United States': 'US', 'United Kingdom': 'GB', Canada: 'CA', Australia: 'AU', Germany: 'DE',
  'United Arab Emirates': 'AE', India: 'IN', France: 'FR', Singapore: 'SG', Japan: 'JP',
};

function iso(date: Date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function presetRange(preset: DatePreset): { from?: string; to?: string } {
  const now = new Date();
  const startOfDay = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  if (preset === 'all_time' || preset === 'custom') return {};
  if (preset === 'today') return { from: iso(startOfDay), to: iso(startOfDay) };
  if (preset === 'this_week') {
    const day = startOfDay.getDay() || 7;
    const start = new Date(startOfDay); start.setDate(start.getDate() - day + 1);
    return { from: iso(start), to: iso(startOfDay) };
  }
  if (preset === 'this_month') return { from: iso(new Date(now.getFullYear(), now.getMonth(), 1)), to: iso(startOfDay) };
  if (preset === 'last_month') return { from: iso(new Date(now.getFullYear(), now.getMonth() - 1, 1)), to: iso(new Date(now.getFullYear(), now.getMonth(), 0)) };
  if (preset === 'this_quarter') {
    const quarterMonth = Math.floor(now.getMonth() / 3) * 3;
    return { from: iso(new Date(now.getFullYear(), quarterMonth, 1)), to: iso(startOfDay) };
  }
  const fyYear = now.getMonth() >= 3 ? now.getFullYear() : now.getFullYear() - 1;
  if (preset === 'financial_year') return { from: iso(new Date(fyYear, 3, 1)), to: iso(startOfDay) };
  return { from: iso(new Date(fyYear - 1, 3, 1)), to: iso(new Date(fyYear, 2, 31)) };
}

function flag(country: string) {
  const code = COUNTRY_CODES[country];
  return code ? String.fromCodePoint(...code.split('').map((letter) => 127397 + letter.charCodeAt(0))) : '🌐';
}

function moneyText(values: GeographicMoney[]) {
  if (!values.length) return '—';
  return values.map(({ currency, amount }) => {
    try {
      return new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 0 }).format(amount);
    } catch {
      return `${currency} ${amount.toLocaleString('en-IN')}`;
    }
  }).join(' · ');
}

function KpiCard({ label, value, tone, hint }: { label: string; value: string; tone: string; hint?: string }) {
  return (
    <div className={`min-w-0 rounded-2xl border p-4 ${tone}`}>
      <p className="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{label}</p>
      <p className="mt-2 break-words text-xl font-bold text-gray-900">{value}</p>
      {hint && <p className="mt-1 text-[11px] text-gray-500">{hint}</p>}
    </div>
  );
}

function LocationCard({ row, level, onClick }: { row: GeographicLocationRow; level: 'country' | 'state' | 'city'; onClick: () => void }) {
  const podium = row.rank === 1
    ? 'border-[#E7C75D] bg-[#FFF7DF]'
    : row.rank === 2 ? 'border-slate-200 bg-[#F1F5F9]' : row.rank === 3 ? 'border-[#E7B993] bg-[#FFF0E5]' : 'border-gray-100 bg-white';
  return (
    <button onClick={onClick} className={`w-full rounded-2xl border p-4 text-left shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md ${podium}`}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-xs font-semibold text-gray-500">Rank #{row.rank}</p>
          <h3 className="mt-1 flex items-center gap-2 break-words text-base font-bold text-gray-900">
            {level === 'country' && <span>{flag(row.name)}</span>}{row.name}
          </h3>
        </div>
        <span className="rounded-full bg-white/80 px-2.5 py-1 text-xs font-bold text-indigo-600">{row.contribution_percent}%</span>
      </div>
      <div className="mt-4 grid grid-cols-3 gap-2 text-center">
        <div><p className="text-lg font-bold text-gray-900">{row.total_deals}</p><p className="text-[10px] text-gray-500">Deals</p></div>
        <div><p className="text-lg font-bold text-emerald-700">{row.won_deals}</p><p className="text-[10px] text-gray-500">Won</p></div>
        <div><p className="text-lg font-bold text-gray-900">{row.open_deals}</p><p className="text-[10px] text-gray-500">Open</p></div>
      </div>
      <div className="mt-4 space-y-2 border-t border-black/5 pt-3">
        <div className="flex justify-between gap-3 text-xs"><span className="text-gray-500">Business value</span><strong className="text-right text-gray-800">{moneyText(row.total_business_value)}</strong></div>
        <div className="flex justify-between gap-3 text-xs"><span className="text-gray-500">Won value</span><strong className="text-right text-gray-800">{moneyText(row.won_business_value)}</strong></div>
        <div className="flex justify-between gap-3 text-xs"><span className="text-gray-500">Collected</span><strong className="text-right text-teal-700">{moneyText(row.collected_revenue)}</strong></div>
        <div className="flex justify-between gap-3 text-xs"><span className="text-gray-500">Outstanding</span><strong className="text-right text-amber-700">{moneyText(row.outstanding_receivables)}</strong></div>
      </div>
      <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-black/5"><div className="h-full rounded-full bg-indigo-500" style={{ width: `${Math.min(100, row.contribution_percent)}%` }} /></div>
      <p className="mt-3 truncate text-[11px] text-gray-500">{row.employees.length ? row.employees.map((employee) => employee.name).join(', ') : 'No employee assigned'}</p>
    </button>
  );
}

export default function GeographicAnalyticsPage() {
  const { user, isAdmin } = useAuthStore();
  const canManage = isAdmin();
  const [market, setMarket] = useState<GeographicMarket>('international');
  const [datePreset, setDatePreset] = useState<DatePreset>('all_time');
  const [customFrom, setCustomFrom] = useState('');
  const [customTo, setCustomTo] = useState('');
  const [dateBasis, setDateBasis] = useState<GeographicFilters['date_basis']>('deal_created');
  const [rankBy, setRankBy] = useState<GeographicRankMetric>('total_business_value');
  const [status, setStatus] = useState('');
  const [service, setService] = useState('');
  const [employee, setEmployee] = useState('');
  const [department, setDepartment] = useState('');
  const [state, setState] = useState('');
  const [city, setCity] = useState('');
  const [country, setCountry] = useState('');
  const [detailOpen, setDetailOpen] = useState(false);
  const [search, setSearch] = useState('');
  const [sortBy, setSortBy] = useState<'date' | 'amount'>('date');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');
  const [page, setPage] = useState(1);
  const [exporting, setExporting] = useState(false);

  const range = datePreset === 'custom' ? { from: customFrom || undefined, to: customTo || undefined } : presetRange(datePreset);
  const filters = useMemo<GeographicFilters>(() => ({
    market, date_basis: dateBasis, date_from: range.from, date_to: range.to,
    assigned_to: employee ? Number(employee) : undefined,
    department_id: department ? Number(department) : undefined,
    status: status as GeographicFilters['status'], service_type: service || undefined,
    country: country || undefined, state: state || undefined, city: city || undefined, rank_by: rankBy,
  }), [market, dateBasis, range.from, range.to, employee, department, status, service, country, state, city, rankBy]);

  const overview = useQuery({ queryKey: ['geographic-analytics', filters], queryFn: () => geographicAnalyticsApi.overview(filters) });
  const detailsFilters = useMemo<GeographicFilters>(() => ({ ...filters, search: search || undefined, sort_by: sortBy, sort_dir: sortDir, page, per_page: 20 }), [filters, search, sortBy, sortDir, page]);
  const details = useQuery({ queryKey: ['geographic-analytics-deals', detailsFilters], queryFn: () => geographicAnalyticsApi.deals(detailsFilters), enabled: detailOpen });
  const employees = useQuery({ queryKey: ['employees', 'geographic-filter'], queryFn: () => employeesApi.list(), enabled: canManage });
  const departments = useQuery({ queryKey: ['departments', 'geographic-filter'], queryFn: departmentsApi.list, enabled: canManage });

  const data = overview.data;
  const level = data?.meta.level ?? (market === 'international' ? 'country' : state ? 'city' : 'state');
  const selectedLocation = city || state || country;

  const resetDrilldown = () => { setCountry(''); setState(''); setCity(''); setDetailOpen(false); setSearch(''); setPage(1); };
  const switchMarket = (next: GeographicMarket) => { setMarket(next); resetDrilldown(); };
  const openLocation = (row: GeographicLocationRow) => {
    setPage(1); setSearch('');
    if (market === 'international') { setCountry(row.name); setDetailOpen(true); return; }
    if (level === 'state') { setState(row.name); setCity(''); setDetailOpen(false); return; }
    setCity(row.name); setDetailOpen(true);
  };

  const exportPdf = async () => {
    if (!data) return;
    setExporting(true);
    try {
      const { jsPDF } = await import('jspdf');
      const doc = new jsPDF();
      let y = 18;
      const line = (text: string, size = 9) => {
        if (y > 280) { doc.addPage(); y = 18; }
        doc.setFontSize(size); doc.text(text.slice(0, 115), 14, y); y += size > 11 ? 8 : 6;
      };
      line(user?.organization?.name ?? 'CRM', 15);
      line('Geographic Business Analytics', 13);
      line(`Market: ${market === 'international' ? 'International' : 'Domestic'} | Date basis: ${dateBasis?.replaceAll('_', ' ')}`);
      line(`Period: ${range.from ?? 'All time'} to ${range.to ?? 'Present'}`);
      line(`Total deals: ${data.summary.total_deals} | Won deals: ${data.summary.won_deals}`);
      line(`Business value: ${moneyText(data.summary.total_business_value)}`);
      line(`Collected: ${moneyText(data.summary.collected_revenue)}`);
      y += 3;
      if (detailOpen && details.data) {
        line(`Deals for ${selectedLocation}`, 12);
        const firstResponse = await geographicAnalyticsApi.deals({ ...detailsFilters, page: 1, per_page: 100 });
        const reportRows = [...firstResponse.data.rows];
        for (let exportPage = 2; exportPage <= firstResponse.meta.last_page; exportPage++) {
          const response = await geographicAnalyticsApi.deals({ ...detailsFilters, page: exportPage, per_page: 100 });
          reportRows.push(...response.data.rows);
        }
        reportRows.forEach((row) => line(`${row.reference} | ${row.title} | ${row.client ?? '—'} | ${row.currency} ${row.value.toLocaleString('en-IN')} | ${row.status}`));
      } else {
        line(`Location ranking (${data.ranking.applied_metric.replaceAll('_', ' ')})`, 12);
        data.locations.forEach((row) => line(`#${row.rank} ${row.name} | Deals ${row.total_deals} | Won ${row.won_deals} | Value ${moneyText(row.total_business_value)} | Collected ${moneyText(row.collected_revenue)}`));
      }
      doc.save(`geographic-${market}-${new Date().toISOString().slice(0, 10)}.pdf`);
    } finally { setExporting(false); }
  };

  const exportExcel = async () => {
    setExporting(true);
    try { await geographicAnalyticsApi.exportExcel(detailsFilters, detailOpen ? 'details' : 'summary'); }
    finally { setExporting(false); }
  };

  return (
    <div className="space-y-5">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div><h1 className="text-xl font-bold text-gray-900">Geographic Business Analytics</h1><p className="mt-1 text-xs text-gray-500">Business performance by country, state and city using authorized CRM records</p></div>
        <div className="flex gap-2">
          <button disabled={exporting || !data} onClick={exportExcel} className="min-h-10 flex-1 rounded-xl border border-gray-200 bg-white px-3 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50 sm:flex-none">Export Excel</button>
          <button disabled={exporting || !data} onClick={exportPdf} className="min-h-10 flex-1 rounded-xl bg-indigo-600 px-3 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 sm:flex-none">Export PDF</button>
        </div>
      </div>

      <div className="rounded-2xl border border-gray-100 bg-white p-3 shadow-sm">
        <div className="grid grid-cols-2 gap-1 rounded-xl bg-gray-100 p-1 sm:w-fit">
          <button onClick={() => switchMarket('international')} className={`min-h-10 rounded-lg px-4 text-sm font-semibold ${market === 'international' ? 'bg-white text-indigo-700 shadow-sm' : 'text-gray-500'}`}>International Market</button>
          <button onClick={() => switchMarket('domestic')} className={`min-h-10 rounded-lg px-4 text-sm font-semibold ${market === 'domestic' ? 'bg-white text-indigo-700 shadow-sm' : 'text-gray-500'}`}>Domestic Market</button>
        </div>
        <div className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <select value={datePreset} onChange={(event) => { setDatePreset(event.target.value as DatePreset); setPage(1); }} className="min-h-10 rounded-xl border border-gray-200 px-3 text-sm"><option disabled>Date range</option>{DATE_PRESETS.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select>
          <select value={dateBasis} onChange={(event) => { setDateBasis(event.target.value as GeographicFilters['date_basis']); setPage(1); }} className="min-h-10 rounded-xl border border-gray-200 px-3 text-sm"><option value="deal_created">Deal Created Date</option><option value="deal_won">Deal Won Date</option><option value="payment_collection">Payment Collection Date</option></select>
          <select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }} disabled={dateBasis === 'deal_won'} className="min-h-10 rounded-xl border border-gray-200 px-3 text-sm disabled:bg-gray-50"><option value="">All deal statuses</option><option value="open">Open</option><option value="won">Won</option><option value="lost">Lost</option></select>
          <select value={rankBy} onChange={(event) => setRankBy(event.target.value as GeographicRankMetric)} className="min-h-10 rounded-xl border border-gray-200 px-3 text-sm">{RANK_OPTIONS.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select>
          {canManage && <select value={employee} onChange={(event) => { setEmployee(event.target.value); setPage(1); }} className="min-h-10 rounded-xl border border-gray-200 px-3 text-sm"><option value="">Overall Team</option>{employees.data?.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>}
          {canManage && <select value={department} onChange={(event) => { setDepartment(event.target.value); setPage(1); }} className="min-h-10 rounded-xl border border-gray-200 px-3 text-sm"><option value="">All Departments</option>{departments.data?.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>}
          <select value={service} onChange={(event) => { setService(event.target.value); setPage(1); }} className="min-h-10 rounded-xl border border-gray-200 px-3 text-sm"><option value="">All services</option>{data?.options.services.map((item) => <option key={item} value={item}>{item}</option>)}</select>
          {market === 'international' && <select value={country} onChange={(event) => { setCountry(event.target.value); setDetailOpen(Boolean(event.target.value)); setPage(1); }} className="min-h-10 rounded-xl border border-gray-200 px-3 text-sm"><option value="">All countries</option>{data?.options.countries.map((item) => <option key={item} value={item}>{item}</option>)}</select>}
          {market === 'domestic' && <select value={state} onChange={(event) => { setState(event.target.value); setCity(''); setDetailOpen(false); setPage(1); }} className="min-h-10 rounded-xl border border-gray-200 px-3 text-sm"><option value="">All states / UTs</option>{data?.options.states.map((item) => <option key={item} value={item}>{item}</option>)}</select>}
          {market === 'domestic' && state && <select value={city} onChange={(event) => { setCity(event.target.value); setDetailOpen(Boolean(event.target.value)); setPage(1); }} className="min-h-10 rounded-xl border border-gray-200 px-3 text-sm"><option value="">All cities</option>{data?.options.cities[state]?.map((item) => <option key={item} value={item}>{item}</option>)}</select>}
        </div>
        {datePreset === 'custom' && <div className="mt-3 grid gap-3 sm:grid-cols-2"><label className="text-xs font-semibold text-gray-600">From<input type="date" value={customFrom} onChange={(event) => setCustomFrom(event.target.value)} className="mt-1 block min-h-10 w-full rounded-xl border border-gray-200 px-3 text-sm" /></label><label className="text-xs font-semibold text-gray-600">To<input type="date" min={customFrom} value={customTo} onChange={(event) => setCustomTo(event.target.value)} className="mt-1 block min-h-10 w-full rounded-xl border border-gray-200 px-3 text-sm" /></label></div>}
      </div>

      {data && <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        <KpiCard label="Total Deals" value={String(data.summary.total_deals)} tone="border-blue-100 bg-blue-50" />
        <KpiCard label="Won Deals" value={String(data.summary.won_deals)} tone="border-emerald-100 bg-emerald-50" />
        <KpiCard label="Total Business Value" value={moneyText(data.summary.total_business_value)} tone="border-violet-100 bg-violet-50" />
        <KpiCard label="Won Business Value" value={moneyText(data.summary.won_business_value)} tone="border-green-100 bg-green-50" />
        <KpiCard label="Revenue Collected" value={moneyText(data.summary.collected_revenue)} tone="border-teal-100 bg-teal-50" />
        <KpiCard label={`Top ${level}`} value={data.locations[0]?.name ?? '—'} hint={data.locations[0] ? `#1 by ${data.ranking.applied_metric.replaceAll('_', ' ')}` : undefined} tone="border-amber-100 bg-amber-50" />
      </div>}

      <div className="flex flex-wrap items-center gap-2 text-xs text-gray-500">
        <button onClick={resetDrilldown} className="font-semibold text-indigo-600 hover:underline">Geographic Analytics</button><span>›</span><button onClick={() => { setDetailOpen(false); setCountry(''); setState(''); setCity(''); }} className="font-semibold text-indigo-600 hover:underline">{market === 'international' ? 'International' : 'Domestic'}</button>
        {state && <><span>›</span><button onClick={() => { setCity(''); setDetailOpen(false); }} className="font-semibold text-indigo-600 hover:underline">{state}</button></>}
        {selectedLocation && detailOpen && <><span>›</span><span className="font-semibold text-gray-700">{selectedLocation}</span></>}
      </div>

      {data?.ranking.warning && <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">{data.ranking.warning}</div>}

      {overview.isLoading ? <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{Array.from({ length: 6 }).map((_, index) => <div key={index} className="h-64 animate-pulse rounded-2xl bg-gray-100" />)}</div>
      : overview.isError ? <div className="rounded-2xl border border-red-100 bg-red-50 p-6 text-sm text-red-700">Unable to load geographic analytics. Please retry.</div>
      : detailOpen ? <DealReport rows={details.data?.data.rows ?? []} loading={details.isLoading} search={search} onSearch={(value) => { setSearch(value); setPage(1); }} sortBy={sortBy} onSortBy={(value) => { setSortBy(value); setPage(1); }} sortDir={sortDir} onSortDir={(value) => { setSortDir(value); setPage(1); }} meta={details.data?.meta} page={page} setPage={setPage} />
      : <>
          <section><div className="mb-3"><h2 className="text-base font-bold text-gray-900">{level === 'country' ? 'Country-wise Business' : level === 'state' ? 'State-wise Business' : `City-wise Business — ${state}`}</h2><p className="mt-0.5 text-xs text-gray-500">Select a card to inspect the underlying authorized deals</p></div>
            {data?.locations.length ? <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">{data.locations.map((row) => <LocationCard key={row.name} row={row} level={level} onClick={() => openLocation(row)} />)}</div> : <div className="rounded-2xl border border-dashed border-gray-200 bg-white py-14 text-center text-sm text-gray-500">No geographic business data matches these filters.</div>}
          </section>
          {!!data?.locations.length && <section className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm"><h2 className="text-base font-bold text-gray-900">Top Performing Locations</h2><div className="mt-3 space-y-2">{data.locations.slice(0, 5).map((row) => <button key={row.name} onClick={() => openLocation(row)} className="flex min-h-12 w-full items-center gap-3 rounded-xl bg-gray-50 px-3 text-left hover:bg-indigo-50"><span className={`flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold ${row.rank === 1 ? 'bg-[#FFF7DF] text-[#D4A017]' : row.rank === 2 ? 'bg-slate-100 text-slate-500' : row.rank === 3 ? 'bg-[#FFF0E5] text-[#C47A44]' : 'bg-white text-gray-500'}`}>#{row.rank}</span><span className="min-w-0 flex-1 truncate text-sm font-semibold text-gray-800">{row.name}</span><span className="text-xs text-gray-500">{row.total_deals} deals · {row.won_deals} won</span></button>)}</div></section>}
        </>}

      {data && <details className="rounded-xl border border-gray-100 bg-white px-4 py-3 text-xs text-gray-500"><summary className="cursor-pointer font-semibold text-gray-700">Data attribution and currency policy</summary><p className="mt-2">{data.meta.location_attribution}</p><p className="mt-1">{data.meta.currency_policy}</p></details>}
    </div>
  );
}

function DealReport({ rows, loading, search, onSearch, sortBy, onSortBy, sortDir, onSortDir, meta, page, setPage }: {
  rows: GeographicDealRow[]; loading: boolean; search: string; onSearch: (value: string) => void;
  sortBy: 'date' | 'amount'; onSortBy: (value: 'date' | 'amount') => void; sortDir: 'asc' | 'desc'; onSortDir: (value: 'asc' | 'desc') => void;
  meta?: { total: number; current_page: number; last_page: number; per_page?: number }; page: number; setPage: (page: number) => void;
}) {
  return <section className="space-y-3"><div className="flex flex-col gap-2 sm:flex-row"><input type="search" value={search} onChange={(event) => onSearch(event.target.value)} placeholder="Search deal or client…" className="min-h-10 flex-1 rounded-xl border border-gray-200 px-3 text-sm" /><select value={sortBy} onChange={(event) => onSortBy(event.target.value as 'date' | 'amount')} className="min-h-10 rounded-xl border border-gray-200 px-3 text-sm"><option value="date">Sort by date</option><option value="amount">Sort by amount</option></select><select value={sortDir} onChange={(event) => onSortDir(event.target.value as 'asc' | 'desc')} className="min-h-10 rounded-xl border border-gray-200 px-3 text-sm"><option value="desc">Highest / newest</option><option value="asc">Lowest / oldest</option></select></div>
    <div className="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">{loading ? <div className="h-64 animate-pulse bg-gray-50" /> : !rows.length ? <div className="py-16 text-center text-sm text-gray-500">No deals match this location and filter set.</div> : <><div className="divide-y divide-gray-100 md:hidden">{rows.map((row) => <DealCard key={row.id} row={row} />)}</div><div className="hidden overflow-x-auto md:block"><table className="min-w-[1150px] w-full"><thead className="bg-gray-50"><tr>{['Deal', 'Client', 'Location', 'Employee', 'Value', 'Collected', 'Outstanding', 'Status', ''].map((heading) => <th key={heading} className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500">{heading}</th>)}</tr></thead><tbody className="divide-y divide-gray-100">{rows.map((row) => <tr key={row.id}><td className="px-4 py-3"><p className="text-sm font-semibold text-gray-900">{row.title}</p><p className="text-xs text-gray-400">{row.reference}</p></td><td className="px-4 py-3 text-sm text-gray-700">{row.client ?? '—'}<p className="text-xs text-gray-400">{row.contact_email ?? row.contact_phone}</p></td><td className="px-4 py-3 text-sm text-gray-700">{[row.city, row.state, row.country].filter(Boolean).join(', ')}</td><td className="px-4 py-3 text-sm text-gray-700">{row.assigned_employee ?? '—'}</td><td className="px-4 py-3 text-sm font-semibold">{moneyText([{ currency: row.currency, amount: row.value }])}</td><td className="px-4 py-3 text-sm font-semibold text-teal-700">{moneyText([{ currency: row.currency, amount: row.collected }])}</td><td className="px-4 py-3 text-sm font-semibold text-amber-700">{moneyText([{ currency: row.currency, amount: row.outstanding }])}</td><td className="px-4 py-3"><Badge value={row.status} /></td><td className="px-4 py-3"><Link href={`/deals?search=${encodeURIComponent(row.title)}`} className="text-xs font-semibold text-indigo-600 hover:underline">View Deal →</Link></td></tr>)}</tbody></table></div></>}</div>
    {meta && meta.last_page > 1 && <div className="flex items-center justify-between gap-3"><span className="text-xs text-gray-500">{meta.total} deals</span><div className="flex gap-2"><button onClick={() => setPage(Math.max(1, page - 1))} disabled={page === 1} className="min-h-10 rounded-xl border border-gray-200 px-3 text-xs disabled:opacity-40">← Previous</button><button onClick={() => setPage(Math.min(meta.last_page, page + 1))} disabled={page === meta.last_page} className="min-h-10 rounded-xl border border-gray-200 px-3 text-xs disabled:opacity-40">Next →</button></div></div>}
  </section>;
}

function DealCard({ row }: { row: GeographicDealRow }) {
  return <article className="space-y-3 p-4"><div className="flex items-start justify-between gap-3"><div><h3 className="text-sm font-semibold text-gray-900">{row.title}</h3><p className="text-xs text-gray-400">{row.reference} · {row.client ?? 'No client'}</p></div><Badge value={row.status} /></div><div className="grid grid-cols-2 gap-3 text-xs"><div><p className="text-gray-400">Value</p><strong>{moneyText([{ currency: row.currency, amount: row.value }])}</strong></div><div><p className="text-gray-400">Collected</p><strong className="text-teal-700">{moneyText([{ currency: row.currency, amount: row.collected }])}</strong></div><div><p className="text-gray-400">Outstanding</p><strong className="text-amber-700">{moneyText([{ currency: row.currency, amount: row.outstanding }])}</strong></div><div><p className="text-gray-400">Employee</p><strong>{row.assigned_employee ?? '—'}</strong></div></div><p className="text-xs text-gray-500">{[row.city, row.state, row.country].filter(Boolean).join(', ')}</p><Link href={`/deals?search=${encodeURIComponent(row.title)}`} className="flex min-h-10 items-center justify-center rounded-xl border border-indigo-200 text-xs font-semibold text-indigo-600">View Deal</Link></article>;
}
