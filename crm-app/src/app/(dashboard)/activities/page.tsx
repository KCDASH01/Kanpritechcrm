'use client';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import { motion, AnimatePresence } from 'framer-motion';
import { activitiesApi, type ActivityPayload } from '@/lib/api/activities';
import { Modal } from '@/components/ui/Modal';
import { Badge } from '@/components/ui/Badge';
import { SkeletonActivityCard } from '@/components/ui/Skeleton';
import { useAuthStore } from '@/store/authStore';
import type { Activity, PaginatedResponse } from '@/types';

const TYPE_ICONS: Record<string, string> = {
  call:      'M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z',
  email:     'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
  meeting:   'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
  task:      'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
  note:      'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
  deadline:  'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
  whatsapp:  'M8.29 13.29a1 1 0 001.41 0l3-3a1 1 0 10-1.41-1.41L9 11.17l-.29-.3a1 1 0 00-1.42 1.42l1 1zM12 2a10 10 0 100 20A10 10 0 0012 2z',
};

const TYPE_BG: Record<string, string> = {
  call:      'bg-green-100 text-green-700',
  email:     'bg-violet-100 text-violet-700',
  meeting:   'bg-blue-100 text-blue-700',
  task:      'bg-orange-100 text-orange-700',
  note:      'bg-yellow-100 text-yellow-700',
  deadline:  'bg-red-100 text-red-700',
  whatsapp:  'bg-green-100 text-green-700',
};

function ActivityForm({ activity, onClose, onSave, saving }: {
  activity?: Activity | null;
  onClose: () => void;
  onSave: (d: ActivityPayload) => void;
  saving?: boolean;
}) {
  const [form, setForm] = useState<ActivityPayload>({
    subject_type: (activity?.subject_type as 'lead' | 'deal') ?? 'lead',
    subject_id:   activity?.subject_id ?? 0,
    type:         activity?.type ?? 'task',
    title:        activity?.title ?? '',
    description:  activity?.description ?? '',
    due_at:       activity?.due_at ? activity.due_at.substring(0, 16) : '',
    priority:     activity?.priority ?? 'medium',
  });

  const set = (k: keyof ActivityPayload, v: unknown) => setForm((f) => ({ ...f, [k]: v }));

  const inputCls = 'w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-shadow';

  return (
    <div className="space-y-4">
      <div>
        <label className="block text-xs font-semibold text-gray-600 mb-1.5">Title *</label>
        <input
          value={form.title}
          onChange={(e) => set('title', e.target.value)}
          placeholder="e.g. Follow-up call with client"
          className={inputCls}
        />
      </div>

      <div className="grid grid-cols-2 gap-4">
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Type</label>
          <select value={form.type} onChange={(e) => set('type', e.target.value)} className={inputCls}>
            {['call', 'email', 'meeting', 'task', 'note', 'deadline'].map((t) => (
              <option key={t} value={t}>{t.charAt(0).toUpperCase() + t.slice(1)}</option>
            ))}
          </select>
        </div>
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Priority</label>
          <select value={form.priority} onChange={(e) => set('priority', e.target.value)} className={inputCls}>
            {['low', 'medium', 'high'].map((p) => (
              <option key={p} value={p}>{p.charAt(0).toUpperCase() + p.slice(1)}</option>
            ))}
          </select>
        </div>
      </div>

      <div>
        <label className="block text-xs font-semibold text-gray-600 mb-1.5">Due Date & Time</label>
        <input
          type="datetime-local"
          value={form.due_at ?? ''}
          onChange={(e) => set('due_at', e.target.value)}
          className={inputCls}
        />
      </div>

      <div className="grid grid-cols-2 gap-4">
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Subject Type</label>
          <select value={form.subject_type} onChange={(e) => set('subject_type', e.target.value)} className={inputCls}>
            <option value="lead">Lead</option>
            <option value="deal">Deal</option>
          </select>
        </div>
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Subject ID</label>
          <input
            type="number"
            min={1}
            value={form.subject_id || ''}
            onChange={(e) => set('subject_id', Number(e.target.value))}
            placeholder="ID"
            className={inputCls}
          />
        </div>
      </div>

      <div>
        <label className="block text-xs font-semibold text-gray-600 mb-1.5">Description</label>
        <textarea
          rows={3}
          value={form.description ?? ''}
          onChange={(e) => set('description', e.target.value)}
          placeholder="Optional notes…"
          className={`${inputCls} resize-none`}
        />
      </div>

      <div className="flex gap-3 pt-2">
        <button
          onClick={() => onSave(form)}
          disabled={saving || !form.title.trim()}
          className="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white font-semibold
                     py-2.5 rounded-xl text-sm flex items-center justify-center gap-2 transition-colors"
        >
          {saving && (
            <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
          )}
          {activity ? 'Save Changes' : 'Create Activity'}
        </button>
        <button onClick={onClose} className="px-5 text-sm text-gray-500 hover:text-gray-700 border border-gray-200 rounded-xl transition-colors">
          Cancel
        </button>
      </div>
    </div>
  );
}

