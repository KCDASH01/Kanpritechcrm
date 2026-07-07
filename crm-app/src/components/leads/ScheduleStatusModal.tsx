'use client';

import { useState } from 'react';
import { Modal } from '@/components/ui/Modal';
import type { Lead } from '@/types';
import { LEAD_STATUS_LABELS, type ScheduledLeadStatus } from '@/lib/leadStatuses';

interface Props {
  lead:      Lead;
  status:    ScheduledLeadStatus;
  onClose:   () => void;
  onConfirm: (scheduleAt: string) => void;
  saving?:   boolean;
}

function formatConfirm(datetimeLocal: string): string {
  if (!datetimeLocal) return '';
  const d = new Date(datetimeLocal);
  return d.toLocaleString('en-IN', {
    weekday: 'long', day: 'numeric', month: 'long',
    hour: '2-digit', minute: '2-digit',
  });
}

export function ScheduleStatusModal({ lead, status, onClose, onConfirm, saving }: Props) {
  const [scheduleAt, setScheduleAt] = useState('');
  const inputCls = 'w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-shadow bg-white';
  const label = LEAD_STATUS_LABELS[status];

  return (
    <Modal open onClose={onClose} title={`Set ${label} — ${lead.full_name}`} maxWidth="max-w-sm">
      <div className="space-y-4">
        <p className="text-sm text-gray-500">
          Choose when to {status === 'meeting' ? 'meet with' : 'follow up with'} this lead.
          An activity will be created and the lead status will be updated.
        </p>
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Date &amp; time</label>
          <input
            type="datetime-local"
            value={scheduleAt}
            onChange={(e) => setScheduleAt(e.target.value)}
            className={inputCls}
          />
        </div>
        {scheduleAt && (
          <p className="text-sm text-indigo-700 bg-indigo-50 rounded-xl px-3 py-2.5 font-medium">
            Scheduled for {formatConfirm(scheduleAt)}
          </p>
        )}
        <div className="flex gap-3 pt-1">
          <button
            onClick={onClose}
            className="flex-1 border border-gray-200 text-gray-700 font-semibold py-2.5 rounded-xl text-sm hover:bg-gray-50 transition-colors"
          >
            Cancel
          </button>
          <button
            onClick={() => onConfirm(scheduleAt)}
            disabled={!scheduleAt || saving}
            className="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white font-semibold py-2.5 rounded-xl text-sm flex items-center justify-center gap-2 transition-colors"
          >
            {saving && (
              <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
            )}
            Save {label}
          </button>
        </div>
      </div>
    </Modal>
  );
}
