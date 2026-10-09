'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { motion } from 'framer-motion';
import {
  BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend,
} from 'recharts';
import { salesTargetsApi } from '@/lib/api/salesTargets';
import { useAuthStore } from '@/store/authStore';
import { SkeletonCard } from '@/components/ui/Skeleton';
import type { MyTargetProgress } from '@/types';

// ── Helpers (mirrors targets/page.tsx) ───────────────────────────────────────

function fmtAmount(n: number) {
  if (n >= 10_00_000) return `₹${(n / 10_00_000).toFixed(2)}Cr`;
  if (n >= 1_00_000)  return `₹${(n / 1_00_000).toFixed(2)}L`;
  if (n >= 1_000)     return `₹${(n / 1_000).toFixed(1)}K`;
  return `₹${n.toFixed(0)}`;
}

function pctColor(pct: number) {
  if (pct >= 100) return 'text-emerald-600 bg-emerald-50';
  if (pct >= 50)  return 'text-amber-600 bg-amber-50';
  return 'text-red-600 bg-red-50';
}

function fmtMonthLabel(periodStart: string) {
  const [y, m] = periodStart.split('-').map(Number);
  return new Date(y, m - 1, 1).toLocaleDateString('en-IN', { month: 'short', year: 'numeric' });
}

// ── Progress Card ─────────────────────────────────────────────────────────────

