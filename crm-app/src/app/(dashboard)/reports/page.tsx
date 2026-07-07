'use client';

import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { motion } from 'framer-motion';
import {
  PieChart, Pie, Cell, Tooltip, ResponsiveContainer,
  BarChart, Bar, XAxis, YAxis, CartesianGrid,
} from 'recharts';
import { reportsApi } from '@/lib/api/reports';
import { salesTargetsApi } from '@/lib/api/salesTargets';
import { useAuthStore } from '@/store/authStore';
import { SkeletonCard } from '@/components/ui/Skeleton';
import { AccessDenied } from '@/components/ui/AccessDenied';
import type { SalesTargetRow } from '@/types';

const container = { hidden: {}, show: { transition: { staggerChildren: 0.1 } } };
const item      = { hidden: { opacity: 0, y: 16 }, show: { opacity: 1, y: 0, transition: { type: 'spring' as const, damping: 24, stiffness: 300 } } };

const PIE_COLORS = ['#6366f1','#10b981','#3b82f6','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#ec4899'];

const STATUS_COLORS: Record<string, { bar: string; label: string }> = {
  new:         { bar: '#6366f1', label: 'New'         },
  contacted:   { bar: '#3b82f6', label: 'Contacted'   },
  qualified:   { bar: '#10b981', label: 'Qualified'   },
  converted:   { bar: '#22c55e', label: 'Converted'   },
  unqualified: { bar: '#94a3b8', label: 'Unqualified' },
  lost:        { bar: '#ef4444', label: 'Lost'        },
};

const MEDALS = ['🥇', '🥈', '🥉'];

function fmtCurrency(n: number) {
  if (n >= 100_000) return `₹${(n / 100_000).toFixed(1)}L`;
  if (n >= 1_000)   return `₹${(n / 1_000).toFixed(1)}K`;
  return `₹${n.toFixed(0)}`;
}

function FunnelBar({ status, count, max }: { status: string; count: number; max: number }) {
  const cfg  = STATUS_COLORS[status] ?? { bar: '#94a3b8', label: status };
  const pct  = max > 0 ? (count / max) * 100 : 0;
  return (
    <div className="flex items-center gap-3">
      <div className="w-24 text-right text-xs font-semibold text-gray-600 capitalize">{cfg.label}</div>
      <div className="flex-1 bg-gray-100 rounded-full h-6 relative overflow-hidden">
        <motion.div
          className="h-full rounded-full flex items-center px-2"
          style={{ backgroundColor: cfg.bar, width: `${Math.max(pct, 2)}%` }}
          initial={{ width: 0 }}
          animate={{ width: `${Math.max(pct, 2)}%` }}
          transition={{ duration: 0.6, ease: 'easeOut' }}
        />
        <span className="absolute right-2 top-1/2 -translate-y-1/2 text-xs font-bold text-gray-700">
          {count.toLocaleString()}
        </span>
      </div>
    </div>
  );
}

// ── Goal Attainment helpers ───────────────────────────────────────────────────

function pctColor(pct: number) {
  if (pct >= 100) return 'text-emerald-600 bg-emerald-50';
  if (pct >= 50)  return 'text-amber-600   bg-amber-50';
  return 'text-red-600 bg-red-50';
}

