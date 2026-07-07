'use client';

import { LEAD_STATUS_LABELS, type LeadStatus } from '@/lib/leadStatuses';

interface Props {
  status: LeadStatus;
  onStatusChange: (s: LeadStatus) => void;
  saving: boolean;
  canEdit?: boolean;
}

const MAIN_STEPS: { key: LeadStatus; label: string; num: number }[] = [
  { key: 'new',       label: 'New',       num: 1 },
  { key: 'contacted', label: 'Contacted', num: 2 },
  { key: 'qualified', label: 'Qualified', num: 3 },
];

const TERMINAL: { key: LeadStatus; label: string; color: string; activeColor: string }[] = [
  { key: 'unqualified',    label: 'Unqualified',    color: 'border-gray-300 text-gray-500 hover:border-gray-400',          activeColor: 'bg-gray-500 border-gray-500 text-white'       },
  { key: 'not_interested', label: 'Not Interested', color: 'border-gray-300 text-gray-500 hover:border-gray-400',          activeColor: 'bg-gray-600 border-gray-600 text-white'       },
  { key: 'converted',      label: 'Converted',      color: 'border-emerald-300 text-emerald-600 hover:border-emerald-400', activeColor: 'bg-emerald-500 border-emerald-500 text-white' },
  { key: 'lost',           label: 'Lost',           color: 'border-red-300 text-red-500 hover:border-red-400',           activeColor: 'bg-red-500 border-red-500 text-white'         },
];

const SCHEDULED: { key: LeadStatus; label: string; color: string; activeColor: string }[] = [
  { key: 'followup', label: 'Follow-up', color: 'border-orange-300 text-orange-600 hover:border-orange-400', activeColor: 'bg-orange-500 border-orange-500 text-white' },
  { key: 'meeting',  label: 'Meeting',   color: 'border-violet-300 text-violet-600 hover:border-violet-400', activeColor: 'bg-violet-500 border-violet-500 text-white'   },
];

function isMainStep(s: LeadStatus) {
  return MAIN_STEPS.some((x) => x.key === s);
}

export function StatusStepperCard({ status, onStatusChange, saving, canEdit = true }: Props) {
  const activeIdx = MAIN_STEPS.findIndex((s) => s.key === status);
  const isTerminal = !isMainStep(status) && !SCHEDULED.some((x) => x.key === status);
  const isScheduled = SCHEDULED.some((x) => x.key === status);

  return (
    <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
      <h3 className="text-sm font-semibold text-gray-900 mb-4">Pipeline Status</h3>

      {/* Main flow stepper */}
      <div className="flex items-center gap-0 mb-4">
        {MAIN_STEPS.map((step, idx) => {
          const isDone    = !isTerminal && !isScheduled && idx < activeIdx;
          const isActive  = !isTerminal && !isScheduled && idx === activeIdx;
          const isFuture  = isTerminal || isScheduled || idx > activeIdx;

          return (
            <div key={step.key} className="flex items-center flex-1">
              <button
                onClick={() => canEdit && !saving && onStatusChange(step.key)}
                disabled={!canEdit || saving || (isActive && !isTerminal && !isScheduled)}
                title={step.label}
                className={[
                  'w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold border-2 transition-all shrink-0',
                  isDone   ? 'bg-indigo-600 border-indigo-600 text-white cursor-pointer hover:bg-indigo-700'   : '',
                  isActive ? 'bg-indigo-600 border-indigo-600 text-white cursor-default ring-4 ring-indigo-100' : '',
                  isFuture && !isDone && !isActive ? 'bg-white border-gray-300 text-gray-400 cursor-pointer hover:border-indigo-400 hover:text-indigo-500' : '',
                ].filter(Boolean).join(' ')}
              >
                {isDone ? (
                  <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
                  </svg>
                ) : step.num}
              </button>

              <div className="flex-1 flex flex-col items-center">
                <div className={[
                  'w-full h-0.5 transition-colors',
                  idx < MAIN_STEPS.length - 1 ? (isDone || isActive ? 'bg-indigo-400' : 'bg-gray-200') : 'bg-transparent',
                ].join(' ')} />
                <span className={[
                  'text-[10px] font-semibold mt-1 whitespace-nowrap',
                  isActive ? 'text-indigo-600' : isDone ? 'text-indigo-400' : 'text-gray-400',
                ].join(' ')}>
                  {step.label}
                </span>
              </div>
            </div>
          );
        })}
      </div>

      {/* Scheduled status pills */}
      <div className="border-t border-gray-50 pt-3 mb-3">
        <p className="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-2">Schedule</p>
        <div className="flex flex-wrap gap-2">
          {SCHEDULED.map(({ key, label, color, activeColor }) => {
            const isActive = status === key;
            const isDisabled = !canEdit || saving || isActive;
            return (
              <button
                key={key}
                onClick={() => !isDisabled && onStatusChange(key)}
                disabled={isDisabled}
                className={[
                  'px-3 py-1 rounded-full text-xs font-semibold border transition-all',
                  isActive ? activeColor : color,
                  !isActive && !saving ? 'cursor-pointer' : 'cursor-default opacity-70',
                ].join(' ')}
              >
                {label}
                {isActive && ' ✓'}
              </button>
            );
          })}
        </div>
      </div>

      {/* Terminal status pills */}
      <div className="border-t border-gray-50 pt-3">
        <p className="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-2">Close As</p>
        <div className="flex flex-wrap gap-2">
          {TERMINAL.map(({ key, label, color, activeColor }) => {
            const isActive = status === key;
            const isDisabled = !canEdit || saving || isActive;
            return (
              <button
                key={key}
                onClick={() => !isDisabled && onStatusChange(key)}
                disabled={isDisabled}
                className={[
                  'px-3 py-1 rounded-full text-xs font-semibold border transition-all',
                  isActive ? activeColor : color,
                  !isActive && !saving ? 'cursor-pointer' : 'cursor-default opacity-70',
                ].join(' ')}
              >
                {label}
                {isActive && ' ✓'}
              </button>
            );
          })}
        </div>
      </div>

      {!isMainStep(status) && (
        <p className="mt-3 text-xs text-gray-500">
          Current: <span className="font-semibold text-gray-700">{LEAD_STATUS_LABELS[status]}</span>
        </p>
      )}
    </div>
  );
}
