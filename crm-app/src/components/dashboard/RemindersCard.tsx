'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useQueryClient } from '@tanstack/react-query';
import { activitiesApi } from '@/lib/api/activities';
import type { Activity } from '@/types';

interface Props {
  reminders:   Activity[];
  queryKey:    unknown[];   // pass the dashboard query key so we can invalidate
  canManage:   boolean;
}

const TYPE_ICONS: Record<string, string> = {
  call: '📞', email: '✉️', meeting: '🤝', task: '✓', note: '📝', deadline: '🚩',
};

function classifyDue(dueAt?: string): 'overdue' | 'today' | 'upcoming' | null {
  if (!dueAt) return null;
  const d    = new Date(dueAt);
  const now  = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const tomorrow = new Date(today); tomorrow.setDate(today.getDate() + 1);
  if (d < now)       return 'overdue';
  if (d < tomorrow)  return 'today';
  return 'upcoming';
}

function formatDue(dueAt: string): string {
  const d = new Date(dueAt);
  return d.toLocaleString('en-IN', {
    day: 'numeric', month: 'short',
    hour: '2-digit', minute: '2-digit',
  });
}

export function RemindersCard({ reminders, queryKey, canManage }: Props) {
  const qc = useQueryClient();
  const [marking, setMarking] = useState<number | null>(null);

  const handleMarkDone = async (id: number) => {
    setMarking(id);
    try {
      await activitiesApi.markDone(id);
      qc.invalidateQueries({ queryKey });
      qc.invalidateQueries({ queryKey: ['activities'] });
      qc.invalidateQueries({ queryKey: ['follow-ups'] });
    } finally {
      setMarking(null);
    }
  };

  const overdueCount = reminders.filter(r => classifyDue(r.due_at) === 'overdue').length;

  return (
    <div className="bg-white rounded-2xl border border-amber-200 shadow-sm overflow-hidden">
      {/* Header */}
      <div className="px-5 py-4 border-b border-amber-100 flex items-center justify-between bg-amber-50/60">
        <div className="flex items-center gap-2.5">
          <div className="w-8 h-8 rounded-xl bg-amber-100 flex items-center justify-center">
            <svg className="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <div>
            <h2 className="font-semibold text-gray-900 text-sm">
              Upcoming Reminders
              {canManage ? '' : ' — Mine'}
            </h2>
            <p className="text-[11px] text-amber-600">
              {overdueCount > 0
                ? `${overdueCount} overdue · ${reminders.length} total`
                : `${reminders.length} due within 7 days`}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          {reminders.length > 0 && (
            <span className={`px-2 py-0.5 rounded-full text-xs font-bold ${
              overdueCount > 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'
            }`}>
              {reminders.length}
            </span>
          )}
          <Link href="/follow-ups" className="text-xs text-indigo-600 hover:text-indigo-700 font-medium transition-colors">
            View all →
          </Link>
        </div>
      </div>

      {/* List */}
      {reminders.length === 0 ? (
        <div className="px-5 py-8 text-center">
          <div className="w-10 h-10 bg-emerald-50 rounded-full flex items-center justify-center mx-auto mb-2">
            <svg className="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
            </svg>
          </div>
          <p className="text-sm font-medium text-gray-700">All caught up!</p>
          <p className="text-xs text-gray-400 mt-0.5">No pending reminders in the next 7 days.</p>
        </div>
      ) : (
        <div className="divide-y divide-gray-50 max-h-[320px] overflow-y-auto">
          {reminders.map((rem) => {
            const status = classifyDue(rem.due_at);
            const rowBg =
              status === 'overdue'  ? 'bg-red-50/60 hover:bg-red-50' :
              status === 'today'    ? 'bg-amber-50/50 hover:bg-amber-50' :
                                     'hover:bg-gray-50/60';
            return (
              <div key={rem.id} className={`px-5 py-3 flex items-center gap-3 transition-colors ${rowBg}`}>
                {/* Type icon */}
                <div className={`w-8 h-8 rounded-xl flex items-center justify-center shrink-0 text-sm ${
                  status === 'overdue' ? 'bg-red-100 text-red-700' :
                  status === 'today'   ? 'bg-amber-100 text-amber-700' :
                                        'bg-gray-100 text-gray-600'
                }`}>
                  {TYPE_ICONS[rem.type] ?? '•'}
                </div>

                {/* Info */}
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-semibold text-gray-900 truncate">{rem.title}</p>
                  <div className="flex items-center gap-2 mt-0.5">
                    {rem.due_at && (
                      <span className={`text-[11px] font-medium ${
                        status === 'overdue' ? 'text-red-600' :
                        status === 'today'   ? 'text-amber-600' :
                                              'text-gray-400'
                      }`}>
                        {status === 'overdue' ? '⚠ ' : status === 'today' ? '⏰ ' : ''}
                        {formatDue(rem.due_at)}
                      </span>
                    )}
                    {rem.assigned_to && canManage && (
                      <span className="text-[11px] text-gray-400 truncate">
                        · {rem.assigned_to.name}
                      </span>
                    )}
                  </div>
                </div>

                {/* Mark done */}
                <button
                  onClick={() => handleMarkDone(rem.id)}
                  disabled={marking === rem.id}
                  title="Mark as done"
                  className={`shrink-0 w-7 h-7 rounded-lg border flex items-center justify-center transition-all ${
                    marking === rem.id
                      ? 'bg-gray-100 border-gray-200 opacity-60'
                      : 'border-gray-200 hover:border-emerald-400 hover:bg-emerald-50 hover:text-emerald-600 text-gray-400'
                  }`}
                >
                  {marking === rem.id ? (
                    <svg className="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                  ) : (
                    <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 13l4 4L19 7" />
                    </svg>
                  )}
                </button>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