function ProgressCard({
  label, actual, target, sublabel, gradient, iconPath,
}: {
  label: string; actual: number; target: number; sublabel: string;
  gradient: string; iconPath: string;
}) {
  const pct    = target > 0 ? Math.min(Math.round((actual / target) * 100), 100) : 0;
  const rawPct = target > 0 ? Math.round((actual / target) * 100) : 0;

  return (
    <div className={`relative bg-gradient-to-br ${gradient} rounded-2xl p-6 text-white shadow-lg overflow-hidden`}>
      <div className="absolute -right-6 -top-6 w-28 h-28 bg-white/10 rounded-full" />
      <div className="absolute -right-2 top-2 w-16 h-16 bg-white/10 rounded-full flex items-center justify-center">
        <svg className="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d={iconPath} />
        </svg>
      </div>
      <p className="text-white/70 text-[11px] font-semibold uppercase tracking-wider mb-2">{label}</p>
      <p className="text-3xl font-bold leading-none">{fmtAmount(actual)}</p>
      <p className="text-white/60 text-xs mt-1.5">of {fmtAmount(target)} target</p>
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

// ── Page ──────────────────────────────────────────────────────────────────────

export default function UserTargetDetailPage() {
  const params = useParams();
  const userId = Number(params.userId);
  const qc = useQueryClient();
  const { isOwner, isAdmin } = useAuthStore();
  const canManage = isOwner() || isAdmin();

  // Editing state for history table inline edit
  const [editingPeriod, setEditingPeriod] = useState<string | null>(null);
  const [editTarget, setEditTarget]       = useState('');
  const [editReceivable, setEditReceivable] = useState('');

  // Fetch 6-month progress for this user
  const { data, isLoading } = useQuery({
    queryKey: ['user-target-progress', userId],
    queryFn:  () => salesTargetsApi.userProgress(userId),
    enabled:  canManage && !!userId,
  });

  const history: MyTargetProgress[] = data?.data ?? [];
  const employeeName = data?.user?.name ?? '—';
  const employeeEmail = data?.user?.email ?? '';

  // Current month = last entry
  const current = history[history.length - 1] ?? null;

  const upsertMutation = useMutation({
    mutationFn: (payload: { user_id: number; target_amount: number; receivable_amount: number; period_start: string }) =>
      salesTargetsApi.upsert(payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['user-target-progress', userId] });
      qc.invalidateQueries({ queryKey: ['sales-targets'] });
      setEditingPeriod(null);
    },
  });

  // Chart data
  const salesChartData = history.map((h) => ({
    month:    fmtMonthLabel(h.period_start),
    Target:   h.target_amount,
    Achieved: h.achieved_amount,
  }));

  const collChartData = history.map((h) => ({
    month:    fmtMonthLabel(h.period_start),
    'Collection Target': h.receivable_amount,
    Received: h.received_amount ?? 0,
  }));

  if (!canManage) {
    return (
      <div className="p-6">
        <div className="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-6 py-8 text-center">
          <p className="font-semibold">Access denied</p>
          <p className="text-sm mt-1">Only owners and admins can view team member targets.</p>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6">

      {/* Header */}
      <div className="flex items-center gap-3">
        <Link
          href="/targets"
          className="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 transition-all"
        >
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <div className="flex-1 flex items-center gap-3">
          <div className="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 flex items-center justify-center text-white font-bold text-sm shrink-0">
            {employeeName[0]?.toUpperCase() ?? '?'}
          </div>
          <div>
            <h1 className="text-xl font-bold text-gray-900">{isLoading ? '…' : employeeName}</h1>
            <p className="text-xs text-gray-400">{employeeEmail}</p>
          </div>
        </div>
        <span className="text-xs text-gray-400 bg-gray-100 px-3 py-1 rounded-full font-medium">Sales Performance</span>
      </div>

      {isLoading ? (
        <div className="space-y-6">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <SkeletonCard /> <SkeletonCard />
          </div>
          <div className="skeleton h-56 rounded-2xl" />
          <div className="skeleton h-56 rounded-2xl" />
        </div>
      ) : (
        <>
          {/* Current month progress cards */}
          {current && (current.target_amount > 0 || current.receivable_amount > 0) ? (
            <motion.div
              className="grid grid-cols-1 sm:grid-cols-2 gap-4"
              initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }}
            >
              <ProgressCard
                label={`Sales Target — ${fmtMonthLabel(current.period_start)}`}
                actual={current.achieved_amount}
                target={current.target_amount}
                sublabel="Won deals this month"
                gradient="from-indigo-500 to-violet-600"
                iconPath="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
              />
              <ProgressCard
                label={`Collections — ${fmtMonthLabel(current.period_start)}`}
                actual={current.received_amount ?? 0}
                target={current.receivable_amount}
                sublabel="Amount collected"
                gradient="from-emerald-500 to-teal-600"
                iconPath="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"
              />
            </motion.div>
          ) : (
            <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-10 text-center">
              <div className="text-4xl mb-3">🎯</div>
              <p className="text-gray-600 font-semibold">No target set for this month</p>
              <p className="text-sm text-gray-400 mt-1">Use the table below to set a target for any month.</p>
            </div>
          )}

          {/* Charts */}
          {history.some((h) => h.target_amount > 0 || h.achieved_amount > 0) && (
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              <motion.div
                className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6"
                initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.1 }}
              >
                <h2 className="text-sm font-semibold text-gray-900 mb-1">Sales Performance — 6 Months</h2>
                <p className="text-[11px] text-gray-400 mb-5">Target vs Won deals value per month</p>
                <ResponsiveContainer width="100%" height={220}>
                  <BarChart data={salesChartData} margin={{ top: 0, right: 12, left: 0, bottom: 0 }}>
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

              <motion.div
                className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6"
                initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.15 }}
              >
                <h2 className="text-sm font-semibold text-gray-900 mb-1">Collections Performance — 6 Months</h2>
                <p className="text-[11px] text-gray-400 mb-5">Collection target vs amount received per month</p>
                <ResponsiveContainer width="100%" height={220}>
                  <BarChart data={collChartData} margin={{ top: 0, right: 12, left: 0, bottom: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" vertical={false} />
                    <XAxis dataKey="month" tick={{ fontSize: 10, fill: '#94a3b8' }} tickLine={false} axisLine={false} />
                    <YAxis tick={{ fontSize: 10, fill: '#94a3b8' }} tickLine={false} axisLine={false} tickFormatter={(v) => fmtAmount(v)} />
                    <Tooltip
                      contentStyle={{ borderRadius: 10, border: '1px solid #e2e8f0', fontSize: 11 }}
                      formatter={(v) => [fmtAmount(Number(v ?? 0))]}
                    />
                    <Legend wrapperStyle={{ fontSize: 11, color: '#64748b' }} />
                    <Bar dataKey="Collection Target" fill="#a7f3d0" radius={[4, 4, 0, 0]} name="Collection Target" />
                    <Bar dataKey="Received"          fill="#10b981" radius={[4, 4, 0, 0]} name="Received"          />
                  </BarChart>
                </ResponsiveContainer>
              </motion.div>
            </div>
          )}

          {/* History table with inline editing */}
          <motion.div
            className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden"
            initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.2 }}
          >
            <div className="px-6 py-4 border-b border-gray-100">
              <h2 className="text-sm font-semibold text-gray-900">Monthly History & Target Editor</h2>
              <p className="text-[11px] text-gray-400 mt-0.5">Click Edit to update targets or enter received amounts</p>
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
                    <th className="text-center px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wide">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {[...history].reverse().map((h) => {
                    const salesPct = h.target_amount > 0 ? Math.round((h.achieved_amount / h.target_amount) * 100) : 0;
                    const collPct  = h.receivable_amount > 0 && h.received_amount != null
                      ? Math.round(((h.received_amount ?? 0) / h.receivable_amount) * 100)
                      : null;
                    const isEditingTargets  = editingPeriod === h.period_start;

                    return (
                      <tr key={h.period_start} className={`transition-colors ${isEditingTargets ? 'bg-indigo-50/30' : 'hover:bg-gray-50/50'}`}>

                        {/* Month */}
                        <td className="px-6 py-3.5 font-medium text-gray-800 text-sm">{fmtMonthLabel(h.period_start)}</td>

                        {/* Sales Target — inline edit when isEditingTargets */}
                        <td className="px-4 py-3.5 text-right text-gray-500 text-xs">
                          {isEditingTargets ? (
                            <input
                              type="number" min={0} value={editTarget}
                              onChange={(e) => setEditTarget(e.target.value)}
                              className="w-28 border border-indigo-300 rounded-lg px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-400 text-right"
                              placeholder="Sales ₹"
                            />
                          ) : (
                            h.target_amount > 0 ? fmtAmount(h.target_amount) : <span className="text-gray-300">—</span>
                          )}
                        </td>

                        {/* Achieved */}
                        <td className="px-4 py-3.5 text-right text-gray-700 text-xs font-semibold">{fmtAmount(h.achieved_amount)}</td>

                        {/* Sales % */}
                        <td className="px-4 py-3.5 text-center">
                          {h.target_amount > 0
                            ? <span className={`text-[11px] font-bold px-2 py-0.5 rounded-full ${pctColor(salesPct)}`}>{salesPct}%</span>
                            : <span className="text-gray-300 text-xs">—</span>}
                        </td>

                        {/* Collection Target — inline edit when isEditingTargets */}
                        <td className="px-4 py-3.5 text-right text-gray-500 text-xs border-l border-gray-100">
                          {isEditingTargets ? (
                            <input
                              type="number" min={0} value={editReceivable}
                              onChange={(e) => setEditReceivable(e.target.value)}
                              className="w-28 border border-indigo-300 rounded-lg px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-400 text-right"
                              placeholder="Collection ₹"
                            />
                          ) : (
                            h.receivable_amount > 0 ? fmtAmount(h.receivable_amount) : <span className="text-gray-300">—</span>
                          )}
                        </td>

                        {/* Received — auto-calculated from deal payments */}
                        <td className="px-4 py-3.5 text-right text-xs">
                          <span className={h.received_amount != null ? 'font-semibold text-gray-700' : 'text-gray-300'}>
                            {h.received_amount != null ? fmtAmount(h.received_amount) : '—'}
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
                          <div className="flex items-center justify-center gap-1.5 flex-wrap">

                            {/* Editing targets: Save / Cancel */}
                            {isEditingTargets ? (
                              <>
                                <button
                                  onClick={() => {
                                    upsertMutation.mutate({
                                      user_id:           userId,
                                      target_amount:     parseFloat(editTarget) || 0,
                                      receivable_amount: parseFloat(editReceivable) || 0,
                                      period_start:      h.period_start,
                                    });
                                  }}
                                  disabled={upsertMutation.isPending}
                                  className="bg-indigo-600 text-white text-xs font-semibold px-2.5 py-1 rounded-lg hover:bg-indigo-700 disabled:opacity-50"
                                >
                                  {upsertMutation.isPending ? '…' : 'Save'}
                                </button>
                                <button
                                  onClick={() => setEditingPeriod(null)}
                                  className="text-gray-400 hover:text-gray-600 text-xs px-1.5 py-1"
                                >
                                  Cancel
                                </button>
                              </>
                            ) : (
                              /* Normal state — only target editing remains */
                              <button
                                onClick={() => {
                                  setEditingPeriod(h.period_start);
                                  setEditTarget(h.target_amount > 0 ? h.target_amount.toString() : '');
                                  setEditReceivable(h.receivable_amount > 0 ? h.receivable_amount.toString() : '');
                                }}
                                className="text-indigo-600 hover:text-indigo-700 text-xs font-medium"
                              >
                                {h.target_id ? 'Edit Target' : 'Set Target'}
                              </button>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </motion.div>
        </>
      )}
    </div>
  );
}
