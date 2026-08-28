'use client';

import { useState } from 'react';
import { Modal } from '@/components/ui/Modal';
import type { Lead } from '@/types';
import { LEAD_STATUS_LABELS, type RemarkLeadStatus } from '@/lib/leadStatuses';

interface Props {
  lead:      Lead;
  status:    RemarkLeadStatus;
  onClose:   () => void;
  onConfirm: (remark: string) => void;
  saving?:   boolean;
}

export function RemarkStatusModal({ lead, status, onClose, onConfirm, saving }: Props) {
  const [remark, setRemark] = useState('');
  const inputCls = 'w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-shadow bg-white';
  const label = LEAD_STATUS_LABELS[status];

  return (
    <Modal open onClose={onClose} title={`Set ${label} — ${lead.full_name}`} maxWidth="max-w-sm">
      <div className="space-y-4">
        <p className="text-sm text-gray-500">
          Add a remark for this status change. It will be saved as the activity description.
        </p>
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">
            Remark <span className="text-gray-400 font-normal">(optional)</span>
          </label>
          <textarea
            value={remark}
            onChange={(e) => setRemark(e.target.value)}
            rows={4}
            autoFocus
            placeholder={
              status === 'ringing'
                ? 'e.g. Called, no answer — try again evening…'
                : 'e.g. High-intent lead, follow closely…'
            }
            className={`${inputCls} resize-none`}
          />
          <p className="text-[11px] text-gray-400 mt-1">Stored in activities.description</p>
        </div>
        <div className="flex gap-3 pt-1">
          <button
            onClick={onClose}
            className="flex-1 border border-gray-200 text-gray-700 font-semibold py-2.5 rounded-xl text-sm hover:bg-gray-50 transition-colors"
          >
            Cancel
          </button>
          <button
            onClick={() => onConfirm(remark.trim())}
            disabled={saving}
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
