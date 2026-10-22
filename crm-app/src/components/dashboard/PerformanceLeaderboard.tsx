'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { salesTargetsApi } from '@/lib/api/salesTargets';
import { departmentsApi } from '@/lib/api/departments';
import { ConversionPeriodSelector } from './ConversionPeriodSelector';
import type { ConversionPeriodSelection, ConversionTargetPeriod, LeaderboardRow } from '@/types';

const rankStyle: Record<number, string> = {
  1: 'bg-[#FFF7DF] text-[#D4A017] ring-[#E9CA68]',
  2: 'bg-[#F1F5F9] text-[#64748B] ring-[#94A3B8]',
  3: 'bg-[#FFF0E5] text-[#C47A44] ring-[#DDA57C]',
};

const rowStyle: Record<number, string> = {
  1: 'bg-[#FFF7DF] border-l-4 border-l-[#D4A017]',
  2: 'bg-[#F1F5F9] border-l-4 border-l-[#94A3B8]',
  3: 'bg-[#FFF0E5] border-l-4 border-l-[#C47A44]',
};

const rankMedal: Record<number, string> = { 1: '🏆', 2: '🥈', 3: '🥉' };

function score(row: LeaderboardRow) {
  return row.ranking_score === null ? '—' : `${Math.round(row.ranking_score)}%`;
}

interface PerformanceLeaderboardProps {
  canManage: boolean;
  currentUserId?: number;
  conversionPeriod: ConversionPeriodSelection;
  onConversionPeriodChange: (period: ConversionPeriodSelection) => void;
  targetPeriods: ConversionTargetPeriod[];
}