function EmptyActivities({ onAdd }: { onAdd: () => void }) {
  return (
    <div className="bg-white rounded-2xl border border-gray-100 shadow-sm px-6 py-16 text-center">
      <div className="w-14 h-14 bg-blue-50 rounded-2xl flex items-center justify-center mx-auto mb-4">
        <svg className="w-7 h-7 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
            d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
        </svg>
      </div>
      <p className="text-gray-900 font-semibold text-sm mb-1">No activities yet</p>
      <p className="text-xs text-gray-400 mb-4">Schedule calls, emails, meetings and more</p>
      <button
        onClick={onAdd}
        className="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2 rounded-xl transition-colors"
      >
        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
        </svg>
        Add Activity
      </button>
    </div>
  );
}

export default function ActivitiesPage() {
  const qc = useQueryClient();
  const { user, isOwner, isAdmin } = useAuthStore();
  const canManage = isOwner() || isAdmin();

  const [typeFilter, setTypeFilter] = useState('');
  const [doneFilter, setDoneFilter] = useState<boolean | undefined>(undefined);
  const [page, setPage] = useState(1);
  const [modal, setModal] = useState<Activity | null | undefined>(undefined);
  // owners/admins default to all; team members default to their own
  const [viewMine, setViewMine] = useState(!canManage);

  const assignedToFilter = viewMine ? user?.id : undefined;

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['activities', { type: typeFilter, is_done: doneFilter, page, assigned_to: assignedToFilter }],
    queryFn: () => activitiesApi.list({
      type:        typeFilter || undefined,
      is_done:     doneFilter,
      assigned_to: assignedToFilter,
      page,
      sort_by: 'due_at',
    }),
    placeholderData: keepPreviousData,
  });

  const createMutation = useMutation({
    mutationFn: (p: ActivityPayload) => activitiesApi.create(p),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['activities'] }); setModal(undefined); },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, p }: { id: number; p: ActivityPayload }) => activitiesApi.update(id, p),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['activities'] }); setModal(undefined); },
  });

  // Optimistic mark done
  const doneMutation = useMutation({
    mutationFn: (id: number) => activitiesApi.markDone(id),
    onMutate: async (id) => {
      await qc.cancelQueries({ queryKey: ['activities'] });
      const snapshot = qc.getQueriesData<PaginatedResponse<Activity>>({ queryKey: ['activities'] });
      qc.setQueriesData<PaginatedResponse<Activity>>({ queryKey: ['activities'] }, (old) => {
        if (!old) return old;
        return { ...old, data: old.data.map((a) => a.id === id ? { ...a, is_done: true } : a) };
      });
      return { snapshot };
    },
    onError: (_err, _id, ctx) => {
      if (ctx?.snapshot) {
        ctx.snapshot.forEach(([key, data]) => qc.setQueryData(key, data));
      }
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ['activities'] }),
  });

  // Optimistic delete
  const deleteMutation = useMutation({
    mutationFn: (id: number) => activitiesApi.delete(id),
    onMutate: async (id) => {
      await qc.cancelQueries({ queryKey: ['activities'] });
      const snapshot = qc.getQueriesData<PaginatedResponse<Activity>>({ queryKey: ['activities'] });
      qc.setQueriesData<PaginatedResponse<Activity>>({ queryKey: ['activities'] }, (old) => {
        if (!old) return old;
        return { ...old, data: old.data.filter((a) => a.id !== id) };
      });
      return { snapshot };
    },
    onError: (_err, _id, ctx) => {
      if (ctx?.snapshot) {
        ctx.snapshot.forEach(([key, data]) => qc.setQueryData(key, data));
      }
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ['activities'] }),
  });

  const activities = data?.data ?? [];
  const meta = data?.meta;

  const handleSave = (p: ActivityPayload) => {
    if (modal?.id) {
      updateMutation.mutate({ id: modal.id, p });
    } else {
      createMutation.mutate(p);
    }
  };

  const isSaving = createMutation.isPending || updateMutation.isPending;

  return (
    <div className="space-y-5">
      {/* Header */}
      <div className="flex items-center justify-between gap-4 flex-wrap">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Activities</h1>
          {meta && <p className="text-xs text-gray-400 mt-0.5">{meta.total} total</p>}
        </div>
        <div className="flex items-center gap-2">
          {/* My / All toggle */}
          <div className="flex gap-1 bg-gray-100 rounded-xl p-1">
            <button
              onClick={() => { setViewMine(true); setPage(1); }}
              className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all ${viewMine ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'}`}
            >
              My Activities
            </button>
            {canManage && (
              <button
                onClick={() => { setViewMine(false); setPage(1); }}
                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all ${!viewMine ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'}`}
              >
                All Activities
              </button>
            )}
          </div>
          {canManage && (
            <button
              onClick={() => setModal(null)}
              className="flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2 rounded-xl transition-colors shadow-sm shadow-indigo-500/20"
            >
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
              </svg>
              Add Activity
            </button>
          )}
        </div>
      </div>

      {/* Filters */}
      <div className="flex gap-3 flex-wrap items-center">
        {/* Done filter tabs */}
        <div className="flex gap-1 bg-gray-100 rounded-xl p-1">
          {([undefined, false, true] as const).map((v, i) => (
            <button
              key={i}
              onClick={() => { setDoneFilter(v); setPage(1); }}
              className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all duration-150 ${
                doneFilter === v
                  ? 'bg-white text-gray-900 shadow-sm'
                  : 'text-gray-500 hover:text-gray-700'
              }`}
            >
              {v === undefined ? 'All' : v ? '✓ Done' : '⏳ Pending'}
            </button>
          ))}
        </div>

        {/* Type filter */}
        <select
          value={typeFilter}
          onChange={(e) => { setTypeFilter(e.target.value); setPage(1); }}
          className="border border-gray-200 rounded-xl px-3 py-1.5 text-sm text-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white"
        >
          <option value="">All types</option>
          {['call', 'email', 'meeting', 'task', 'note', 'deadline'].map((t) => (
            <option key={t} value={t}>{t.charAt(0).toUpperCase() + t.slice(1)}</option>
          ))}
        </select>

        {/* Updating indicator */}
        {isFetching && !isLoading && (
          <span className="text-xs text-gray-400 flex items-center gap-1.5">
            <svg className="w-3 h-3 animate-spin text-indigo-400" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            Updating…
          </span>
        )}
      </div>

      {/* Cards */}
      <div className="space-y-3">
        {isLoading ? (
          Array.from({ length: 5 }).map((_, i) => <SkeletonActivityCard key={i} />)
        ) : activities.length === 0 ? (
          <EmptyActivities onAdd={() => setModal(null)} />
        ) : (
          <AnimatePresence initial={false}>
            {activities.map((act, i) => {
              const overdue = act.due_at && !act.is_done && new Date(act.due_at) < new Date();
              const typeBg  = TYPE_BG[act.type] ?? 'bg-gray-100 text-gray-600';
              const typeIcon = TYPE_ICONS[act.type];
              return (
                <motion.div
                  key={act.id}
                  layout
                  initial={{ opacity: 0, y: 10 }}
                  animate={{ opacity: act.is_done ? 0.6 : 1, y: 0 }}
                  exit={{ opacity: 0, x: -20, transition: { duration: 0.2 } }}
                  transition={{ delay: i * 0.04, type: 'spring', damping: 28, stiffness: 350 }}
                  className="group bg-white rounded-2xl border border-gray-100 shadow-sm p-4 flex items-center gap-4
                             hover:shadow-md hover:-translate-y-0.5 transition-all duration-150 cursor-default"
                >
                  {/* Done checkbox */}
                  <motion.button
                    onClick={() => !act.is_done && doneMutation.mutate(act.id)}
                    disabled={act.is_done || doneMutation.isPending}
                    whileTap={{ scale: 0.85 }}
                    className={`w-6 h-6 rounded-full border-2 flex items-center justify-center shrink-0 transition-all duration-200 ${
                      act.is_done
                        ? 'bg-green-500 border-green-500 shadow-sm shadow-green-500/30'
                        : 'border-gray-300 hover:border-indigo-400 hover:bg-indigo-50'
                    }`}
                  >
                    <AnimatePresence>
                      {act.is_done && (
                        <motion.svg
                          initial={{ scale: 0 }}
                          animate={{ scale: 1 }}
                          exit={{ scale: 0 }}
                          transition={{ type: 'spring', stiffness: 500, damping: 25 }}
                          className="w-3 h-3 text-white"
                          fill="none"
                          stroke="currentColor"
                          viewBox="0 0 24 24"
                        >
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
                        </motion.svg>
                      )}
                    </AnimatePresence>
                  </motion.button>

                  {/* Type icon */}
                  <div className={`w-9 h-9 rounded-xl flex items-center justify-center shrink-0 ${typeBg}`}>
                    {typeIcon && (
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d={typeIcon} />
                      </svg>
                    )}
                  </div>

                  {/* Content */}
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap mb-0.5">
                      <p className={`text-sm font-semibold text-gray-900 ${act.is_done ? 'line-through text-gray-400' : ''}`}>
                        {act.title}
                      </p>
                      <Badge value={act.priority} />
                    </div>
                    {act.description && (
                      <p className="text-xs text-gray-400 truncate mb-0.5">{act.description}</p>
                    )}
                    {act.due_at && (
                      <p className={`text-xs font-medium ${overdue ? 'text-red-500' : 'text-gray-400'}`}>
                        {overdue && '⚠ '}
                        {new Date(act.due_at).toLocaleString('en-IN', {
                          day: 'numeric', month: 'short', year: 'numeric',
                          hour: '2-digit', minute: '2-digit',
                        })}
                      </p>
                    )}
                  </div>

                  {/* Actions — managers see all; team members only their own */}
                  {(canManage || act.assigned_to?.id === user?.id) && (
                    <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity shrink-0">
                      {canManage && (
                        <button
                          onClick={() => setModal(act)}
                          className="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors"
                          title="Edit"
                        >
                          <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                              d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                          </svg>
                        </button>
                      )}
                      {canManage && (
                        <button
                          onClick={() => { if (confirm('Delete this activity?')) deleteMutation.mutate(act.id); }}
                          className="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-red-500 hover:bg-red-50 transition-colors"
                          title="Delete"
                        >
                          <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                              d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                          </svg>
                        </button>
                      )}
                    </div>
                  )}
                </motion.div>
              );
            })}
          </AnimatePresence>
        )}
      </div>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-gray-500 pt-1">
          <span className="text-xs">
            Showing {(meta.current_page - 1) * (meta.per_page ?? 15) + 1}–
            {Math.min(meta.current_page * (meta.per_page ?? 15), meta.total)} of {meta.total}
          </span>
          <div className="flex gap-2">
            <button
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={page === 1}
              className="px-3 py-1.5 border border-gray-200 rounded-xl text-sm disabled:opacity-40 hover:bg-gray-50 transition-colors"
            >
              ← Prev
            </button>
            <button
              onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
              disabled={page === meta.last_page}
              className="px-3 py-1.5 border border-gray-200 rounded-xl text-sm disabled:opacity-40 hover:bg-gray-50 transition-colors"
            >
              Next →
            </button>
          </div>
        </div>
      )}

      {/* Modal */}
      <Modal
        open={modal !== undefined}
        onClose={() => setModal(undefined)}
        title={modal?.id ? 'Edit Activity' : 'New Activity'}
        maxWidth="max-w-md"
      >
        {modal !== undefined && (
          <ActivityForm
            activity={modal}
            onClose={() => setModal(undefined)}
            onSave={handleSave}
            saving={isSaving}
          />
        )}
      </Modal>
    </div>
  );
}
