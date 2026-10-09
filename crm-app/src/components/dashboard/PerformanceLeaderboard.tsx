'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { salesTargetsApi } from '@/lib/api/salesTargets';
import { departmentsApi } from '@/lib/api/departments';
import type { LeaderboardRow } from '@/types';

const rankStyle: Record<number, string> = {
  1: 'bg-amber-100 text-amber-700 ring-amber-200',
  2: 'bg-slate-100 text-slate-600 ring-slate-200',
  3: 'bg-orange-100 text-orange-700 ring-orange-200',
};

function score(row: LeaderboardRow) {
  return row.ranking_score === null ? '—' : `${Math.round(row.ranking_score)}%`;
}

export function PerformanceLeaderboard({ canManage, currentUserId }: { canManage: boolean; currentUserId?: number }) {
  const [category, setCategory] = useState<'sales' | 'collection' | 'overall'>('overall');
  const [mode, setMode] = useState<'actual' | 'pace'>('actual');
  const [periodScope, setPeriodScope] = useState<'active' | 'completed'>('active');
  const [departmentId, setDepartmentId] = useState('');
  const [expanded, setExpanded] = useState(false);
  const leaderboard = useQuery({
    queryKey: ['performance-leaderboard', category, mode, periodScope, departmentId],
    queryFn: () => salesTargetsApi.leaderboard({ category, mode, period_scope: periodScope, department_id: departmentId ? Number(departmentId) : undefined }),
    retry: false,
  });
  const departments = useQuery({ queryKey: ['departments', 'leaderboard'], queryFn: departmentsApi.list, enabled: canManage, retry: false });
  if (leaderboard.isError) return null;
  const rows = leaderboard.data?.rows ?? [];
  const visibleRows = expanded ? rows : rows.slice(0, 5);
  const ownRank = leaderboard.data?.own_rank;

  return <section className="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
    <div className="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 xl:flex-row xl:items-center xl:justify-between">
      <div><div className="flex items-center gap-2"><h2 className="text-base font-bold text-gray-900">Performance Leaderboard</h2><span className="rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-bold uppercase text-indigo-600">Live</span></div><p className="mt-0.5 text-xs text-gray-400">Fair ranking against each employee&apos;s own target period</p></div>
      <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
        <select value={category} onChange={(e) => setCategory(e.target.value as typeof category)} className="rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-700"><option value="overall">Overall</option><option value="sales">Sales</option><option value="collection">Collection</option></select>
        <select value={mode} onChange={(e) => setMode(e.target.value as typeof mode)} className="rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-700"><option value="actual">Actual %</option><option value="pace">Pace-based</option></select>
        {canManage && <select value={periodScope} onChange={(e) => setPeriodScope(e.target.value as typeof periodScope)} className="rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-700"><option value="active">Active periods</option><option value="completed">Completed periods</option></select>}
        {canManage && <select value={departmentId} onChange={(e) => setDepartmentId(e.target.value)} className="rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-700"><option value="">All departments</option>{(departments.data ?? []).map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}</select>}
      </div>
    </div>
    {!leaderboard.data && leaderboard.isLoading ? <div className="space-y-2 p-5">{[1, 2, 3].map((i) => <div key={i} className="skeleton h-16 rounded-xl" />)}</div> : rows.length === 0 ? <div className="px-5 py-10 text-center text-sm text-gray-400">No targets are available for this period.</div> : <>
      <div className="divide-y divide-gray-100">{visibleRows.map((row) => {
        const isMe = row.user_id === currentUserId;
        return <div key={`${row.target_id}-${row.user_id}`} className={`grid grid-cols-[auto_1fr_auto] items-center gap-3 px-5 py-3.5 sm:grid-cols-[auto_1fr_repeat(3,minmax(72px,auto))] ${isMe ? 'bg-indigo-50/50' : 'hover:bg-gray-50'}`}>
          <div className={`flex h-9 w-9 items-center justify-center rounded-full text-sm font-extrabold ring-1 ${row.rank && rankStyle[row.rank] ? rankStyle[row.rank] : 'bg-gray-50 text-gray-500 ring-gray-200'}`}>{row.rank ?? '—'}</div>
          <div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><p className="truncate text-sm font-semibold text-gray-900">{row.employee_name ?? `Employee #${row.rank ?? row.user_id}`}{isMe ? ' (You)' : ''}</p>{row.badges.map((badge) => <span key={badge} className="rounded-full bg-emerald-50 px-2 py-0.5 text-[9px] font-bold text-emerald-700">{badge}</span>)}</div><p className="mt-0.5 truncate text-[11px] text-gray-400">{row.department ?? 'No department'} · {row.period_label}</p></div>
          <div className="text-right sm:hidden"><p className="text-lg font-extrabold text-indigo-600">{score(row)}</p><p className="text-[9px] uppercase text-gray-400">score</p></div>
          <div className="hidden text-right sm:block"><p className="text-sm font-bold text-gray-800">{row.sales_percentage === null ? '—' : `${Math.round(row.sales_percentage)}%`}</p><p className="text-[9px] uppercase text-gray-400">sales</p></div>
          <div className="hidden text-right sm:block"><p className="text-sm font-bold text-gray-800">{row.collection_percentage === null ? '—' : `${Math.round(row.collection_percentage)}%`}</p><p className="text-[9px] uppercase text-gray-400">collection</p></div>
          <div className="hidden text-right sm:block"><p className="text-lg font-extrabold text-indigo-600">{score(row)}</p><p className="text-[9px] uppercase text-gray-400">{mode === 'pace' ? 'pace score' : category}</p></div>
        </div>;
      })}</div>
      <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 bg-gray-50/60 px-5 py-3"><p className="text-[11px] text-gray-500">{mode === 'pace' ? 'Pace score compares actual progress with elapsed working days.' : 'Ranked by target achievement percentage; ties share a rank.'}</p>{rows.length > 5 && <button onClick={() => setExpanded((value) => !value)} className="text-xs font-semibold text-indigo-600 hover:text-indigo-800">{expanded ? 'Show top 5' : `View all ${rows.length}`}</button>}</div>
      {!canManage && ownRank && !visibleRows.some((row) => row.user_id === currentUserId) && <div className="border-t border-indigo-100 bg-indigo-50 px-5 py-3 text-sm text-indigo-800">Your rank: <strong>#{ownRank.rank ?? '—'}</strong> · {score(ownRank)}</div>}
    </>}
  </section>;
}