export function PerformanceLeaderboard({ canManage, currentUserId, conversionPeriod, onConversionPeriodChange, targetPeriods }: PerformanceLeaderboardProps) {
  const [metric, setMetric] = useState<'target_achievement' | 'lead_conversion'>('target_achievement');
  const [category, setCategory] = useState<'sales' | 'collection' | 'overall'>('overall');
  const [mode, setMode] = useState<'actual' | 'pace'>('actual');
  const [periodScope, setPeriodScope] = useState<'active' | 'completed'>('active');
  const [departmentId, setDepartmentId] = useState('');
  const conversionParams = metric === 'lead_conversion' ? {
    conversion_period: conversionPeriod.mode,
    conversion_month: conversionPeriod.mode === 'month' ? conversionPeriod.month : undefined,
    conversion_start: conversionPeriod.mode === 'custom' ? conversionPeriod.start : undefined,
    conversion_end: conversionPeriod.mode === 'custom' ? conversionPeriod.end : undefined,
    conversion_target_id: conversionPeriod.mode === 'target' && conversionPeriod.targetId ? Number(conversionPeriod.targetId) : undefined,
  } : {};
  const leaderboard = useQuery({
    queryKey: ['performance-leaderboard', metric, category, mode, periodScope, departmentId, conversionPeriod],
    queryFn: () => salesTargetsApi.leaderboard({
      metric,
      category,
      mode,
      period_scope: periodScope,
      department_id: departmentId ? Number(departmentId) : undefined,
      ...conversionParams,
    }),
    retry: false,
  });
  const departments = useQuery({ queryKey: ['departments', 'leaderboard'], queryFn: departmentsApi.list, enabled: canManage, retry: false });
  if (leaderboard.isError) {
    return <section role="alert" className="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm">
      <h2 className="text-base font-bold text-gray-900">Performance Leaderboard unavailable</h2>
      <p className="mt-1 text-sm text-gray-500">Rankings could not be loaded. No performance values are being assumed.</p>
      <button type="button" onClick={() => void leaderboard.refetch()} disabled={leaderboard.isFetching} className="mt-3 min-h-10 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
        {leaderboard.isFetching ? 'Retrying…' : 'Retry leaderboard'}
      </button>
    </section>;
  }
  const rows = leaderboard.data?.rows ?? [];
  const ownRank = leaderboard.data?.own_rank;
  const isConversion = metric === 'lead_conversion';
  const availableTargetPeriods = leaderboard.data?.target_periods ?? targetPeriods;

  return <section className="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
    <div className="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 xl:flex-row xl:items-center xl:justify-between">
      <div><div className="flex items-center gap-2"><h2 className="text-base font-bold text-gray-900">Performance Leaderboard</h2><span className="rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-bold uppercase text-indigo-600">Live</span></div><p className="mt-0.5 text-xs text-gray-400">{isConversion ? `Lead conversion · ${leaderboard.data?.period?.label ?? 'selected reporting period'}` : 'Fair ranking against each employee\'s own target period'}</p></div>
      <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
        <select aria-label="Leaderboard metric" value={metric} onChange={(e) => setMetric(e.target.value as typeof metric)} className="rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-700"><option value="target_achievement">Target achievement</option><option value="lead_conversion">Lead conversion</option></select>
        {!isConversion && <select aria-label="Target category" value={category} onChange={(e) => setCategory(e.target.value as typeof category)} className="rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-700"><option value="overall">Overall</option><option value="sales">Sales</option><option value="collection">Collection</option></select>}
        {!isConversion && <select aria-label="Ranking calculation" value={mode} onChange={(e) => setMode(e.target.value as typeof mode)} className="rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-700"><option value="actual">Actual %</option><option value="pace">Pace-based</option></select>}
        {!isConversion && canManage && <select aria-label="Target period status" value={periodScope} onChange={(e) => setPeriodScope(e.target.value as typeof periodScope)} className="rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-700"><option value="active">Active periods</option><option value="completed">Completed periods</option></select>}
        {canManage && <select value={departmentId} onChange={(e) => setDepartmentId(e.target.value)} className="rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-700"><option value="">All departments</option>{(departments.data ?? []).map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}</select>}
      </div>
    </div>
    {isConversion && <div className="border-b border-gray-100 bg-gray-50/50 px-5 py-3"><ConversionPeriodSelector value={conversionPeriod} onChange={onConversionPeriodChange} targetPeriods={availableTargetPeriods} compact /></div>}
    {!leaderboard.data && leaderboard.isLoading ? <div className="space-y-2 p-5">{[1, 2, 3].map((i) => <div key={i} className="skeleton h-16 rounded-xl" />)}</div> : rows.length === 0 ? <div className="px-5 py-10 text-center text-sm text-gray-400">{isConversion ? 'No eligible employees or assigned leads are available for this period.' : 'No targets are available for this period.'}</div> : <>
      <div className="divide-y divide-gray-100">{rows.map((row) => {
        const isMe = row.user_id === currentUserId;
        const highlighted = row.rank ? rowStyle[row.rank] : undefined;
        return <div key={`${row.target_id}-${row.user_id}`} className={`grid grid-cols-[auto_1fr_auto] items-center gap-3 px-5 py-3.5 sm:grid-cols-[auto_1fr_repeat(3,minmax(72px,auto))] ${highlighted ?? (isMe ? 'bg-indigo-50/50' : 'hover:bg-gray-50')}`}>
          <div className={`flex h-10 w-10 items-center justify-center rounded-full text-sm font-extrabold ring-1 ${row.rank && rankStyle[row.rank] ? rankStyle[row.rank] : 'bg-gray-50 text-gray-500 ring-gray-200'}`}><span aria-label={row.rank ? `Rank ${row.rank}` : 'Unranked'}>{row.rank && rankMedal[row.rank] ? rankMedal[row.rank] : row.rank ?? '—'}</span></div>
          <div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><p className="truncate text-sm font-semibold text-gray-900">{row.employee_name ?? `Employee #${row.rank ?? row.user_id}`}{isMe ? ' (You)' : ''}</p>{row.badges.map((badge) => <span key={badge} className="rounded-full bg-emerald-50 px-2 py-0.5 text-[9px] font-bold text-emerald-700">{badge}</span>)}</div><p className="mt-0.5 truncate text-[11px] text-gray-400">{row.department ?? 'No department'} · {row.period_label}</p></div>
          <div className="text-right sm:hidden"><p className="text-lg font-extrabold text-indigo-600">{isConversion ? `${Math.round(row.conversion_ratio ?? 0)}%` : score(row)}</p><p className="text-[9px] uppercase text-gray-400">{isConversion ? 'conversion' : 'score'}</p></div>
          <div className="hidden text-right sm:block"><p className="text-sm font-bold text-gray-800">{isConversion ? row.assigned_leads ?? 0 : row.sales_percentage === null ? '—' : `${Math.round(row.sales_percentage)}%`}</p><p className="text-[9px] uppercase text-gray-400">{isConversion ? 'assigned' : 'sales'}</p></div>
          <div className="hidden text-right sm:block"><p className="text-sm font-bold text-gray-800">{isConversion ? row.converted_leads ?? 0 : row.collection_percentage === null ? '—' : `${Math.round(row.collection_percentage)}%`}</p><p className="text-[9px] uppercase text-gray-400">{isConversion ? 'converted' : 'collection'}</p></div>
          <div className="hidden text-right sm:block"><p className="text-lg font-extrabold text-indigo-600">{isConversion ? `${Math.round(row.conversion_ratio ?? 0)}%` : score(row)}</p><p className="text-[9px] uppercase text-gray-400">{isConversion ? 'conversion' : mode === 'pace' ? 'pace score' : category}</p></div>
        </div>;
      })}</div>
      <div className="border-t border-gray-100 bg-gray-50/60 px-5 py-3"><p className="text-[11px] text-gray-500">{isConversion ? 'Ranked by unique converted leads ÷ unique assigned leads for the selected period; ties share a rank.' : mode === 'pace' ? 'Pace score compares actual progress with elapsed working days.' : 'Ranked by target achievement percentage; ties share a rank.'}</p></div>
      {!canManage && ownRank && !rows.some((row) => row.user_id === currentUserId) && <div className="border-t border-indigo-100 bg-indigo-50 px-5 py-3 text-sm text-indigo-800">Your rank: <strong>#{ownRank.rank ?? '—'}</strong> · {score(ownRank)}</div>}
    </>}
  </section>;
}
