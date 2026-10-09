'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { motion } from 'framer-motion';
import {
  BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend,
} from 'recharts';
import { salesTargetsApi } from '@/lib/api/salesTargets';
import { employeesApi } from '@/lib/api/employees';
import { useAuthStore } from '@/store/authStore';
import type { User } from '@/types';
import { SkeletonCard } from '@/components/ui/Skeleton';
import { AccessDenied } from '@/components/ui/AccessDenied';
import type { MyTargetProgress, SalesTargetRow } from '@/types';

// ── Helpers ────────────────────────────────────────────────────────────────────

function fmtAmount(n: number) {
  if (n >= 1_00_00_000) return `₹${(n / 1_00_00_000).toFixed(2)}Cr`;
  if (n >= 1_00_000)  return `₹${(n / 1_00_000).toFixed(2)}L`;
  if (n >= 1_000)     return `₹${(n / 1_000).toFixed(1)}K`;
  return `₹${n.toFixed(0)}`;
}

function pctColor(pct: number) {
  if (pct >= 100) return 'text-emerald-600 bg-emerald-50';
  if (pct >= 50)  return 'text-amber-600 bg-amber-50';
  return 'text-red-600 bg-red-50';
}

function progressBarColor(pct: number) {
  if (pct >= 100) return 'bg-emerald-500';
  if (pct >= 50)  return 'bg-amber-500';
  return 'bg-red-500';
}

