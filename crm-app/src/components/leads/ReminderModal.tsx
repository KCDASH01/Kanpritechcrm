'use client';

import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { motion } from 'framer-motion';
import { activitiesApi } from '@/lib/api/activities';
import { Modal } from '@/components/ui/Modal';
import type { Lead } from '@/types';

interface Props {
  lead:          Lead;
  currentUserId: number;
  onClose:       () => void;
  onCreated:     () => void;
}

type Preset = '1h' | '3h' | '6h' | 'tmr' | '2d' | '1w' | 'custom';

function calcDueAt(preset: Exclude<Preset, 'custom'>): string {
  const d = new Date();
  if (preset === '1h')  { d.setHours(d.getHours() + 1); }
  if (preset === '3h')  { d.setHours(d.getHours() + 3); }
  if (preset === '6h')  { d.setHours(d.getHours() + 6); }
  if (preset === 'tmr') { d.setDate(d.getDate() + 1); d.setHours(9, 0, 0, 0); }
  if (preset === '2d')  { d.setDate(d.getDate() + 2); d.setHours(9, 0, 0, 0); }
  if (preset === '1w')  { d.setDate(d.getDate() + 7); d.setHours(9, 0, 0, 0); }
  // Convert to datetime-local format (local time)
  const offset = d.getTimezoneOffset();
  const local  = new Date(d.getTime() - offset * 60_000);
  return local.toISOString().slice(0, 16);
}

const PRESETS: { key: Exclude<Preset, 'custom'>; label: string }[] = [
  { key: '1h',  label: 'In 1 hour'     },
  { key: '3h',  label: 'In 3 hours'    },
  { key: '6h',  label: 'In 6 hours'    },
  { key: 'tmr', label: 'Tomorrow 9am'  },
  { key: '2d',  label: 'In 2 days'     },
  { key: '1w',  label: 'In 1 week'     },
];

function formatConfirm(datetimeLocal: string): string {
  if (!datetimeLocal) return '';
  const d = new Date(datetimeLocal);
  return d.toLocaleString('en-IN', {
    weekday: 'long', day: 'numeric', month: 'long',
    hour: '2-digit', minute: '2-digit',
  });
}

export function ReminderModal({ lead, currentUserId, onClose, onCreated }: Props) {
  const [type, setType]           = useState<'call' | 'task'>('call');
  const [preset, setPreset]       = useState<Preset | null>(null);
  const [customDue, setCustomDue] = useState('');
  const [note, setNote]           = useState('');
  const [done, setDone]           = useState(false);

  // Resolved datetime-local value
  const resolvedDueAt =
    preset === 'custom' ? customDue :
    preset             ? calcDueAt(preset) :
    '';

  const mutation = useMutation({
    mutationFn: () =>
      activitiesApi.create({
        subject_type: 'lead',
        subject_id:   lead.id,
        type,
        title:        `Follow up with ${lead.full_name}`,
        description:  note.trim() || undefined,
        due_at:       resolvedDueAt || undefined,
        assigned_to:  lead.assigned_to?.id ?? currentUserId,
        priority:     'medium',
      }),
    onSuccess: () => { onCreated(); },
  });

  const inputCls = 'w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white';

  return (
    <Modal open onClose={onClose} title={`Set Reminder — ${lead.full_name}`} maxWidth="max-w-md">
      <div className="space-y-5">

        {/* Type toggle */}
        <div>
          <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Reminder Type</p>
          <div className="flex gap-2">
            {(['call', 'task'] as const).map((t) => (
              <button
                key={t}
                onClick={() => setType(t)}
                className={`flex-1 flex items-center justify-center gap-1.5 py-2 rounded-xl text-sm font-semibold border transition-all ${
                  type === t
                    ? 'bg-indigo-600 text-white border-indigo-600 shadow-sm'
                    : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'
                }`}
              >
                {t === 'call' ? '📞' : '✓'} {t === 'call' ? 'Call Back' : 'Task'}
              </button>
            ))}
          </div>
        </div>

        {/* Preset pills */}
        <div>
          <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">When?</p>
          <div className="grid grid-cols-3 gap-2">
            {PRESETS.map(({ key, label }) => (
              <button
                key={key}
                onClick={() => setPreset(key)}
                className={`py-2 px-3 rounded-xl text-xs font-semibold border transition-all ${
                  preset === key
                    ? 'bg-indigo-600 text-white border-indigo-600 shadow-sm'
                    : 'bg-white text-gray-600 border-gray-200 hover:bg-indigo-50 hover:text-indigo-700 hover:border-indigo-200'
                }`}
              >
                {label}
              </button>
            ))}
            {/* Custom option */}
            <button
              onClick={() => setPreset('custom')}
              className={`py-2 px-3 rounded-xl text-xs font-semibold border transition-all col-span-3 ${
                preset === 'custom'
                  ? 'bg-indigo-600 text-white border-indigo-600'
                  : 'bg-white text-gray-600 border-gray-200 hover:bg-indigo-50 hover:text-indigo-700 hover:border-indigo-200'
              }`}
            >
              🗓 Custom date & time…
            </button>
          </div>

          {/* Custom datetime input */}
          {preset === 'custom' && (
            <motion.div
              initial={{ height: 0, opacity: 0 }}
              animate={{ height: 'auto', opacity: 1 }}
              exit={{ height: 0, opacity: 0 }}
              transition={{ duration: 0.18 }}
              className="overflow-hidden mt-2"
            >
              <input
                type="datetime-local"
                value={customDue}
                onChange={(e) => setCustomDue(e.target.value)}
                className={inputCls}
              />
            </motion.div>
          )}
        </div>

        {/* Confirmation line */}
        {resolvedDueAt && (
          <motion.div
            initial={{ opacity: 0, y: 4 }}
            animate={{ opacity: 1, y: 0 }}
            className="flex items-center gap-2 px-3 py-2.5 bg-indigo-50 rounded-xl text-sm text-indigo-700 font-medium"
          >
            <svg className="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Reminder set for {formatConfirm(resolvedDueAt)}
          </motion.div>
        )}

        {/* Optional note */}
        <div>
          <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Note <span className="font-normal normal-case">(optional)</span></p>
          <textarea
            rows={2}
            value={note}
            onChange={(e) => setNote(e.target.value)}
            placeholder={`e.g. Client said to call after ${type === 'call' ? 'lunch' : 'the meeting'}…`}
            className={`${inputCls} resize-none`}
          />
        </div>

        {/* Mark done now (for calls) */}
        {type === 'call' && (
          <label className="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
            <input
              type="checkbox"
              checked={done}
              onChange={(e) => setDone(e.target.checked)}
              className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
            />
            Mark as completed immediately after saving
          </label>
        )}

        {/* Submit */}
        <div className="flex gap-3 pt-1">
          <button
            onClick={() => mutation.mutate()}
            disabled={!resolvedDueAt || mutation.isPending}
            className="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-semibold
                       py-2.5 rounded-xl text-sm flex items-center justify-center gap-2 transition-colors"
          >
            {mutation.isPending && (
              <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
              </svg>
            )}
            {mutation.isPending ? 'Setting…' : '⏰ Set Reminder'}
          </button>
          <button
            onClick={onClose}
            className="px-5 text-sm text-gray-500 hover:text-gray-700 border border-gray-200 rounded-xl transition-colors"
          >
            Cancel
          </button>
        </div>

        {mutation.isError && (
          <p className="text-xs text-red-500 text-center">Failed to set reminder. Please try again.</p>
        )}

      </div>
    </Modal>
  );
}
