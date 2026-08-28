'use client';

import { useState } from 'react';
import { Modal } from '@/components/ui/Modal';
import type { Lead } from '@/types';
import { LEAD_STATUS_LABELS, type ScheduledLeadStatus } from '@/lib/leadStatuses';

export interface ScheduleStatusPayload {
  scheduleAt: string;
  remark?:    string;
}

interface Props {
  lead:      Lead;
  status:    ScheduledLeadStatus;
  onClose:   () => void;
  onConfirm: (payload: ScheduleStatusPayload) => void;
  saving?:   boolean;
  /** True when lead already has this status — updating timing */
  reschedule?: boolean;
}

function formatConfirm(datetimeLocal: string): string {
  if (!datetimeLocal) return '';
  const d = new Date(datetimeLocal);
  return d.toLocaleString('en-IN', {
    weekday: 'long', day: 'numeric', month: 'long',
    hour: '2-digit', minute: '2-digit',
  });
}

export function ScheduleStatusModal({ lead, status, onClose, onConfirm, saving, reschedule }: Props) {
  const [scheduleAt, setScheduleAt] = useState('');
  const [remark, setRemark]         = useState('');
  const inputCls = 'w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-shadow bg-white';
  const label = LEAD_STATUS_LABELS[status];
  const isReschedule = reschedule ?? lead.status === status;

  const handleConfirm = () => {
    if (!scheduleAt) return;
    onConfirm({
      scheduleAt,
      remark: remark.trim() || undefined,
    });
  };

  return (
    <Modal open onClose={onClose} title={`${isReschedule ? 'Reschedule' : 'Set'} ${label} — ${lead.full_name}`} maxWidth="max-w-sm">
      <div className="space-y-4">
        <p className="text-sm text-gray-500">
          {isReschedule
            ? `Update the date & time for this ${status === 'meeting' ? 'meeting' : 'follow-up'}. The existing schedule will be updated.`
            : `Choose when to ${status === 'meeting' ? 'meet with' : 'follow up with'} this lead. An activity will be created and the lead status will be updated.`}
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
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Remark</label>
          <textarea
            value={remark}
            onChange={(e) => setRemark(e.target.value)}
            rows={3}
            placeholder={`Add notes for this ${status === 'meeting' ? 'meeting' : 'follow-up'}…`}
            className={`${inputCls} resize-none`}
          />
          <p className="text-[11px] text-gray-400 mt-1">Saved as the activity description.</p>
        </div>
        {scheduleAt && (
          <p className="text-sm text-indigo-700 bg-indigo-50 rounded-xl px-3 py-2.5 font-medium">
            {isReschedule ? 'Updated to' : 'Scheduled for'} {formatConfirm(scheduleAt)}
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
            onClick={handleConfirm}
            disabled={!scheduleAt || saving}
            className="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white font-semibold py-2.5 rounded-xl text-sm flex items-center justify-center gap-2 transition-colors"
          >
            {saving && (
              <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
            )}
            {isReschedule ? 'Update Timing' : `Save ${label}`}
          </button>
        </div>
      </div>
    </Modal>
  );
}