function fmtMonthLabel(periodStart: string) {
  // periodStart = "YYYY-MM-DD"
  const [y, m] = periodStart.split('-').map(Number);
  return new Date(y, m - 1, 1).toLocaleDateString('en-IN', { month: 'short', year: 'numeric' });
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
function fmtMonthFull(ym: string) {
  const [y, m] = ym.split('-').map(Number);
  return new Date(y, m - 1, 1).toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
}

// ── Progress Card ─────────────────────────────────────────────────────────────

function ProgressCard({
  label, actual, target, sublabel, gradient, icon,
}: {
  label: string; actual: number; target: number; sublabel: string;
  gradient: string; icon: string;
}) {
  const pct = target > 0 ? Math.min(Math.round((actual / target) * 100), 100) : 0;
  const rawPct = target > 0 ? Math.round((actual / target) * 100) : 0;

  return (
    <div className={`relative bg-gradient-to-br ${gradient} rounded-2xl p-6 text-white shadow-lg overflow-hidden`}>
      <div className="absolute -right-6 -top-6 w-28 h-28 bg-white/10 rounded-full" />
      <div className="absolute -right-2 top-2 w-16 h-16 bg-white/10 rounded-full flex items-center justify-center">
        <svg className="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d={icon} />
        </svg>
      </div>
      <p className="text-white/70 text-[11px] font-semibold uppercase tracking-wider mb-2">{label}</p>
      <p className="text-3xl font-bold leading-none">{fmtAmount(actual)}</p>
      <p className="text-white/60 text-xs mt-1.5">of {fmtAmount(target)} target</p>

      {/* Progress bar */}
      <div className="mt-4">
        <div className="flex justify-between text-white/70 text-[10px] mb-1">
          <span>{sublabel}</span>
          <span className="font-bold">{rawPct}%</span>
        </div>
        <div className="h-2 bg-white/20 rounded-full overflow-hidden">
          <div
            className={`h-full rounded-full transition-all duration-700 ${rawPct >= 100 ? 'bg-white' : rawPct >= 50 ? 'bg-white/80' : 'bg-white/50'}`}
            style={{ width: `${pct}%` }}
          />
        </div>
      </div>
    </div>
  );
}

// ── Team member view ──────────────────────────────────────────────────────────

function TeamMemberView() {
  const qc = useQueryClient();

  const { data: history, isLoading } = useQuery({
    queryKey: ['my-targets'],
    queryFn:  () => salesTargetsApi.myProgress(),
  });


  if (isLoading) {
    return (
      <div className="space-y-6">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <SkeletonCard /> <SkeletonCard />
        </div>
        <div className="skeleton h-64 rounded-2xl" />
      </div>
    );
  }

  // Current month = last entry in the array
  const current  = history?.[history.length - 1];
  const hasCurrent = current && (current.target_amount > 0 || current.receivable_amount > 0);

  // Chart data: all 6 months
  const chartData = (history ?? []).map((h) => ({
    month:      fmtMonthLabel(h.period_start),
    Target:     h.target_amount,
    Achieved:   h.achieved_amount,
    'Collection Target': h.receivable_amount,
    Received:   h.received_amount ?? 0,
  }));

  return (
    <div className="space-y-6">

      {/* Current month stat cards */}
      {hasCurrent ? (
        <motion.div
          className="grid grid-cols-1 sm:grid-cols-2 gap-4"
          initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }}
        >
          <ProgressCard
            label="Sales Target"
            actual={current!.achieved_amount}
            target={current!.target_amount}
            sublabel="Won deals this month"
            gradient="from-indigo-500 to-violet-600"
            icon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
          />
          <div className="relative bg-gradient-to-br from-emerald-500 to-teal-600 rounded-2xl p-6 text-white shadow-lg overflow-hidden">
            <div className="absolute -right-6 -top-6 w-28 h-28 bg-white/10 rounded-full" />
            <div className="absolute -right-2 top-2 w-16 h-16 bg-white/10 rounded-full flex items-center justify-center">
              <svg className="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
              </svg>
            </div>
            <p className="text-white/70 text-[11px] font-semibold uppercase tracking-wider mb-2">Collections</p>
            <p className="text-3xl font-bold leading-none">
              {current!.received_amount != null ? fmtAmount(current!.received_amount) : <span className="text-white/50 text-xl">Not entered</span>}
            </p>
            <p className="text-white/60 text-xs mt-1.5">of {fmtAmount(current!.receivable_amount)} target</p>

            {/* Progress bar */}
            {current!.receivable_amount > 0 && current!.received_amount != null && (
              <div className="mt-3">
                <div className="h-2 bg-white/20 rounded-full overflow-hidden">
                  <div
                    className={`h-full rounded-full ${(current!.received_amount / current!.receivable_amount) >= 1 ? 'bg-white' : (current!.received_amount / current!.receivable_amount) >= 0.5 ? 'bg-white/80' : 'bg-white/50'}`}
                    style={{ width: `${Math.min(Math.round((current!.received_amount! / current!.receivable_amount) * 100), 100)}%` }}
                  />
                </div>
              </div>
            )}

            <p className="mt-3 text-white/50 text-[10px]">Calculated from deal payments</p>
          </div>
        </motion.div>
      ) : (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-10 text-center">
          <div className="text-4xl mb-3">🎯</div>
          <p className="text-gray-600 font-semibold">No target set for this month</p>
          <p className="text-sm text-gray-400 mt-1">Your manager will assign your sales target here.</p>
        </div>
      )}

      {/* 6-month history table */}
      <motion.div
        className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden"
        initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.1 }}
      >
        <div className="px-6 py-4 border-b border-gray-100">
          <h2 className="text-sm font-semibold text-gray-900">6-Month History</h2>
          <p className="text-[11px] text-gray-400 mt-0.5">Your targets and performance over the last 6 months</p>
        </div>
        <div className="overflow-x-auto">
          <table className="min-w-[760px] w-full text-sm">
            <thead>
              <tr className="bg-gray-50 border-b border-gray-100">
                <th className="text-left px-6 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Month</th>
                <th className="text-right px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Sales Target</th>
                <th className="text-right px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Achieved</th>
                <th className="text-center px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">%</th>
                <th className="text-right px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide border-l border-gray-100">Collection Target</th>
                <th className="text-right px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Received</th>
                <th className="text-center px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">%</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {[...(history ?? [])].reverse().map((h) => {
                const salesPct = h.target_amount > 0 ? Math.round((h.achieved_amount / h.target_amount) * 100) : 0;
                const collPct  = h.receivable_amount > 0 && h.received_amount != null
                  ? Math.round(((h.received_amount ?? 0) / h.receivable_amount) * 100)
                  : null;

                return (
                  <tr key={h.period_start} className="hover:bg-gray-50/50 transition-colors">
                    <td className="px-6 py-3.5 font-medium text-gray-800 text-sm">{fmtMonthLabel(h.period_start)}</td>
                    <td className="px-4 py-3.5 text-right text-gray-500 text-xs">{h.target_amount > 0 ? fmtAmount(h.target_amount) : <span className="text-gray-300">—</span>}</td>
                    <td className="px-4 py-3.5 text-right text-gray-700 text-xs font-semibold">{fmtAmount(h.achieved_amount)}</td>
                    <td className="px-4 py-3.5 text-center">
                      {h.target_amount > 0
                        ? <span className={`text-[11px] font-bold px-2 py-0.5 rounded-full ${pctColor(salesPct)}`}>{salesPct}%</span>
                        : <span className="text-gray-300 text-xs">—</span>}
                    </td>
                    <td className="px-4 py-3.5 text-right text-gray-500 text-xs border-l border-gray-100">{h.receivable_amount > 0 ? fmtAmount(h.receivable_amount) : <span className="text-gray-300">—</span>}</td>
                    <td className="px-4 py-3.5 text-right text-xs">
                      <span className={h.received_amount != null ? 'font-semibold text-gray-700' : 'text-gray-300'}>
                        {h.received_amount != null ? fmtAmount(h.received_amount) : '—'}
                      </span>
                    </td>
                    <td className="px-4 py-3.5 text-center">
                      {collPct != null
                        ? <span className={`text-[11px] font-bold px-2 py-0.5 rounded-full ${pctColor(collPct)}`}>{collPct}%</span>
                        : <span className="text-gray-300 text-xs">—</span>}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </motion.div>

      {/* Bar chart */}
      {chartData.some((d) => d.Target > 0 || d.Achieved > 0) && (
        <motion.div
          className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6"
          initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.2 }}
        >
          <h2 className="text-sm font-semibold text-gray-900 mb-1">Sales Performance — 6 Months</h2>
          <p className="text-[11px] text-gray-400 mb-5">Target vs Achieved per month</p>
          <ResponsiveContainer width="100%" height={220}>
            <BarChart data={chartData} margin={{ top: 0, right: 12, left: 0, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" vertical={false} />
              <XAxis dataKey="month" tick={{ fontSize: 10, fill: '#94a3b8' }} tickLine={false} axisLine={false} />
              <YAxis tick={{ fontSize: 10, fill: '#94a3b8' }} tickLine={false} axisLine={false} tickFormatter={(v) => fmtAmount(v)} />
              <Tooltip
                contentStyle={{ borderRadius: 10, border: '1px solid #e2e8f0', fontSize: 11 }}
                formatter={(v) => [fmtAmount(Number(v ?? 0))]}
              />
              <Legend wrapperStyle={{ fontSize: 11, color: '#64748b' }} />
              <Bar dataKey="Target"   fill="#c7d2fe" radius={[4, 4, 0, 0]} name="Target"   />
              <Bar dataKey="Achieved" fill="#6366f1" radius={[4, 4, 0, 0]} name="Achieved" />
            </BarChart>
          </ResponsiveContainer>
        </motion.div>
      )}
    </div>
  );
}

// ── Manager view ──────────────────────────────────────────────────────────────

function ManagerView() {
  const qc    = useQueryClient();
  const today = new Date();
  const { user: authUser } = useAuthStore();

  const [month, setMonth] = useState(
    `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`
  );
  const periodStart = `${month}-01`;

  // Modal state: null = closed; preset userId means "Edit mode"
  const [modal, setModal] = useState<{
    userId: number | ''; targetAmount: string; receivableAmount: string;
  } | null>(null);
  const [saveError, setSaveError] = useState('');

  // Load existing targets for this month
  const { data: targetsData, isLoading: targetsLoading } = useQuery({
    queryKey: ['sales-targets', month],
    queryFn:  () => salesTargetsApi.list({ month }),
  });

  // Load all employees so we can show EVERY team member, not just those with targets
  const { data: employees = [], isLoading: empLoading } = useQuery({
    queryKey: ['employees'],
    queryFn:  () => employeesApi.list(),
    retry: false, // 403 on free plan — silently ignore
  });

  const upsertMutation = useMutation({
    mutationFn: (p: { user_id: number; target_amount: number; receivable_amount: number; period_start: string }) =>
      salesTargetsApi.upsert(p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['sales-targets'] });
      setSaveError('');
      setModal(null);
    },
    onError: (err: unknown) => {
      const msg =
        (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
          ?.response?.data?.message ??
        (err as { message?: string })?.message ??
        'Failed to save target. Please try again.';
      setSaveError(msg);
    },
  });

  // Build a unified rows list: every employee + their target data (if any)
  const targetsByUser = new Map((targetsData ?? []).map((t: SalesTargetRow) => [t.user?.id, t]));

  // Include the owner/admin themselves if they are also a sales person (optional — include all employees + self)
  const allMembers: User[] = employees;

  // If no employees loaded (free plan or empty), fall back to just the targets list
  const rows = allMembers.length > 0
    ? allMembers.map((emp) => {
        const t = targetsByUser.get(emp.id);
        return t ?? {
          id: 0,
          user: { id: emp.id, name: emp.name },
          target_amount: 0,
          receivable_amount: 0,
          received_amount: null,
          achieved_amount: 0,
          period_start: periodStart,
        } as SalesTargetRow;
      })
    : (targetsData ?? []);

  const isLoading = targetsLoading || empLoading;

  const openSetModal = (userId: number | '' = '') => {
    const existing = typeof userId === 'number' ? targetsByUser.get(userId) : undefined;
    setSaveError('');
    setModal({
      userId,
      targetAmount:    existing ? existing.target_amount.toString() : '',
      receivableAmount: existing ? existing.receivable_amount.toString() : '',
    });
  };

  return (
    <div className="space-y-6">

      {/* Header: month picker + Set Target button */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-2">
          <button
            onClick={() => setMonth(prevMonth(month))}
            className="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 transition-all"
          >
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
          </button>
          <span className="text-sm font-semibold text-gray-700 min-w-[130px] text-center">{fmtMonthFull(month)}</span>
          <button
            onClick={() => setMonth(nextMonth(month))}
            className="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 transition-all"
          >
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
            </svg>
          </button>
        </div>

        {/* Set Target button */}
        <button
          onClick={() => openSetModal()}
          className="flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2 rounded-xl transition-colors shadow-sm"
        >
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
          </svg>
          Set Target
        </button>
      </div>

      {/* Table */}
      <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
          <div>
            <h2 className="text-sm font-semibold text-gray-900">Team Targets — {fmtMonthFull(month)}</h2>
            <p className="text-[11px] text-gray-400 mt-0.5">Sales and collection targets for all team members</p>
          </div>
          <span className="text-xs text-gray-400">{rows.length} member{rows.length !== 1 ? 's' : ''}</span>
        </div>

        {isLoading ? (
          <div className="p-6 space-y-3">
            {[...Array(4)].map((_, i) => <div key={i} className="skeleton h-10 rounded-xl" />)}
          </div>
        ) : rows.length === 0 ? (
          <div className="px-6 py-12 text-center">
            <div className="text-3xl mb-2">🎯</div>
            <p className="text-gray-600 font-semibold">No team members found</p>
            <p className="text-sm text-gray-400 mt-1">Add team members first, then set their targets.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
          <table className="min-w-[860px] w-full text-sm">
              <thead>
                <tr className="bg-gray-50 border-b border-gray-100">
                  <th className="text-left px-6 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Rep</th>
                  <th className="text-right px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Sales Target</th>
                  <th className="text-right px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Achieved</th>
                  <th className="text-center px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">%</th>
                  <th className="text-right px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide border-l border-gray-100">Collection Target</th>
                  <th className="text-right px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Received</th>
                  <th className="text-center px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">%</th>
                  <th className="text-center px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {rows.map((row: SalesTargetRow) => {
                  const hasTarget = row.id > 0;
                  const salesPct  = row.target_amount > 0 ? Math.round((row.achieved_amount / row.target_amount) * 100) : null;
                  const collPct   = row.receivable_amount > 0 && row.received_amount != null
                    ? Math.round(((row.received_amount ?? 0) / row.receivable_amount) * 100)
                    : null;

                  return (
                    <tr key={row.user?.id ?? row.id} className="hover:bg-gray-50/50 transition-colors">

                      {/* Rep */}
                      <td className="px-6 py-3.5">
                        <div className="flex items-center gap-2.5">
                          <div className="w-7 h-7 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 flex items-center justify-center text-white text-xs font-bold shrink-0">
                            {row.user?.name[0]?.toUpperCase() ?? '?'}
                          </div>
                          <span className="font-medium text-gray-800 text-sm">{row.user?.name ?? '—'}</span>
                        </div>
                      </td>

                      {/* Sales Target */}
                      <td className="px-4 py-3.5 text-right text-xs">
                        {hasTarget
                          ? <span className="text-gray-500">{fmtAmount(row.target_amount)}</span>
                          : <span className="text-gray-300">Not set</span>}
                      </td>

                      {/* Achieved */}
                      <td className="px-4 py-3.5 text-right text-gray-700 text-xs font-semibold">
                        {hasTarget ? fmtAmount(row.achieved_amount) : <span className="text-gray-300">—</span>}
                      </td>

                      {/* Sales % */}
                      <td className="px-4 py-3.5 text-center">
                        {salesPct != null
                          ? <span className={`text-[11px] font-bold px-2 py-0.5 rounded-full ${pctColor(salesPct)}`}>{salesPct}%</span>
                          : <span className="text-gray-300 text-xs">—</span>}
                      </td>

                      {/* Collection Target */}
                      <td className="px-4 py-3.5 text-right text-xs border-l border-gray-100">
                        {hasTarget
                          ? <span className="text-gray-500">{fmtAmount(row.receivable_amount)}</span>
                          : <span className="text-gray-300">Not set</span>}
                      </td>

                      {/* Received — auto-calculated from deal payments */}
                      <td className="px-4 py-3.5 text-right text-xs">
                        <span className={row.received_amount != null ? 'font-semibold text-gray-700' : 'text-gray-300'}>
                          {row.received_amount != null ? fmtAmount(row.received_amount) : '—'}
                        </span>
                      </td>

                      {/* Collection % */}
                      <td className="px-4 py-3.5 text-center">
                        {collPct != null
                          ? <span className={`text-[11px] font-bold px-2 py-0.5 rounded-full ${pctColor(collPct)}`}>{collPct}%</span>
                          : <span className="text-gray-300 text-xs">—</span>}
                      </td>

                      {/* Actions */}
                      <td className="px-4 py-3.5 text-center">
                        <div className="flex items-center justify-center gap-2 flex-wrap">
                          {/* Set / Edit target */}
                          <button
                            onClick={() => openSetModal(row.user?.id)}
                            className={`text-xs font-medium ${hasTarget ? 'text-indigo-600 hover:text-indigo-700' : 'text-emerald-600 hover:text-emerald-700'}`}
                          >
                            {hasTarget ? 'Edit' : 'Set Target'}
                          </button>

                          {/* Details link */}
                          {row.user?.id && (
                            <>
                              <span className="text-gray-200">|</span>
                              <Link
                                href={`/targets/${row.user.id}`}
                                className="text-gray-500 hover:text-gray-700 text-xs font-medium"
                              >
                                Details →
                              </Link>
                            </>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Set / Edit Target modal */}
      {modal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden">
            <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
              <h3 className="text-base font-semibold text-gray-900">
                Set Target — {fmtMonthFull(month)}
              </h3>
              <button onClick={() => { setModal(null); setSaveError(''); }} className="text-gray-400 hover:text-gray-600 transition-colors">
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            <div className="p-6 space-y-4">
              {/* Error banner */}
              {saveError && (
                <div className="px-4 py-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-600">
                  {saveError}
                </div>
              )}

              {/* Team member select */}
              <div>
                <label className="block text-xs font-semibold text-gray-600 mb-1.5 uppercase tracking-wide">Team Member *</label>
                {employees.length === 0 ? (
                  <div className="px-3 py-2.5 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-700">
                    No team members found. Add team members first under the <strong>Team</strong> section.
                  </div>
                ) : (
                  <select
                    value={modal.userId}
                    onChange={(e) => {
                      const uid = e.target.value ? Number(e.target.value) : '';
                      const existing = typeof uid === 'number' ? targetsByUser.get(uid) : undefined;
                      setSaveError('');
                      setModal({
                        userId: uid,
                        targetAmount:     existing ? existing.target_amount.toString() : '',
                        receivableAmount: existing ? existing.receivable_amount.toString() : '',
                      });
                    }}
                    className="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  >
                    <option value="">Select team member…</option>
                    {employees.map((emp) => (
                      <option key={emp.id} value={emp.id}>{emp.name}</option>
                    ))}
                  </select>
                )}
              </div>

              {/* Sales Target */}
              <div>
                <label className="block text-xs font-semibold text-gray-600 mb-1.5 uppercase tracking-wide">Sales Target (₹) *</label>
                <input
                  type="number" min={0} step={1000}
                  value={modal.targetAmount}
                  onChange={(e) => setModal((m) => m ? { ...m, targetAmount: e.target.value } : m)}
                  className="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  placeholder="e.g. 500000"
                />
              </div>

              {/* Collection Target */}
              <div>
                <label className="block text-xs font-semibold text-gray-600 mb-1.5 uppercase tracking-wide">Collection Target (₹) *</label>
                <input
                  type="number" min={0} step={1000}
                  value={modal.receivableAmount}
                  onChange={(e) => setModal((m) => m ? { ...m, receivableAmount: e.target.value } : m)}
                  className="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  placeholder="e.g. 200000"
                />
              </div>

              <div className="flex gap-3 pt-1">
                <button
                  onClick={() => { setModal(null); setSaveError(''); }}
                  className="flex-1 border border-gray-200 text-gray-600 py-2.5 rounded-xl text-sm hover:bg-gray-50 transition-colors font-medium"
                >
                  Cancel
                </button>
                <button
                  onClick={() => {
                    if (!modal.userId || !modal.targetAmount) return;
                    upsertMutation.mutate({
                      user_id:           modal.userId as number,
                      target_amount:     parseFloat(modal.targetAmount) || 0,
                      receivable_amount: parseFloat(modal.receivableAmount) || 0,
                      period_start:      periodStart,
                    });
                  }}
                  disabled={upsertMutation.isPending || !modal.userId || !modal.targetAmount || employees.length === 0}
                  className="flex-1 bg-indigo-600 text-white py-2.5 rounded-xl text-sm font-semibold hover:bg-indigo-700 disabled:opacity-50 transition-colors"
                >
                  {upsertMutation.isPending ? 'Saving…' : 'Save Target'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function TargetsPage() {
  const { isOwner, isAdmin, isPaidPlan } = useAuthStore();
  const canManage = isOwner() || isAdmin();

  if (!isPaidPlan()) {
    return (
      <AccessDenied
        reason="Sales targets are available on the Business and Enterprise plans. Upgrade to set and track team goals."
        upgradeHref="/plans"
      />
    );
  }

  return (
    <div className="space-y-6">
      <motion.div initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.35 }}>
        <h1 className="text-xl font-bold text-gray-900">
          {canManage ? 'Sales Targets' : 'My Targets'}
        </h1>
        <p className="text-xs text-gray-400 mt-0.5">
          {canManage
            ? 'Set and track monthly sales & collection targets for your team'
            : 'Your monthly sales targets and collection progress'}
        </p>
      </motion.div>

      {canManage ? <ManagerView /> : <TeamMemberView />}
    </div>
  );
}
