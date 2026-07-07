'use client';

import { useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { activitiesApi, type ActivityPayload } from '@/lib/api/activities';
import { notesApi } from '@/lib/api/notes';
import { emailTemplatesApi } from '@/lib/api/emailTemplates';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useAuthStore } from '@/store/authStore';
import type { Lead } from '@/types';

type FormType = 'call' | 'email' | 'meeting' | 'task' | 'note' | null;

interface Props {
  leadId:   number;
  leadName: string;
  lead?:    Pick<Lead, 'full_name' | 'company'>;
}

// ── Template variable substitution ───────────────────────────────────────────
function renderTemplate(tpl: string, leadName: string, repName: string, company: string): string {
  return tpl
    .replace(/\{\{lead_name\}\}/g, leadName)
    .replace(/\{\{rep_name\}\}/g, repName)
    .replace(/\{\{company\}\}/g, company);
}

const inputCls = 'w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white';
const labelCls = 'block text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1';

const BUTTONS: { type: FormType; icon: string; label: string; color: string }[] = [
  { type: 'call',    icon: '📞', label: 'Call',    color: 'hover:bg-blue-50   hover:text-blue-700   hover:border-blue-200'   },
  { type: 'email',   icon: '✉️',  label: 'Email',   color: 'hover:bg-violet-50 hover:text-violet-700 hover:border-violet-200' },
  { type: 'meeting', icon: '🤝', label: 'Meeting', color: 'hover:bg-emerald-50 hover:text-emerald-700 hover:border-emerald-200' },
  { type: 'task',    icon: '✓',  label: 'Task',    color: 'hover:bg-amber-50  hover:text-amber-700  hover:border-amber-200'  },
  { type: 'note',    icon: '📝', label: 'Note',    color: 'hover:bg-yellow-50 hover:text-yellow-700 hover:border-yellow-200' },
];

function toLocal(d: Date): string {
  const offset = d.getTimezoneOffset();
  return new Date(d.getTime() - offset * 60_000).toISOString().slice(0, 16);
}

function nowDatetimeLocal() { return toLocal(new Date()); }

function tomorrowDatetimeLocal() {
  const d = new Date(); d.setDate(d.getDate() + 1); d.setHours(10, 0, 0, 0);
  return toLocal(d);
}

const TIME_SHORTCUTS: { label: string; calc: () => string }[] = [
  { label: '+1h',      calc: () => { const d = new Date(); d.setHours(d.getHours() + 1); return toLocal(d); } },
  { label: '+3h',      calc: () => { const d = new Date(); d.setHours(d.getHours() + 3); return toLocal(d); } },
  { label: 'Tmr 9am',  calc: () => { const d = new Date(); d.setDate(d.getDate() + 1); d.setHours(9, 0, 0, 0); return toLocal(d); } },
  { label: '+2 days',  calc: () => { const d = new Date(); d.setDate(d.getDate() + 2); d.setHours(9, 0, 0, 0); return toLocal(d); } },
];