function prevMonth(ym: string) {
  const [y, m] = ym.split('-').map(Number);
  const d = new Date(y, m - 2, 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}
function nextMonth(ym: string) {
  const [y, m] = ym.split('-').map(Number);
  const d = new Date(y, m, 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}
function fmtMonth(ym: string) {
  const [y, m] = ym.split('-').map(Number);
  return new Date(y, m - 1, 1).toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
}

export default function ReportsPage() {
  const { user, isOwner, isAdmin, isPaidPlan } = useAuthStore();

  // Advanced reports requires Business or Enterprise plan
  if (!isPaidPlan()) {
    return (
      <AccessDenied reason="Advanced reports & analytics are available on the Business and Enterprise plans. Upgrade to unlock full reporting." />
    );
  }

  const canManage      = isOwner() || isAdmin();
  const assignedFilter = canManage ? undefined : user?.id;

  // Goal Attainment month state
  const today = new Date();
  const [goalMonth, setGoalMonth] = useState(
    `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`
  );

  const { data, isLoading } = useQuery({
    queryKey: ['reports', assignedFilter],
    queryFn:  () => reportsApi.get({ assigned_to: assignedFilter }),
  });

  // Goal Attainment data — only fetch for managers
  const { data: goalData, isLoading: goalLoading } = useQuery({
    queryKey: ['sales-targets', goalMonth],
    queryFn:  () => salesTargetsApi.list({ month: goalMonth }),
    enabled:  canManage,
  });

  if (isLoading) {
    return (
      <div className="space-y-6">
        <div className="skeleton h-8 w-48 rounded-xl" />
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          {[...Array(4)].map((_, i) => <SkeletonCard key={i} />)}
        </div>
      </div>
    );
  }

  const funnel      = data?.funnel      ?? [];
  const leadSources = data?.lead_sources ?? [];
  const leaderboard = data?.leaderboard  ?? [];
  const lostReasons = data?.lost_reasons ?? [];
  const avgCycle    = data?.avg_cycle    ?? [];

  const funnelMax    = Math.max(...funnel.map((f) => f.count), 1);
  const wonCycle     = avgCycle.find((c) => c.status === 'won');
  const lostCycle    = avgCycle.find((c) => c.status === 'lost');
  const totalLeads   = funnel.reduce((s, f) => s + f.count, 0);

  // Conversion rate: converted / total (non-zero)
  const convertedCount = funnel.find((f) => f.status === 'converted')?.count ?? 0;
  const conversionRate = totalLeads > 0 ? ((convertedCount / totalLeads) * 100).toFixed(1) : '0.0';

  return (
    <div className="space-y-6">

      {/* Header */}
      <motion.div initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.35 }}>
        <h1 className="text-xl font-bold text-gray-900">
          {canManage ? 'Reports & Analytics' : 'My Reports'}
        </h1>
        <p className="text-xs text-gray-400 mt-0.5">
          {canManage ? 'Organisation-wide performance overview' : 'Your personal performance metrics'}
        </p>
      </motion.div>

      {/* Summary stat row */}
      <motion.div className="grid grid-cols-2 lg:grid-cols-4 gap-4" variants={container} initial="hidden" animate="show">
        {[
          { label: 'Total Leads',      value: totalLeads.toLocaleString(),  color: 'text-indigo-600', bg: 'bg-indigo-50'  },
          { label: 'Conversion Rate',  value: `${conversionRate}%`,        color: 'text-emerald-600',bg: 'bg-emerald-50' },
          { label: 'Avg Won Cycle',    value: wonCycle  ? `${wonCycle.avg_days}d`  : '—', color: 'text-blue-600',   bg: 'bg-blue-50'   },
          { label: 'Avg Lost Cycle',   value: lostCycle ? `${lostCycle.avg_days}d` : '—', color: 'text-red-600',    bg: 'bg-red-50'    },
        ].map((s) => (
          <motion.div key={s.label} variants={item}>
            <div className={`${s.bg} rounded-2xl p-4 border border-white`}>
              <p className="text-[11px] font-semibold text-gray-500 uppercase tracking-wide">{s.label}</p>
              <p className={`text-2xl font-bold mt-1 ${s.color}`}>{s.value}</p>
            </div>
          </motion.div>
        ))}
      </motion.div>

      {/* Row 1: Conversion Funnel (full width) */}
      <motion.div
        className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6"
        initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.2 }}
      >
        <h2 className="text-sm font-semibold text-gray-900 mb-4">
          Lead Conversion Funnel
        </h2>
        {funnel.every((f) => f.count === 0) ? (
          <p className="text-sm text-gray-400 text-center py-6">No lead data yet.</p>
        ) : (
          <div className="space-y-2.5">
            {funnel.filter((f) => f.count > 0).map((f) => (
              <FunnelBar key={f.status} status={f.status} count={f.count} max={funnelMax} />
            ))}
          </div>
        )}
      </motion.div>

      {/* Row 2: Lead Source Pie + Lost Reason Bar */}
      <motion.div
        className="grid grid-cols-1 lg:grid-cols-3 gap-6"
        initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.3 }}
      >
        {/* Lead Source Pie */}
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
          <h2 className="text-sm font-semibold text-gray-900 mb-4">Lead Source Breakdown</h2>
          {leadSources.length === 0 ? (
            <div className="py-8 text-center">
              <p className="text-sm text-gray-400">No source data yet.</p>
              <p className="text-xs text-gray-400 mt-1">Set the Source field when creating leads.</p>
            </div>
          ) : (
            <ResponsiveContainer width="100%" height={220}>
              <PieChart>
                <Pie
                  data={leadSources}
                  dataKey="count"
                  nameKey="source"
                  cx="50%" cy="45%"
                  outerRadius={70}
                  label={({ name, percent }) => `${name} ${((percent ?? 0) * 100).toFixed(0)}%`}
                  labelLine={false}
                >
                  {leadSources.map((_, i) => (
                    <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} />
                  ))}
                </Pie>
                <Tooltip
                  contentStyle={{ borderRadius: 10, border: '1px solid #e2e8f0', fontSize: 11 }}
                  formatter={(val) => [val, 'Leads']}
                />
              </PieChart>
            </ResponsiveContainer>
          )}
        </div>

        {/* Lost Reason Bar */}
        <div className="lg:col-span-2 bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
          <h2 className="text-sm font-semibold text-gray-900 mb-1">Lost Reason Analysis</h2>
          <p className="text-[11px] text-gray-400 mb-4">Why deals are lost</p>
          {lostReasons.length === 0 ? (
            <div className="py-8 text-center">
              <p className="text-sm text-gray-400">No lost reason data yet.</p>
              <p className="text-xs text-gray-400 mt-1">Set a lost reason when marking deals as lost.</p>
            </div>
          ) : (
            <ResponsiveContainer width="100%" height={200}>
              <BarChart data={lostReasons} layout="vertical" margin={{ top: 0, right: 16, left: 0, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" horizontal={false} />
                <XAxis type="number" tick={{ fontSize: 10, fill: '#94a3b8' }} tickLine={false} axisLine={false} allowDecimals={false} />
                <YAxis type="category" dataKey="lost_reason" tick={{ fontSize: 10, fill: '#64748b' }} tickLine={false} axisLine={false} width={120} />
                <Tooltip
                  contentStyle={{ borderRadius: 10, border: '1px solid #e2e8f0', fontSize: 11 }}
                  formatter={(val) => [val, 'Deals']}
                />
                <Bar dataKey="count" fill="#ef4444" radius={[0, 4, 4, 0]} name="Deals" />
              </BarChart>
            </ResponsiveContainer>
          )}
        </div>
      </motion.div>

      {/* Row 3: Team Leaderboard + Avg Deal Cycle */}
      <motion.div
        className="grid grid-cols-1 lg:grid-cols-3 gap-6"
        initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.4 }}
      >
        {/* Team Leaderboard */}
        <div className="lg:col-span-2 bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
          <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <div>
              <h2 className="text-sm font-semibold text-gray-900">Team Leaderboard</h2>
              <p className="text-[11px] text-gray-400 mt-0.5">Won deals this month</p>
            </div>
            {!canManage && (
              <span className="text-[10px] bg-gray-100 text-gray-500 px-2 py-1 rounded-lg font-medium">
                Managers only
              </span>
            )}
          </div>

          {!canManage ? (
            <div className="px-6 py-10 text-center">
              <div className="w-10 h-10 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-2">
                <svg className="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
              </div>
              <p className="text-sm text-gray-500">Leaderboard is visible to managers only</p>
            </div>
          ) : leaderboard.length === 0 ? (
            <div className="px-6 py-10 text-center">
              <p className="text-sm text-gray-400">No won deals this month yet.</p>
            </div>
          ) : (
            <div className="divide-y divide-gray-50">
              {leaderboard.map((entry, i) => (
                <div key={entry.user_id} className="px-6 py-3.5 flex items-center gap-4">
                  <div className="w-8 text-center text-lg">
                    {MEDALS[i] ?? <span className="text-sm font-bold text-gray-400">#{i + 1}</span>}
                  </div>
                  <div className="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500
                                   flex items-center justify-center text-white text-xs font-bold shrink-0">
                    {entry.name[0]?.toUpperCase() ?? '?'}
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-semibold text-gray-900 truncate">{entry.name}</p>
                    <p className="text-xs text-gray-400">{entry.deals_won} deal{entry.deals_won !== 1 ? 's' : ''} won</p>
                  </div>
                  <div className="text-right">
                    <p className="text-sm font-bold text-emerald-600">{fmtCurrency(entry.revenue)}</p>
                    <p className="text-[11px] text-gray-400">revenue</p>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Avg Deal Cycle */}
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
          <h2 className="text-sm font-semibold text-gray-900 mb-1">Average Deal Cycle</h2>
          <p className="text-[11px] text-gray-400 mb-5">Days from creation to close</p>

          {avgCycle.length === 0 ? (
            <div className="py-6 text-center">
              <p className="text-sm text-gray-400">Not enough data yet.</p>
              <p className="text-xs text-gray-400 mt-1">Close some deals to see cycle times.</p>
            </div>
          ) : (
            <div className="space-y-4">
              {wonCycle && (
                <div className="bg-emerald-50 rounded-xl p-4 border border-emerald-100">
                  <div className="flex items-center gap-2 mb-1">
                    <span className="text-emerald-600 text-sm font-semibold">Won Deals</span>
                    <span className="text-[10px] bg-emerald-100 text-emerald-600 px-1.5 py-0.5 rounded-full font-medium">
                      {wonCycle.count} closed
                    </span>
                  </div>
                  <p className="text-3xl font-bold text-emerald-700">{wonCycle.avg_days}<span className="text-lg font-normal ml-1">days</span></p>
                  <p className="text-[11px] text-emerald-600 mt-1">average to close</p>
                </div>
              )}
              {lostCycle && (
                <div className="bg-red-50 rounded-xl p-4 border border-red-100">
                  <div className="flex items-center gap-2 mb-1">
                    <span className="text-red-600 text-sm font-semibold">Lost Deals</span>
                    <span className="text-[10px] bg-red-100 text-red-600 px-1.5 py-0.5 rounded-full font-medium">
                      {lostCycle.count} closed
                    </span>
                  </div>
                  <p className="text-3xl font-bold text-red-700">{lostCycle.avg_days}<span className="text-lg font-normal ml-1">days</span></p>
                  <p className="text-[11px] text-red-600 mt-1">average to lose</p>
                </div>
              )}
            </div>
          )}
        </div>
      </motion.div>

      {/* Row 4: Goal Attainment (managers only) */}
      {canManage && (
        <motion.div
          className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden"
          initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.5 }}
        >
          {/* Header */}
          <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <div>
              <h2 className="text-sm font-semibold text-gray-900">Goal Attainment</h2>
              <p className="text-[11px] text-gray-400 mt-0.5">Monthly targets vs actual performance per rep</p>
            </div>

            {/* Month picker */}
            <div className="flex items-center gap-2">
              <button
                onClick={() => setGoalMonth(prevMonth(goalMonth))}
                className="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 transition-all"
              >
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                </svg>
              </button>
              <span className="text-xs font-semibold text-gray-700 min-w-[110px] text-center">
                {fmtMonth(goalMonth)}
              </span>
              <button
                onClick={() => setGoalMonth(nextMonth(goalMonth))}
                className="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 transition-all"
              >
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                </svg>
              </button>
            </div>
          </div>

          {/* Table */}
          {goalLoading ? (
            <div className="px-6 py-8 text-center">
              <div className="skeleton h-4 w-3/4 rounded-xl mx-auto mb-3" />
              <div className="skeleton h-4 w-1/2 rounded-xl mx-auto" />
            </div>
          ) : !goalData || goalData.length === 0 ? (
            <div className="px-6 py-10 text-center">
              <div className="text-3xl mb-2">🎯</div>
              <p className="text-sm text-gray-500 font-medium">No targets set for {fmtMonth(goalMonth)}</p>
              <p className="text-xs text-gray-400 mt-1">
                Set targets for your team via the <span className="text-indigo-600 font-semibold">Targets</span> page
              </p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="bg-gray-50 border-b border-gray-100">
                    <th className="text-left px-6 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Rep</th>
                    {['Sales Target', 'Collections'].map(label => (
                      <th key={label} colSpan={3} className="text-center px-3 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide border-l border-gray-100">
                        {label}
                      </th>
                    ))}
                  </tr>
                  <tr className="bg-gray-50/50 border-b border-gray-100">
                    <th className="px-6 py-2" />
                    {['sales', 'collections'].flatMap(k => [
                      <th key={`${k}-t`} className="text-center px-2 py-2 text-[10px] font-medium text-gray-400 border-l border-gray-100">Target</th>,
                      <th key={`${k}-a`} className="text-center px-2 py-2 text-[10px] font-medium text-gray-400">Actual</th>,
                      <th key={`${k}-p`} className="text-center px-2 py-2 text-[10px] font-medium text-gray-400">%</th>,
                    ])}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {(goalData as SalesTargetRow[]).map((row) => {
                    const salesPct = row.target_amount > 0 ? Math.round((row.achieved_amount / row.target_amount) * 100) : 0;
                    const collPct  = row.receivable_amount > 0 && row.received_amount != null
                      ? Math.round(((row.received_amount ?? 0) / row.receivable_amount) * 100)
                      : null;
                    return (
                      <tr key={row.user?.id ?? row.id} className="hover:bg-gray-50/50 transition-colors">
                        {/* Rep name */}
                        <td className="px-6 py-3.5">
                          <div className="flex items-center gap-2.5">
                            <div className="w-7 h-7 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 flex items-center justify-center text-white text-xs font-bold shrink-0">
                              {row.user?.name[0]?.toUpperCase() ?? '?'}
                            </div>
                            <span className="font-medium text-gray-800 text-sm">{row.user?.name ?? '—'}</span>
                          </div>
                        </td>

                        {/* Sales Target columns */}
                        <td className="text-center px-2 py-3.5 text-gray-500 text-xs border-l border-gray-100">{fmtCurrency(row.target_amount)}</td>
                        <td className="text-center px-2 py-3.5 text-gray-700 text-xs font-semibold">{fmtCurrency(row.achieved_amount)}</td>
                        <td className="text-center px-2 py-3.5">
                          <span className={`text-[11px] font-bold px-2 py-0.5 rounded-full ${pctColor(salesPct)}`}>{salesPct}%</span>
                        </td>

                        {/* Collections columns */}
                        <td className="text-center px-2 py-3.5 text-gray-500 text-xs border-l border-gray-100">{fmtCurrency(row.receivable_amount)}</td>
                        <td className="text-center px-2 py-3.5 text-gray-700 text-xs font-semibold">
                          {row.received_amount != null ? fmtCurrency(row.received_amount) : <span className="text-gray-300">—</span>}
                        </td>
                        <td className="text-center px-2 py-3.5">
                          {collPct != null
                            ? <span className={`text-[11px] font-bold px-2 py-0.5 rounded-full ${pctColor(collPct)}`}>{collPct}%</span>
                            : <span className="text-gray-300 text-xs">—</span>
                          }
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </motion.div>
      )}
    </div>
  );
}
