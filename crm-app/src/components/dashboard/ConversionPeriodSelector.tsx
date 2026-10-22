'use client';

import type { ConversionPeriodSelection, ConversionTargetPeriod } from '@/types';

interface Props {
  value: ConversionPeriodSelection;
  onChange: (value: ConversionPeriodSelection) => void;
  targetPeriods: ConversionTargetPeriod[];
  compact?: boolean;
}

const fieldClass = 'min-h-10 rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500';

export function ConversionPeriodSelector({ value, onChange, targetPeriods, compact = false }: Props) {
  const update = (changes: Partial<ConversionPeriodSelection>) => onChange({ ...value, ...changes });
  const selectMode = (mode: ConversionPeriodSelection['mode']) => {
    const today = new Date();
    const localDate = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
    update({
      mode,
      ...(mode === 'custom' && (!value.start || !value.end) ? { start: localDate, end: localDate } : {}),
      ...(mode === 'target' && !value.targetId && targetPeriods[0] ? { targetId: String(targetPeriods[0].id) } : {}),
    });
  };
  const invalidRange = value.mode === 'custom' && value.start !== '' && value.end !== '' && value.end < value.start;

  return <div className={`flex flex-wrap items-end gap-2 ${compact ? '' : 'rounded-2xl border border-indigo-100 bg-indigo-50/60 p-3'}`}>
    <label className="min-w-44 text-[10px] font-semibold uppercase tracking-wide text-gray-500">Reporting period
      <select aria-label="Lead conversion reporting period" value={value.mode} onChange={(event) => selectMode(event.target.value as ConversionPeriodSelection['mode'])} className={`${fieldClass} mt-1 w-full`}>
        <option value="current_month">Current Month</option>
        <option value="month">Select Month</option>
        <option value="custom">Custom Date Range</option>
        <option value="target" disabled={targetPeriods.length === 0}>Set Target Period</option>
        <option value="all_time">All Time</option>
      </select>
    </label>
    {value.mode === 'month' && <label className="text-[10px] font-semibold uppercase tracking-wide text-gray-500">Month
      <input aria-label="Conversion month" type="month" value={value.month} onChange={(event) => update({ month: event.target.value })} className={`${fieldClass} mt-1 block`} />
    </label>}
    {value.mode === 'custom' && <>
      <label className="text-[10px] font-semibold uppercase tracking-wide text-gray-500">Start date
        <input aria-label="Conversion start date" type="date" value={value.start} onChange={(event) => update({ start: event.target.value, ...(value.end && value.end < event.target.value ? { end: event.target.value } : {}) })} className={`${fieldClass} mt-1 block`} />
      </label>
      <label className="text-[10px] font-semibold uppercase tracking-wide text-gray-500">End date
        <input aria-label="Conversion end date" type="date" min={value.start || undefined} value={value.end} onChange={(event) => update({ end: event.target.value })} className={`${fieldClass} mt-1 block ${invalidRange ? 'border-red-400' : ''}`} />
      </label>
      {invalidRange && <p role="alert" className="w-full text-xs font-medium text-red-600">End date must be on or after the start date.</p>}
    </>}
    {value.mode === 'target' && <label className="min-w-56 flex-1 text-[10px] font-semibold uppercase tracking-wide text-gray-500">Configured target period
      <select aria-label="Configured target period" value={value.targetId} onChange={(event) => update({ targetId: event.target.value })} className={`${fieldClass} mt-1 w-full`}>
        <option value="">Select a target period…</option>
        {targetPeriods.map((period) => <option key={period.id} value={period.id}>{period.label}</option>)}
      </select>
    </label>}
  </div>;
}