export function QuickLogForm({ leadId, leadName, lead }: Props) {
  const qc = useQueryClient();
  const { user } = useAuthStore();
  const [activeForm, setActiveForm] = useState<FormType>(null);
  const [submitting, setSubmitting] = useState(false);

  // Fetch email templates (lazy — only when email form is opened)
  const { data: emailTemplates } = useQuery({
    queryKey: ['email-templates'],
    queryFn:  emailTemplatesApi.list,
    enabled:  activeForm === 'email',
    staleTime: 5 * 60_000,
  });

  // Activity forms state
  const [title, setTitle]             = useState('');
  const [description, setDescription] = useState('');
  const [dueAt, setDueAt]             = useState('');
  const [priority, setPriority]       = useState<'low' | 'medium' | 'high'>('medium');
  const [markDone, setMarkDone]       = useState(false);

  // Note form state
  const [noteContent, setNoteContent] = useState('');
  const [notePinned, setNotePinned]   = useState(false);

  const openForm = (type: FormType) => {
    if (activeForm === type) { setActiveForm(null); return; }
    setTitle(type === 'call' ? `Call with ${leadName}` : type === 'email' ? `Email to ${leadName}` : '');
    setDescription('');
    setDueAt(type === 'call' || type === 'email' ? nowDatetimeLocal() : tomorrowDatetimeLocal());
    setPriority('medium');
    setMarkDone(false);
    setNoteContent('');
    setNotePinned(false);
    setActiveForm(type);
  };

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['lead-activities', leadId] });
    qc.invalidateQueries({ queryKey: ['lead-notes',      leadId] });
    qc.invalidateQueries({ queryKey: ['lead-timeline',   leadId] });
  };

  const submitActivity = async (type: Exclude<FormType, 'note' | null>) => {
    if (!title.trim()) return;
    setSubmitting(true);
    try {
      const payload: ActivityPayload = {
        subject_type: 'lead',
        subject_id:   leadId,
        type,
        title:        title.trim(),
        description:  description.trim() || undefined,
        due_at:       dueAt || undefined,
        priority,
      };
      const activity = await activitiesApi.create(payload);
      if (markDone) await activitiesApi.markDone(activity.id);
      invalidate();
      setActiveForm(null);
    } finally {
      setSubmitting(false);
    }
  };

  const submitNote = async () => {
    if (!noteContent.trim()) return;
    setSubmitting(true);
    try {
      await notesApi.create({
        notable_type: 'lead',
        notable_id:   leadId,
        content:      noteContent.trim(),
        is_pinned:    notePinned,
      });
      invalidate();
      setActiveForm(null);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="mb-4">
      {/* Quick-log button bar */}
      <div className="flex items-center gap-2 flex-wrap">
        <span className="text-[11px] font-semibold text-gray-400 uppercase tracking-wide mr-1">Log:</span>
        {BUTTONS.map(({ type, icon, label, color }) => (
          <button
            key={type}
            onClick={() => openForm(type)}
            className={[
              'flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-xl border transition-all',
              activeForm === type
                ? 'bg-indigo-600 text-white border-indigo-600'
                : `border-gray-200 text-gray-600 bg-white ${color}`,
            ].join(' ')}
          >
            <span>{icon}</span> {label}
          </button>
        ))}
      </div>

      {/* Collapsible form */}
      <AnimatePresence>
        {activeForm && (
          <motion.div
            key={activeForm}
            initial={{ height: 0, opacity: 0 }}
            animate={{ height: 'auto', opacity: 1 }}
            exit={{ height: 0, opacity: 0 }}
            transition={{ duration: 0.2, ease: 'easeInOut' }}
            className="overflow-hidden"
          >
            <div className="mt-3 bg-gray-50 border border-gray-200 rounded-2xl p-4 space-y-3">
              {activeForm === 'note' ? (
                <>
                  <label className={labelCls}>Note Content *</label>
                  <textarea
                    rows={3}
                    value={noteContent}
                    onChange={(e) => setNoteContent(e.target.value)}
                    placeholder="Type your note..."
                    className={`${inputCls} resize-none`}
                  />
                  <label className="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={notePinned}
                      onChange={(e) => setNotePinned(e.target.checked)}
                      className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                    />
                    📌 Pin this note
                  </label>
                </>
              ) : (
                <>
                  {/* Email template picker — only in Email form */}
                  {activeForm === 'email' && emailTemplates && emailTemplates.length > 0 && (
                    <div>
                      <label className={labelCls}>Use Template</label>
                      <select
                        defaultValue=""
                        onChange={(e) => {
                          const tpl = emailTemplates.find((t) => String(t.id) === e.target.value);
                          if (!tpl) return;
                          const repName = user?.name ?? '';
                          const company = lead?.company ?? '';
                          setTitle(renderTemplate(tpl.subject, leadName, repName, company));
                          setDescription(renderTemplate(tpl.body, leadName, repName, company));
                        }}
                        className={inputCls}
                      >
                        <option value="">— select a template —</option>
                        {emailTemplates.map((tpl) => (
                          <option key={tpl.id} value={String(tpl.id)}>
                            {tpl.name}{tpl.stage_trigger ? ` (${tpl.stage_trigger})` : ''}
                          </option>
                        ))}
                      </select>
                      <p className="text-[10px] text-gray-400 mt-1">Variables auto-filled: subject &amp; body remain editable</p>
                    </div>
                  )}

                  <div>
                    <label className={labelCls}>{activeForm === 'email' ? 'Subject *' : 'Title *'}</label>
                    <input
                      value={title}
                      onChange={(e) => setTitle(e.target.value)}
                      placeholder={activeForm === 'email' ? 'Email subject' : 'Activity title'}
                      className={inputCls}
                    />
                  </div>
                  <div>
                    <label className={labelCls}>
                      {activeForm === 'call' ? 'Call Outcome' : activeForm === 'email' ? 'Email Body' : 'Description'}
                    </label>
                    <textarea
                      rows={activeForm === 'email' ? 4 : 2}
                      value={description}
                      onChange={(e) => setDescription(e.target.value)}
                      placeholder={
                        activeForm === 'call'  ? 'What was discussed?' :
                        activeForm === 'email' ? 'Email body / summary...' :
                        'Optional details...'
                      }
                      className={`${inputCls} resize-none`}
                    />
                  </div>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className={labelCls}>
                        {activeForm === 'call' || activeForm === 'email' ? 'Date & Time' : 'Due At'}
                      </label>
                      {/* Quick time shortcuts */}
                      <div className="flex gap-1.5 flex-wrap mb-1.5">
                        {TIME_SHORTCUTS.map(({ label, calc }) => {
                          const val = calc();
                          return (
                            <button
                              key={label}
                              type="button"
                              onClick={() => setDueAt(val)}
                              className={`px-2 py-0.5 text-[10px] font-semibold rounded-lg border transition-all ${
                                dueAt === val
                                  ? 'bg-indigo-600 text-white border-indigo-600'
                                  : 'bg-white text-gray-500 border-gray-200 hover:border-indigo-300 hover:text-indigo-600'
                              }`}
                            >
                              {label}
                            </button>
                          );
                        })}
                      </div>
                      <input
                        type="datetime-local"
                        value={dueAt}
                        onChange={(e) => setDueAt(e.target.value)}
                        className={inputCls}
                      />
                    </div>
                    {(activeForm === 'meeting' || activeForm === 'task') && (
                      <div>
                        <label className={labelCls}>Priority</label>
                        <select value={priority} onChange={(e) => setPriority(e.target.value as 'low' | 'medium' | 'high')} className={inputCls}>
                          <option value="low">Low</option>
                          <option value="medium">Medium</option>
                          <option value="high">High</option>
                        </select>
                      </div>
                    )}
                  </div>
                  {(activeForm === 'call' || activeForm === 'email') && (
                    <label className="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                      <input
                        type="checkbox"
                        checked={markDone}
                        onChange={(e) => setMarkDone(e.target.checked)}
                        className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                      />
                      Mark as completed now
                    </label>
                  )}
                </>
              )}

              <div className="flex items-center gap-2 pt-1">
                <button
                  onClick={() => activeForm === 'note' ? submitNote() : submitActivity(activeForm as Exclude<FormType, 'note' | null>)}
                  disabled={submitting}
                  className="px-4 py-1.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white text-xs font-semibold rounded-xl transition-colors"
                >
                  {submitting ? 'Saving…' : `Save ${activeForm === 'note' ? 'Note' : activeForm === 'call' ? 'Call' : activeForm === 'email' ? 'Email' : activeForm === 'meeting' ? 'Meeting' : 'Task'}`}
                </button>
                <button
                  onClick={() => setActiveForm(null)}
                  className="px-4 py-1.5 text-xs text-gray-500 hover:text-gray-700 border border-gray-200 rounded-xl transition-colors"
                >
                  Cancel
                </button>
              </div>
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}
