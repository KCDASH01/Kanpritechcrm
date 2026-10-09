'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import { motion } from 'framer-motion';
import {
  LineChart, Line, AreaChart, Area, BarChart, Bar,
  XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
} from 'recharts';
import client from '@/lib/api/client';
import type { ApiResponse, DashboardData, DashboardPerformance, DashboardPerformanceDetail } from '@/types';
import { useAuthStore } from '@/store/authStore';
import { SkeletonCard, SkeletonTable } from '@/components/ui/Skeleton';
import { Badge } from '@/components/ui/Badge';
import { RemindersCard } from '@/components/dashboard/RemindersCard';
import { PerformanceLeaderboard } from '@/components/dashboard/PerformanceLeaderboard';

const container = { hidden: {}, show: { transition: { staggerChildren: 0.08 } } };
const cardItem  = { hidden: { opacity: 0, y: 16 }, show: { opacity: 1, y: 0, transition: { type: 'spring' as const, damping: 24, stiffness: 300 } } };

const AVATAR_GRADIENTS = [
  'from-indigo-400 to-violet-500', 'from-emerald-400 to-teal-500',
  'from-blue-400 to-sky-500',      'from-pink-400 to-rose-500',
  'from-amber-400 to-orange-500',  'from-purple-400 to-fuchsia-500',
];

