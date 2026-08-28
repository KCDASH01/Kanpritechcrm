'use client';

import { useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { useQueryClient } from '@tanstack/react-query';
import { activitiesApi } from '@/lib/api/activities';
import { notesApi } from '@/lib/api/notes';
import { TIMELINE_CONFIG, relativeTime } from '@/lib/timeline';
import { QuickLogForm } from './QuickLogForm';
import type { Activity, Note } from '@/types';
import type { LeadTimelineEntry } from '@/lib/api/leads';

interface Props {
  leadId:     number;
  leadName:   string;
  activities: Activity[];
  notes:      Note[];
  timeline:   LeadTimelineEntry[];
}

// ── Feed types ────────────────────────────────────────────────────────────────
type FeedItem =
  | { kind: 'activity'; data: Activity;         sortKey: string }
  | { kind: 'note';     data: Note;             sortKey: string }
  | { kind: 'timeline'; data: LeadTimelineEntry; sortKey: string };

// ── Helpers ───────────────────────────────────────────────────────────────────
const ACTIVITY_ICONS: Record<string, string> = {
  call: '📞', email: '✉️', meeting: '🤝', task: '✓', note: '📝', deadline: '⏰', whatsapp: '💬',
};

const PRIORITY_BADGE: Record<string, string> = {
  high:   'bg-red-100 text-red-700',
  medium: 'bg-amber-100 text-amber-700',
  low:    'bg-gray-100 text-gray-500',
};

function formatDate(iso: string) {
  return new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
}

function formatDateTime(iso: string) {
  const d = new Date(iso);
  return d.toLocaleString('en-IN', {
    day: 'numeric', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
}

// ── Timeline connector ────────────────────────────────────────────────────────
function FeedTimelineRow({
  icon,
  iconBg,
  isLast,
  children,
}: {
  icon: React.ReactNode;
  iconBg: string;
  isLast: boolean;
  children: React.ReactNode;
}) {
  return (
    <div className="flex gap-3">
      <div className="flex flex-col items-center shrink-0 w-9">
        <div className={`w-8 h-8 rounded-full ${iconBg} flex items-center justify-center text-sm shadow-sm border-2 border-white z-10`}>
          {icon}
        </div>
        {!isLast && (
          <div className="flex flex-col items-center flex-1 py-1 min-h-[28px]">
            <div className="w-0.5 flex-1 bg-gradient-to-b from-indigo-200 via-gray-200 to-gray-100 rounded-full" />
            <svg className="w-4 h-4 text-indigo-300 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M19 9l-7 7-7-7" />
            </svg>
          </div>
        )}
      </div>
      <div className="flex-1 min-w-0 pb-4">
        {children}
      </div>
    </div>
  );
}

function isOverdue(iso?: string) {
  if (!iso) return false;
  return new Date(iso) < new Date();
}

// ── Activity card ─────────────────────────────────────────────────────────────
function ActivityCard({ item, leadId }: { item: Activity; leadId: number }) {
  const qc = useQueryClient();
  const [toggling, setToggling] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const handleToggleDone = async () => {
    if (toggling || item.is_done) return;
    setToggling(true);
    try {
      await activitiesApi.markDone(item.id);
      qc.invalidateQueries({ queryKey: ['lead-activities', leadId] });
      qc.invalidateQueries({ queryKey: ['lead-timeline',   leadId] });
    } finally {
      setToggling(false);
    }
  };

  const handleDelete = async () => {
    if (!confirm('Delete this activity?')) return;
    setDeleting(true);
    try {
      await activitiesApi.delete(item.id);
      qc.invalidateQueries({ queryKey: ['lead-activities', leadId] });
    } finally {
      setDeleting(false);
    }
  };

  return (
    <motion.div
      layout
      initial={{ opacity: 0, y: 8 }}
      animate={{ opacity: item.is_done ? 0.55 : 1, y: 0 }}
      className="bg-white border border-gray-100 rounded-2xl p-4 shadow-sm group hover:shadow-md transition-shadow"
    >
      <div className="flex items-start gap-3">
        {/* Checkbox */}
        <button
          onClick={handleToggleDone}
          disabled={toggling || item.is_done}
          title={item.is_done ? 'Done' : 'Mark done'}
          className={[
            'w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0 mt-0.5 transition-all',
            item.is_done ? 'bg-emerald-500 border-emerald-500' : 'border-gray-300 hover:border-indigo-500',
          ].join(' ')}
        >
          {item.is_done && (
            <svg className="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
            </svg>
          )}
        </button>

        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <span className="text-base">{ACTIVITY_ICONS[item.type] ?? '○'}</span>
            <span className={['text-sm font-semibold text-gray-800', item.is_done ? 'line-through text-gray-400' : ''].join(' ')}>
              {item.title}
            </span>
            {item.priority !== 'medium' && (
              <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${PRIORITY_BADGE[item.priority]}`}>
                {item.priority}
              </span>
            )}
          </div>

          {item.description && (
            <p className="text-xs text-gray-500 mt-1 leading-relaxed">{item.description}</p>
          )}

          <div className="flex items-center gap-3 mt-2 text-xs">
            {item.due_at && (
              <span className={['font-medium', !item.is_done && isOverdue(item.due_at) ? 'text-red-600' : 'text-gray-400'].join(' ')}>
                {item.is_done && item.completed_at
                  ? `Completed ${formatDate(item.completed_at)}`
                  : `Due ${formatDate(item.due_at)}`}
              </span>
            )}
            {item.assigned_to && (
              <span className="text-gray-400">
                <span className="text-indigo-300">→</span> {item.assigned_to.name}
              </span>
            )}
          </div>

          <FeedMetaFooter
            userName={item.created_by?.name ?? item.assigned_to?.name}
            createdAt={item.created_at}
          />
        </div>

        {/* Actions */}
        <button
          onClick={handleDelete}
          disabled={deleting}
          className="opacity-0 group-hover:opacity-100 text-gray-300 hover:text-red-500 transition-all p-1 rounded-lg hover:bg-red-50"
          title="Delete"
        >
          <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
          </svg>
        </button>
      </div>
    </motion.div>
  );
}

// ── Note card ─────────────────────────────────────────────────────────────────
function NoteCard({ item, leadId }: { item: Note; leadId: number }) {
  const qc = useQueryClient();
  const [editing, setEditing]   = useState(false);
  const [content, setContent]   = useState(item.content);
  const [saving, setSaving]     = useState(false);
  const [deleting, setDeleting] = useState(false);

  const handleSave = async () => {
    if (!content.trim()) return;
    setSaving(true);
    try {
      await notesApi.update(item.id, { content: content.trim() });
      qc.invalidateQueries({ queryKey: ['lead-notes', leadId] });
      setEditing(false);
    } finally {
      setSaving(false);
    }
  };

  const handleTogglePin = async () => {
    await notesApi.update(item.id, { is_pinned: !item.is_pinned });
    qc.invalidateQueries({ queryKey: ['lead-notes', leadId] });
  };

  const handleDelete = async () => {
    if (!confirm('Delete this note?')) return;
    setDeleting(true);
    try {
      await notesApi.delete(item.id);
      qc.invalidateQueries({ queryKey: ['lead-notes', leadId] });
    } finally {
      setDeleting(false);
    }
  };

  return (
    <motion.div
      layout
      initial={{ opacity: 0, y: 8 }}
      animate={{ opacity: 1, y: 0 }}
      className="bg-amber-50 border border-amber-100 rounded-2xl p-4 group hover:shadow-md transition-shadow"
    >
      <div className="flex items-start gap-3">
        <span className="text-lg shrink-0 mt-0.5">📝</span>
        <div className="flex-1 min-w-0">
          {editing ? (
            <div className="space-y-2">
              <textarea
                rows={3}
                value={content}
                onChange={(e) => setContent(e.target.value)}
                className="w-full border border-amber-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400 bg-white resize-none"
              />
              <div className="flex gap-2">
                <button
                  onClick={handleSave}
                  disabled={saving}
                  className="px-3 py-1 bg-amber-500 hover:bg-amber-600 text-white text-xs font-semibold rounded-lg"
                >
                  {saving ? 'Saving…' : 'Save'}
                </button>
                <button onClick={() => { setContent(item.content); setEditing(false); }} className="px-3 py-1 text-xs text-gray-500 border border-gray-200 rounded-lg">
                  Cancel
                </button>
              </div>
            </div>
          ) : (
            <p className="text-sm text-gray-700 leading-relaxed whitespace-pre-line">{item.content}</p>
          )}

          <FeedMetaFooter
            userName={item.created_by?.name}
            createdAt={item.created_at}
          />
          {item.is_pinned && <span className="inline-block mt-1 text-xs text-amber-600 font-semibold">📌 Pinned</span>}
        </div>

        {/* Actions */}
        <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
          <button onClick={handleTogglePin} title={item.is_pinned ? 'Unpin' : 'Pin'} className="p-1 rounded-lg hover:bg-amber-100 text-amber-500">
            <span className="text-sm">{item.is_pinned ? '📌' : '📍'}</span>
          </button>
          {!editing && (
            <button onClick={() => setEditing(true)} title="Edit" className="p-1 rounded-lg hover:bg-amber-100 text-amber-600">
              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
              </svg>
            </button>
          )}
          <button onClick={handleDelete} disabled={deleting} title="Delete" className="p-1 rounded-lg hover:bg-red-50 text-gray-300 hover:text-red-500">
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
          </button>
        </div>
      </div>
    </motion.div>
  );
}

// ── Timeline entry ────────────────────────────────────────────────────────────
function FeedMetaFooter({ userName, createdAt }: { userName?: string | null; createdAt: string }) {
  return (
    <div className="flex items-center flex-wrap gap-x-2 gap-y-0.5 mt-2.5 pt-2.5 border-t border-gray-100 text-xs text-gray-400">
      {userName && (
        <>
          <span className="font-medium text-gray-500">{userName}</span>
          <span className="text-indigo-300">→</span>
        </>
      )}
      <span>{formatDateTime(createdAt)}</span>
      <span className="text-indigo-300">→</span>
      <span className="text-gray-500">{relativeTime(createdAt)}</span>
    </div>
  );
}

function TimelineCard({ item }: { item: LeadTimelineEntry }) {
  return (
    <motion.div
      layout
      initial={{ opacity: 0, y: 8 }}
      animate={{ opacity: 1, y: 0 }}
      className="bg-slate-50 border border-slate-100 rounded-2xl p-4 shadow-sm hover:shadow-md transition-shadow"
    >
      <p className="text-sm font-medium text-gray-800 leading-relaxed">{item.description}</p>

      {item.action === 'updated' && item.meta && (
        <div className="mt-2.5 bg-white rounded-xl px-3 py-2 space-y-1 border border-gray-100">
          {Object.entries(item.meta).map(([field, diff]: [string, unknown]) => {
            const d = diff as { from: string; to: string };
            return (
              <p key={field} className="text-xs text-gray-600">
                <span className="font-semibold capitalize">{field.replace(/_/g, ' ')}</span>
                <span className="text-indigo-300 mx-1.5">→</span>
                <span className="line-through text-red-400">{d.from || '—'}</span>
                <span className="text-indigo-300 mx-1.5">→</span>
                <span className="text-emerald-600 font-medium">{d.to || '—'}</span>
              </p>
            );
          })}
        </div>
      )}

      <FeedMetaFooter userName={item.user?.name} createdAt={item.created_at} />
    </motion.div>
  );
}

// ── Main feed ─────────────────────────────────────────────────────────────────
export function LeadActivityFeed({ leadId, leadName, activities, notes, timeline }: Props) {
  // Build merged + sorted feed
  const feed: FeedItem[] = [
    ...activities.map((a) => ({
      kind:    'activity' as const,
      data:    a,
      sortKey: a.created_at,
    })),
    ...notes.map((n) => ({
      kind:    'note' as const,
      data:    n,
      // Pinned notes float to top
      sortKey: n.is_pinned ? `9999-${n.created_at}` : n.created_at,
    })),
    ...timeline.map((t) => ({
      kind:    'timeline' as const,
      data:    t,
      sortKey: t.created_at,
    })),
  ].sort((a, b) => b.sortKey.localeCompare(a.sortKey));

  return (
    <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
      <div className="px-5 pt-5 pb-4 border-b border-gray-50">
        <h3 className="text-sm font-semibold text-gray-900 mb-3">Activity Feed</h3>
        <QuickLogForm leadId={leadId} leadName={leadName} />
      </div>

      <div className="px-5 py-4 max-h-[680px] overflow-y-auto">
        {feed.length === 0 ? (
          <div className="py-12 text-center">
            <div className="text-4xl mb-2">📋</div>
            <p className="text-sm text-gray-400">No activity yet. Log the first interaction above.</p>
          </div>
        ) : (
          <AnimatePresence>
            {feed.map((item, index) => {
              const isLast = index === feed.length - 1;

              if (item.kind === 'activity') {
                const act = item.data;
                return (
                  <FeedTimelineRow
                    key={`a-${act.id}`}
                    icon={<span>{ACTIVITY_ICONS[act.type] ?? '○'}</span>}
                    iconBg="bg-indigo-100"
                    isLast={isLast}
                  >
                    <ActivityCard item={act} leadId={leadId} />
                  </FeedTimelineRow>
                );
              }

              if (item.kind === 'note') {
                const note = item.data;
                return (
                  <FeedTimelineRow
                    key={`n-${note.id}`}
                    icon={<span>📝</span>}
                    iconBg="bg-amber-100"
                    isLast={isLast}
                  >
                    <NoteCard item={note} leadId={leadId} />
                  </FeedTimelineRow>
                );
              }

              const entry = item.data;
              const cfg = TIMELINE_CONFIG[entry.action] ?? { icon: '●', bg: 'bg-gray-100' };
              return (
                <FeedTimelineRow
                  key={`t-${entry.id}`}
                  icon={<span className="text-xs">{cfg.icon}</span>}
                  iconBg={cfg.bg}
                  isLast={isLast}
                >
                  <TimelineCard item={entry} />
                </FeedTimelineRow>
              );
            })}
          </AnimatePresence>
        )}
      </div>
    </div>
  );
}
