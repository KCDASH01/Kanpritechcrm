'use client';

import { useQuery } from '@tanstack/react-query';
import Link from 'next/link';
import { dynamicTargetsApi } from '@/lib/api/dynamicTargets';

function value(amount: number, unit: string, label?: string | null) {
  if (unit === 'currency') return `₹${amount.toLocaleString('en-IN', { maximumFractionDigits: 2 })}`;
  if (unit === 'percentage') return `${amount}%`;
  return `${amount.toLocaleString('en-IN')} ${label || unit}`;
}

export function DynamicTargetSummary({ selectedUserId }: { selectedUserId?: number }) {
  const query = useQuery({
    queryKey: ['dynamic-target-assignments', 'dashboard', selectedUserId],
    queryFn: () => dynamicTargetsApi.assignments({ status: 'active', user_id: selectedUserId }),
    retry: false,
  });

  if (query.isError) {
    return <section className="rounded-2xl border border-amber-200 bg-white px-5 py-4"><p className="text-sm font-semibold text-gray-800">Additional targets unavailable</p><p className="mt-1 text-xs text-gray-500">Legacy Sales and Collection target reporting is unaffected.</p></section>;
  }
  if (query.isLoading) {
    return <div className="skeleton h-36 rounded-2xl" />;
  }
  const rows = query.data ?? [];
  if (!rows.length) return null;

  return <section className="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm"><div className="flex items-center justify-between border-b px-5 py-4"><div><h2 className="text-base font-bold text-gray-900">Additional Performance Targets</h2><p className="text-xs text-gray-400">Compatible metrics are tracked separately without mixing units</p></div><Link href="/targets" className="text-xs font-semibold text-indigo-600">Manage targets →</Link></div><div className="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-3">{rows.slice(0, 6).map((row) => <article key={row.id} className="rounded-xl border border-gray-100 bg-gray-50/60 p-4"><div className="flex justify-between gap-3"><div><p className="text-sm font-semibold text-gray-900">{row.target_name}</p><p className="text-[10px] text-gray-400">{row.user?.name || row.department?.name} · {row.period_start} – {row.period_end}</p></div><p className="text-lg font-extrabold text-indigo-600">{row.achievement_percentage == null ? '—' : `${row.achievement_percentage}%`}</p></div><div className="mt-3 h-2 overflow-hidden rounded-full bg-gray-200"><div className="h-full rounded-full bg-indigo-500" style={{ width: `${Math.min(100, Math.max(0, row.achievement_percentage ?? 0))}%` }} /></div><p className="mt-2 text-xs text-gray-500">{value(row.actual_value, row.unit_type, row.unit_label)} of {value(row.target_value, row.unit_type, row.unit_label)}</p></article>)}</div></section>;
}