const QUICK_ACTIONS = [
  { label: '+ Add Lead',  href: '/leads',      color: 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100 hover:shadow-indigo-100' },
  { label: '+ Add Deal',  href: '/deals',      color: 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 hover:shadow-emerald-100' },
  { label: '+ Activity',  href: '/activities', color: 'bg-blue-50 text-blue-700 hover:bg-blue-100 hover:shadow-blue-100' },
  { label: '→ Pipeline',  href: '/pipelines',  color: 'bg-violet-50 text-violet-700 hover:bg-violet-100 hover:shadow-violet-100' },
];

function getGreeting() {
  const h = new Date().getHours();
  return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening';
}
function getDate() {
  return new Intl.DateTimeFormat('en-IN', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date());
}

/** Fill missing dates with 0 so charts have a continuous x-axis */
function fillDates(data: { date: string; count: number }[], days = 30) {
  const map = Object.fromEntries(data.map((d) => [d.date, d.count]));
  return Array.from({ length: days }, (_, i) => {
    const d = new Date();
    d.setDate(d.getDate() - (days - 1 - i));
    const key = d.toISOString().slice(0, 10);
    return { date: key.slice(5), count: map[key] ?? 0 }; // "MM-DD" for axis labels
  });
}

function fillRevenue(data: { date: string; revenue: number }[], days = 30) {
  const map = Object.fromEntries(data.map((d) => [d.date, d.revenue]));
  return Array.from({ length: days }, (_, i) => {
    const d = new Date();
    d.setDate(d.getDate() - (days - 1 - i));
    const key = d.toISOString().slice(0, 10);
    return { date: key.slice(5), revenue: map[key] ?? 0 };
  });
}

function fmtCurrency(n: number) {
  if (n >= 100_000) return `₹${(n / 100_000).toFixed(1)}L`;
  if (n >= 1_000)   return `₹${(n / 1_000).toFixed(1)}K`;
  return `₹${n.toFixed(0)}`;
}

type DetailType = DashboardPerformanceDetail['type'];

function progressTone(pct: number | null) {
  if (pct === null || pct < 50) return { fill: 'bg-red-500', text: 'text-red-700', badge: 'bg-red-50' };
  if (pct <= 75) return { fill: 'bg-orange-500', text: 'text-orange-700', badge: 'bg-orange-50' };
  if (pct <= 100) return { fill: 'bg-emerald-400', text: 'text-emerald-700', badge: 'bg-emerald-50' };
  return { fill: 'bg-emerald-700', text: 'text-emerald-800', badge: 'bg-emerald-100' };
}

function TargetProgressRow({ label, actual, target, percentage, onClick }: {
  label: string; actual: number; target: number; percentage: number | null; onClick: () => void;
}) {
  const tone = progressTone(percentage);
  const displayPct = percentage === null ? null : Number(percentage.toFixed(2));
  return (
    <button type="button" onClick={onClick} className="w-full text-left rounded-xl p-4 hover:bg-gray-50 transition-colors group">
      <div className="flex items-start justify-between gap-4 mb-2.5">
        <div>
          <p className="text-sm font-semibold text-gray-800 group-hover:text-indigo-700">{label}</p>
          {target > 0 ? (
            <p className="text-sm text-gray-500 mt-0.5">{fmtCurrency(actual)} / {fmtCurrency(target)}</p>
          ) : (
            <p className="text-sm text-gray-400 mt-0.5">No Target Set · Achieved {fmtCurrency(actual)}</p>
          )}
        </div>
        {displayPct !== null ? (
          <span className={`shrink-0 px-2.5 py-1 rounded-full text-xs font-bold ${tone.badge} ${tone.text}`}>
            {displayPct}%{displayPct > 100 ? ' Achieved' : ''}
          </span>
        ) : <span className="text-xs font-semibold text-gray-400">View details →</span>}
      </div>
      <div className="h-[18px] bg-gray-100 rounded-full overflow-hidden ring-1 ring-inset ring-gray-200">
        <div className={`h-full rounded-full transition-all duration-700 ${tone.fill}`} style={{ width: `${Math.min(Math.max(percentage ?? 0, 0), 100)}%` }} />
      </div>
      {displayPct !== null && displayPct > 100 && <div className="h-1 mt-1 rounded-full bg-emerald-700" style={{ width: `${Math.min(displayPct - 100, 100)}%` }} />}
    </button>
  );
}

function PerformanceOverview({ performance, canManage, selectedMember, onSelectMember, onOpen }: {
  performance: DashboardPerformance; canManage: boolean; selectedMember: string;
  onSelectMember: (value: string) => void; onOpen: (type: DetailType) => void;
}) {
  const target = performance.target;
  const revenueCards: { label: string; value: number; type: DetailType; color: string; icon: string }[] = [
    { label: 'Total Revenue Collected', value: performance.revenue.total_collected, type: 'collections_all', color: 'text-indigo-700 bg-indigo-50', icon: '₹' },
    { label: 'Collected This Month', value: performance.revenue.collected_this_month, type: 'collections_month', color: 'text-emerald-700 bg-emerald-50', icon: '✓' },
    { label: 'Receivable', value: performance.revenue.receivable, type: 'receivables', color: 'text-amber-700 bg-amber-50', icon: '↗' },
  ];

  return <motion.section initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
    <div className="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
      <div><h2 className="text-base font-bold text-gray-900">Performance Overview</h2><p className="text-xs text-gray-400 mt-0.5">Target Progress — {performance.period_label}</p></div>
      {canManage && <label className="flex items-center gap-2 text-xs text-gray-500">View:
        <select value={selectedMember} onChange={(e) => onSelectMember(e.target.value)} className="min-w-48 bg-white border border-gray-200 rounded-xl px-3 py-2 text-sm font-medium text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
          <option value="">Overall Team</option>
          {performance.team_members.map((member) => <option key={member.id} value={member.id}>{member.name}</option>)}
        </select>
      </label>}
    </div>
    <div className="grid grid-cols-1 lg:grid-cols-2 gap-2 p-2 border-b border-gray-100">
      <TargetProgressRow label="Sales Target" actual={target.achieved_amount} target={target.target_amount} percentage={target.sales_percentage} onClick={() => onOpen('sales')} />
      <TargetProgressRow label="Collection Target" actual={target.received_amount} target={target.receivable_amount} percentage={target.collection_percentage} onClick={() => onOpen('target_collections')} />
    </div>
    <div className="p-5"><p className="text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-3">Revenue</p><div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
      {revenueCards.map((card) => <button key={card.type} type="button" onClick={() => onOpen(card.type)} className="text-left rounded-2xl border border-gray-100 p-4 hover:border-indigo-200 hover:shadow-md transition-all group">
        <div className={`w-8 h-8 rounded-xl flex items-center justify-center font-bold ${card.color}`}>{card.icon}</div>
        <p className="text-xs text-gray-500 mt-3 group-hover:text-indigo-600">{card.label}</p><p className="text-xl font-bold text-gray-900 mt-1">₹{card.value.toLocaleString('en-IN')}</p><p className="text-[10px] text-gray-400 mt-2">View details →</p>
      </button>)}
    </div></div>
  </motion.section>;
}

function PerformanceDetailsModal({ type, detail, loading, page, onPage, onClose }: { type: DetailType; detail?: DashboardPerformanceDetail; loading: boolean; page: number; onPage: (page: number) => void; onClose: () => void }) {
  const titles: Record<DetailType, string> = { sales: 'Sales Target Details', target_collections: 'Collection Target Details', collections_month: 'Collected This Month', collections_all: 'Full Collection History', receivables: 'Receivables' };
  const columns = type === 'sales'
    ? [['deal','Deal'],['client','Client'],['lead','Lead'],['service','Service'],['employee','Employee'],['deal_value','Deal Value'],['collected','Collected'],['outstanding','Outstanding'],['date','Won Date'],['business_type','Business Type'],['market_type','Market'],['_actions','Actions']]
    : type === 'receivables'
      ? [['client','Client'],['deal','Deal'],['service','Service'],['employee','Employee'],['deal_value','Deal Value'],['collected','Collected'],['receivable','Receivable'],['due_date','Due Date'],['status','Status'],['_actions','Actions']]
      : [['date','Date'],['client','Client'],['deal','Deal'],['lead','Lead'],['service','Service'],['employee','Employee'],['amount','Payment Amount'],['payment_method','Method'],['reference','Reference'],['deal_value','Deal Value'],['total_collected','Total Collected'],['outstanding','Outstanding'],['status','Deal Status'],['_actions','Actions']];
  const moneyFields = new Set(['deal_value','collected','total_collected','outstanding','receivable','amount']);
  return <div className="fixed inset-0 z-50 flex items-center justify-center p-4"><div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={onClose} /><div className="relative bg-white rounded-2xl shadow-2xl w-full max-w-7xl max-h-[86vh] flex flex-col overflow-hidden">
    <div className="px-6 py-4 border-b flex items-center justify-between"><div><h3 className="font-bold text-gray-900">{titles[type]}{detail?.period_label ? ` — ${detail.period_label}` : ''}</h3><p className="text-xs text-gray-400">Only records contributing to this value are shown.</p></div><button onClick={onClose} className="w-9 h-9 rounded-xl hover:bg-gray-100 text-gray-500">✕</button></div>
    <div className="overflow-auto flex-1">{loading ? <p className="p-12 text-center text-gray-400">Loading details…</p> : !detail?.rows.length ? <p className="p-12 text-center text-gray-400">No contributing records found.</p> : <table className="min-w-[1100px] w-full text-sm"><thead className="sticky top-0 bg-gray-50"><tr>{columns.map(([key,label]) => <th key={key} className="px-4 py-3 text-left text-[11px] uppercase tracking-wide text-gray-500">{label}</th>)}</tr></thead><tbody className="divide-y divide-gray-100">{detail.rows.map((row, i) => <tr key={String(row.id ?? row.deal_id ?? i)} className="hover:bg-gray-50">{columns.map(([key]) => <td key={key} className="px-4 py-3 text-gray-700 whitespace-nowrap">{key === '_actions' ? <a href={`/deals?search=${encodeURIComponent(String(row.deal ?? ''))}`} className="text-indigo-600 font-medium hover:underline">View Deal</a> : moneyFields.has(key) && typeof row[key] === 'number' ? `₹${Number(row[key]).toLocaleString('en-IN')}` : String(row[key] ?? '—').replaceAll('_',' ')}</td>)}</tr>)}</tbody></table>}</div>
    {detail?.meta && detail.meta.last_page > 1 && <div className="px-6 py-3 border-t flex items-center justify-between text-xs text-gray-500"><span>{detail.meta.total} records</span><div className="flex items-center gap-2"><button disabled={page <= 1 || loading} onClick={() => onPage(page - 1)} className="px-3 py-1.5 border rounded-lg disabled:opacity-40">Previous</button><span>Page {page} of {detail.meta.last_page}</span><button disabled={page >= detail.meta.last_page || loading} onClick={() => onPage(page + 1)} className="px-3 py-1.5 border rounded-lg disabled:opacity-40">Next</button></div></div>}
  </div></div>;
}

export default function DashboardPage() {
  const router = useRouter();
  const { user, isOwner, isAdmin, isBusinessPlan, isEnterprisePlan } = useAuthStore();
  const [selectedMember, setSelectedMember] = useState('');
  const [detailType, setDetailType] = useState<DetailType | null>(null);
  const [detailPage, setDetailPage] = useState(1);

  const canManage      = isOwner() || isAdmin();
  const planLabel      = isEnterprisePlan() ? 'Enterprise Plan' : isBusinessPlan() ? 'Business Plan' : 'Free Plan';
  const planClass      = isEnterprisePlan() ? 'text-violet-600 font-medium' : isBusinessPlan() ? 'text-indigo-600 font-medium' : 'text-gray-500';
  // Employees are always forced to their own ID by the API. Managers may select a team member.
  const assignedFilter = canManage ? (selectedMember ? Number(selectedMember) : undefined) : user?.id;

  const dashboardQueryKey = ['dashboard', assignedFilter];

  const { data, isLoading } = useQuery({
    queryKey: dashboardQueryKey,
    queryFn: () =>
      client
        .get<ApiResponse<DashboardData>>('/dashboard', {
          params: assignedFilter ? { assigned_to: assignedFilter } : {},
        })
        .then((r) => r.data.data),
  });

  const { data: detail, isLoading: detailLoading } = useQuery({
    queryKey: ['dashboard-performance-details', detailType, assignedFilter, data?.performance?.period_start, detailPage],
    enabled: detailType !== null && !!data?.performance,
    queryFn: () => client.get<ApiResponse<DashboardPerformanceDetail>>('/dashboard/performance-details', { params: {
      type: detailType,
      assigned_to: assignedFilter,
      month: data!.performance.period_start.slice(0, 7),
      page: detailPage,
    } }).then((r) => r.data.data),
  });

  const stats = data?.stats;

  const STAT_CARDS = [
    {
      label:    canManage ? 'Total Leads'  : 'My Leads',
      value:    stats?.total_leads ?? 0,
      sub:      `${stats?.new_leads ?? 0} new`,
      gradient: 'from-indigo-500 to-violet-600',
      shadow:   'shadow-indigo-500/25',
      icon:     'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
    },
    {
      label:    canManage ? 'Open Deals'   : 'My Open Deals',
      value:    stats?.open_deals ?? 0,
      sub:      `₹${Number(stats?.deal_value ?? 0).toLocaleString()} value`,
      gradient: 'from-emerald-500 to-teal-600',
      shadow:   'shadow-emerald-500/25',
      icon:     'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    },
    {
      label:    canManage ? 'Deals Won'    : 'My Deals Won',
      value:    stats?.won_deals ?? 0,
      sub:      'total closed',
      gradient: 'from-blue-500 to-sky-600',
      shadow:   'shadow-blue-500/25',
      icon:     'M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z',
    },
    {
      label:    'Total Revenue',
      value:    `₹${Number(stats?.total_revenue ?? 0).toLocaleString('en-IN')}`,
      sub:      `One-time ₹${Number(stats?.one_time_revenue ?? 0).toLocaleString('en-IN')} · Recurring ₹${Number(stats?.recurring_revenue ?? 0).toLocaleString('en-IN')}`,
      gradient: 'from-violet-500 to-purple-600',
      shadow:   'shadow-violet-500/25',
      icon:     'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V5m0 11v3',
    },
    {
      label:    canManage ? 'Due Today'    : 'My Due Today',
      value:    stats?.due_today_activities ?? 0,
      sub:      'follow-ups & meetings',
      gradient: 'from-fuchsia-500 to-pink-600',
      shadow:   'shadow-fuchsia-500/25',
      icon:     'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
      href:     '/follow-ups?due=today',
    },
    {
      label:    canManage ? 'Overdue'      : 'My Overdue',
      value:    stats?.overdue_activities ?? 0,
      sub:      'need attention',
      gradient: 'from-amber-500 to-orange-500',
      shadow:   'shadow-amber-500/25',
      icon:     'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
      href:     '/follow-ups?due=overdue',
    },
  ];

  if (isLoading) {
    return (
      <div className="space-y-6 animate-fade-in">
        <div className="space-y-1.5">
          <div className="skeleton h-8 w-64 rounded-xl" />
          <div className="skeleton h-4 w-40 rounded-lg" />
        </div>
        <div className="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3">
          {[...Array(5)].map((_, i) => <SkeletonCard key={i} />)}
        </div>
        {/* Reminders skeleton */}
        <div className="skeleton h-28 rounded-2xl" />
        {/* Charts skeleton */}
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
          {[0, 1, 2].map((i) => <div key={i} className="skeleton h-52 rounded-2xl" />)}
        </div>
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          {[0, 1].map((i) => (
            <div key={i} className="bg-white rounded-2xl border border-gray-100 overflow-hidden">
              <div className="px-5 py-4 border-b border-gray-50"><div className="skeleton h-5 w-32 rounded-lg" /></div>
              <SkeletonTable rows={4} cols={3} />
            </div>
          ))}
        </div>
      </div>
    );
  }

  const leadsTrend    = fillDates(data?.charts?.leads_trend   ?? []);
  const revenueTrend  = fillRevenue(data?.charts?.revenue_trend ?? []);
  const dealsByStage  = (data?.charts?.deals_by_stage ?? []).map((d) => ({
    name:  d.stage?.name ?? `Stage ${d.stage_id}`,
    count: d.count,
    value: d.total_value,
    color: d.stage?.color ?? '#6366f1',
  }));

  return (
    <div className="space-y-6">
      {/* Greeting */}
      <motion.div initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
        <h1 className="text-2xl font-bold text-gray-900">
          {getGreeting()}, {user?.name?.split(' ')[0]} 👋
        </h1>
        <p className="text-sm text-gray-400 mt-0.5">
          {getDate()} ·{' '}
          <span className={planClass}>{planLabel}</span>
          {!canManage && (
            <span className="ml-2 px-1.5 py-0.5 bg-blue-100 text-blue-700 text-[10px] font-semibold rounded-md uppercase tracking-wide">
              My Data
            </span>
          )}
        </p>
      </motion.div>

      {/* Stat cards */}
      <motion.div className="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3" variants={container} initial="hidden" animate="show">
        {STAT_CARDS.map((card) => {
          return (
            <motion.div key={card.label} variants={cardItem}>
              <div
                role={card.href ? 'link' : undefined}
                tabIndex={card.href ? 0 : undefined}
                onClick={() => card.href && router.push(card.href)}
                onKeyDown={(e) => card.href && (e.key === 'Enter' || e.key === ' ') && router.push(card.href!)}
                className={`relative bg-gradient-to-br ${card.gradient} rounded-2xl p-4 overflow-hidden shadow-lg ${card.shadow} text-white
                  ${card.href ? 'cursor-pointer hover:brightness-105 active:scale-[0.98] transition-all' : ''}`}
              >
                <div className="absolute -right-4 -top-4 w-20 h-20 bg-white/10 rounded-full" />
                <div className="absolute -right-1 top-1 w-11 h-11 bg-white/10 rounded-full flex items-center justify-center">
                  <svg className="w-5 h-5 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d={card.icon} />
                  </svg>
                </div>
                <p className="text-white/70 text-[10px] font-semibold uppercase tracking-wider mb-1.5 pr-8">{card.label}</p>
                <p className="text-2xl font-bold leading-none">{card.value.toLocaleString()}</p>
                <p className="text-white/60 text-[11px] mt-1">{card.sub}</p>
              </div>
            </motion.div>
          );
        })}
      </motion.div>

      {data?.performance && <PerformanceOverview
        performance={data.performance}
        canManage={canManage}
        selectedMember={selectedMember}
        onSelectMember={setSelectedMember}
        onOpen={(type) => { setDetailPage(1); setDetailType(type); }}
      />}

      <PerformanceLeaderboard canManage={canManage} currentUserId={user?.id} />

      {/* Quick actions */}
      <motion.div className="flex gap-2.5 flex-wrap" initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: 0.35 }}>
        {QUICK_ACTIONS.map((action) => (
          <button
            key={action.href}
            onClick={() => router.push(action.href)}
            className={`text-sm font-semibold px-4 py-2 rounded-xl transition-all duration-150
                        hover:-translate-y-0.5 hover:shadow-md active:scale-95 ${action.color}`}
          >
            {action.label}
          </button>
        ))}
      </motion.div>

      {detailType && <PerformanceDetailsModal type={detailType} detail={detail} loading={detailLoading} page={detailPage} onPage={setDetailPage} onClose={() => setDetailType(null)} />}

      {/* ⏰ Reminders — highlighted, full width */}
      <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.42, duration: 0.35 }}>
        <RemindersCard
          reminders={data?.reminders ?? []}
          queryKey={dashboardQueryKey}
          canManage={canManage}
        />
      </motion.div>

      {/* Analytics Charts */}
      <motion.div
        className="grid grid-cols-1 lg:grid-cols-3 gap-4"
        initial={{ opacity: 0, y: 12 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0.5, duration: 0.4 }}
      >
        {/* Leads Over Time */}
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div className="mb-4">
            <h3 className="font-semibold text-gray-900 text-sm">
              {canManage ? 'Leads Over Time' : 'My Leads — 30 Days'}
            </h3>
            <p className="text-[11px] text-gray-400 mt-0.5">New leads created per day</p>
          </div>
          <ResponsiveContainer width="100%" height={160}>
            <LineChart data={leadsTrend} margin={{ top: 4, right: 4, left: -28, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
              <XAxis
                dataKey="date"
                tick={{ fontSize: 9, fill: '#94a3b8' }}
                tickLine={false}
                axisLine={false}
                interval={6}
              />
              <YAxis tick={{ fontSize: 9, fill: '#94a3b8' }} tickLine={false} axisLine={false} allowDecimals={false} />
              <Tooltip
                contentStyle={{ borderRadius: 10, border: '1px solid #e2e8f0', fontSize: 11 }}
                labelStyle={{ color: '#64748b' }}
              />
              <Line
                type="monotone"
                dataKey="count"
                stroke="#6366f1"
                strokeWidth={2}
                dot={false}
                activeDot={{ r: 4, fill: '#6366f1' }}
                name="Leads"
              />
            </LineChart>
          </ResponsiveContainer>
        </div>

        {/* Deals by Stage */}
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div className="mb-4">
            <h3 className="font-semibold text-gray-900 text-sm">
              {canManage ? 'Deals Pipeline' : 'My Pipeline'}
            </h3>
            <p className="text-[11px] text-gray-400 mt-0.5">Open deals by stage</p>
          </div>
          {dealsByStage.length === 0 ? (
            <div className="h-[160px] flex items-center justify-center">
              <p className="text-xs text-gray-400">No open deals yet</p>
            </div>
          ) : (
            <ResponsiveContainer width="100%" height={160}>
              <BarChart data={dealsByStage} margin={{ top: 4, right: 4, left: -28, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
                <XAxis
                  dataKey="name"
                  tick={{ fontSize: 9, fill: '#94a3b8' }}
                  tickLine={false}
                  axisLine={false}
                />
                <YAxis tick={{ fontSize: 9, fill: '#94a3b8' }} tickLine={false} axisLine={false} allowDecimals={false} />
                <Tooltip
                  contentStyle={{ borderRadius: 10, border: '1px solid #e2e8f0', fontSize: 11 }}
                  formatter={(val, name) =>
                    name === 'value' ? [fmtCurrency(Number(val)), 'Value'] : [val, 'Deals']
                  }
                />
                <Bar dataKey="count" fill="#10b981" radius={[4, 4, 0, 0]} name="Deals" />
              </BarChart>
            </ResponsiveContainer>
          )}
        </div>

        {/* Revenue Trend */}
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div className="mb-4">
            <h3 className="font-semibold text-gray-900 text-sm">
              {canManage ? 'Revenue Trend' : 'My Revenue'}
            </h3>
            <p className="text-[11px] text-gray-400 mt-0.5">One-time closed-won value plus collected recurring payments</p>
          </div>
          <ResponsiveContainer width="100%" height={160}>
            <AreaChart data={revenueTrend} margin={{ top: 4, right: 4, left: -28, bottom: 0 }}>
              <defs>
                <linearGradient id="revenueGrad" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%"  stopColor="#8b5cf6" stopOpacity={0.25} />
                  <stop offset="95%" stopColor="#8b5cf6" stopOpacity={0} />
                </linearGradient>
              </defs>
              <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
              <XAxis
                dataKey="date"
                tick={{ fontSize: 9, fill: '#94a3b8' }}
                tickLine={false}
                axisLine={false}
                interval={6}
              />
              <YAxis
                tick={{ fontSize: 9, fill: '#94a3b8' }}
                tickLine={false}
                axisLine={false}
                tickFormatter={fmtCurrency}
              />
              <Tooltip
                contentStyle={{ borderRadius: 10, border: '1px solid #e2e8f0', fontSize: 11 }}
                formatter={(val) => [fmtCurrency(Number(val)), 'Revenue']}
              />
              <Area
                type="monotone"
                dataKey="revenue"
                stroke="#8b5cf6"
                strokeWidth={2}
                fill="url(#revenueGrad)"
                dot={false}
                activeDot={{ r: 4, fill: '#8b5cf6' }}
              />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      </motion.div>

      {/* Recent Leads + Recent Deals */}
      <motion.div
        className="grid grid-cols-1 lg:grid-cols-2 gap-6"
        initial={{ opacity: 0, y: 12 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0.56, duration: 0.4 }}
      >
        {/* Recent Leads */}
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
          <div className="px-5 py-4 border-b border-gray-50 flex items-center justify-between">
            <h2 className="font-semibold text-gray-900 text-sm">
              {canManage ? 'Recent Leads' : 'My Recent Leads'}
            </h2>
            <a href="/leads" className="text-xs text-indigo-600 hover:text-indigo-700 font-medium transition-colors">View all →</a>
          </div>
          <div className="divide-y divide-gray-50">
            {data?.recent_leads?.length ? data.recent_leads.map((lead, i) => (
              <div key={lead.id} className="px-5 py-3.5 flex items-center gap-3 hover:bg-gray-50/60 transition-colors cursor-default">
                <div className={`w-9 h-9 rounded-full bg-gradient-to-br ${AVATAR_GRADIENTS[i % AVATAR_GRADIENTS.length]}
                                 flex items-center justify-center shrink-0 shadow-sm`}>
                  <span className="text-white font-bold text-xs">{lead.full_name[0]?.toUpperCase()}</span>
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-semibold text-gray-900 truncate">{lead.full_name}</p>
                  <p className="text-xs text-gray-400 truncate">{lead.company ?? lead.email ?? '—'}</p>
                </div>
                <Badge value={lead.status} />
              </div>
            )) : (
              <div className="px-5 py-10 text-center">
                <div className="w-10 h-10 bg-indigo-50 rounded-full flex items-center justify-center mx-auto mb-2">
                  <svg className="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                  </svg>
                </div>
                <p className="text-sm text-gray-400">No leads yet</p>
                <button onClick={() => router.push('/leads')} className="mt-1 text-xs text-indigo-600 hover:underline font-medium">Add your first lead →</button>
              </div>
            )}
          </div>
        </div>

        {/* Recent Deals */}
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
          <div className="px-5 py-4 border-b border-gray-50 flex items-center justify-between">
            <h2 className="font-semibold text-gray-900 text-sm">
              {canManage ? 'Recent Deals' : 'My Recent Deals'}
            </h2>
            <a href="/deals" className="text-xs text-indigo-600 hover:text-indigo-700 font-medium transition-colors">View all →</a>
          </div>
          <div className="divide-y divide-gray-50">
            {data?.recent_deals?.length ? data.recent_deals.map((deal, i) => (
              <div key={deal.id} className="px-5 py-3.5 flex items-center gap-3 hover:bg-gray-50/60 transition-colors cursor-default">
                <div className={`w-9 h-9 rounded-full bg-gradient-to-br ${AVATAR_GRADIENTS[(i + 2) % AVATAR_GRADIENTS.length]}
                                 flex items-center justify-center shrink-0 shadow-sm`}>
                  <span className="text-white font-bold text-xs">₹</span>
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-semibold text-gray-900 truncate">{deal.title}</p>
                  <p className="text-xs text-gray-400 truncate">
                    {deal.stage?.name ?? '—'}
                    {deal.value ? ` · ₹${Number(deal.value).toLocaleString()}` : ''}
                  </p>
                </div>
                <Badge value={deal.status} />
              </div>
            )) : (
              <div className="px-5 py-10 text-center">
                <div className="w-10 h-10 bg-emerald-50 rounded-full flex items-center justify-center mx-auto mb-2">
                  <svg className="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                  </svg>
                </div>
                <p className="text-sm text-gray-400">No deals yet</p>
                <button onClick={() => router.push('/deals')} className="mt-1 text-xs text-indigo-600 hover:underline font-medium">Create your first deal →</button>
              </div>
            )}
          </div>
        </div>
      </motion.div>
    </div>
  );
}
