'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { AnimatePresence, motion } from 'framer-motion';
import { useAuthStore } from '@/store/authStore';
import { scheduleAlertsApi, splitScheduleAlerts, type ScheduleAlertItem } from '@/lib/api/scheduleAlerts';
import { activitiesApi } from '@/lib/api/activities';

function fmtDue(iso?: string) {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('en-IN', {
    day: 'numeric', month: 'short',
    hour: '2-digit', minute: '2-digit',
  });
}

function AlertRow({
  item,
  onDone,
  marking,
}: {
  item: ScheduleAlertItem;
  onDone: (activityId: number) => void;
  marking: number | null;
}) {
  const isMissed = item.state === 'missed';
  const kindLabel = item.kind === 'meeting' ? 'Meeting' : 'Follow-up';
  const kindCls = item.kind === 'meeting'
    ? 'bg-violet-100 text-violet-700'
    : 'bg-fuchsia-100 text-fuchsia-700';

  return (
    <div className={`px-4 py-3 flex items-start gap-3 border-b border-gray-50 last:border-0 ${
      isMissed ? 'bg-red-50/50' : 'bg-amber-50/30'
    }`}>
      <div className={`mt-0.5 w-8 h-8 rounded-xl flex items-center justify-center shrink-0 text-sm ${
        isMissed ? 'bg-red-100 text-red-600' : 'bg-amber-100 text-amber-600'
      }`}>
        {item.kind === 'meeting' ? '🤝' : '📅'}
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 flex-wrap">
          <Link
            href={`/leads/${item.lead_id}`}
            className="text-sm font-semibold text-gray-900 hover:text-indigo-600 truncate"
          >
            {item.lead_name}
          </Link>
          <span className={`text-[10px] font-bold uppercase tracking-wide px-1.5 py-0.5 rounded-md ${kindCls}`}>
            {kindLabel}
          </span>
          {isMissed && (
            <span className="text-[10px] font-bold uppercase tracking-wide px-1.5 py-0.5 rounded-md bg-red-100 text-red-700">
              Missed
            </span>
          )}
        </div>
        <p className="text-xs text-gray-500 truncate mt-0.5">{item.title}</p>
        <p className={`text-[11px] font-medium mt-1 ${isMissed ? 'text-red-600' : 'text-amber-700'}`}>
          {isMissed ? '⚠ ' : '⏰ '}{fmtDue(item.due_at)}
        </p>
      </div>
      {item.activity_id > 0 && (
        <button
          type="button"
          onClick={() => onDone(item.activity_id)}
          disabled={marking === item.activity_id}
          title="Mark as done"
          className="shrink-0 w-7 h-7 rounded-lg border border-gray-200 flex items-center justify-center text-gray-400 hover:border-emerald-400 hover:bg-emerald-50 hover:text-emerald-600 transition-colors disabled:opacity-50"
        >
          {marking === item.activity_id ? (
            <svg className="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
          ) : (
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 13l4 4L19 7" />
            </svg>
          )}
        </button>
      )}
    </div>
  );
}

function Section({
  title,
  items,
  emptyText,
  onDone,
  marking,
}: {
  title: string;
  items: ScheduleAlertItem[];
  emptyText: string;
  onDone: (id: number) => void;
  marking: number | null;
}) {
  if (items.length === 0) {
    return (
      <div className="px-4 py-3 text-xs text-gray-400 text-center border-b border-gray-50 last:border-0">
        {emptyText}
      </div>
    );
  }
  return (
    <div>
      <p className="px-4 py-2 text-[10px] font-bold uppercase tracking-wider text-gray-400 bg-gray-50/80">
        {title} ({items.length})
      </p>
      {items.map((item) => (
        <AlertRow key={item.id} item={item} onDone={onDone} marking={marking} />
      ))}
    </div>
  );
}

export function ScheduleAlertFab() {
  const router = useRouter();
  const qc = useQueryClient();
  const user = useAuthStore((s) => s.user);
  const isEmployee = useAuthStore((s) => s.isEmployee());
  const [open, setOpen] = useState(false);
  const [marking, setMarking] = useState<number | null>(null);
  const [nowMs, setNowMs] = useState(() => Date.now());

  useEffect(() => {
    const id = window.setInterval(() => setNowMs(Date.now()), 5_000);
    return () => window.clearInterval(id);
  }, []);

  const { data, isLoading } = useQuery({
    queryKey: ['schedule-alerts', user?.id],
    queryFn:  scheduleAlertsApi.fetch,
    enabled:  isEmployee && !!user?.id,
    refetchInterval: 15_000,
    refetchOnWindowFocus: true,
  });

  const markDoneMutation = useMutation({
    mutationFn: activitiesApi.markDone,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['schedule-alerts'] });
      qc.invalidateQueries({ queryKey: ['follow-ups'] });
      qc.invalidateQueries({ queryKey: ['meetings'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });

  if (!isEmployee) return null;

  const { dueToday, missed } = splitScheduleAlerts(data?.upcoming ?? [], data?.missed ?? [], nowMs);
  const total = dueToday.length + missed.length;

  const handleDone = async (activityId: number) => {
    setMarking(activityId);
    try {
      await markDoneMutation.mutateAsync(activityId);
    } finally {
      setMarking(null);
    }
  };

  return (
    <>
      {open && (
        <button
          type="button"
          aria-label="Close schedule alerts"
          className="fixed inset-0 z-40 bg-black/10"
          onClick={() => setOpen(false)}
        />
      )}

      <div className="fixed bottom-6 right-6 z-50 flex flex-col items-end gap-3">
        <AnimatePresence>
          {open && (
            <motion.div
              initial={{ opacity: 0, y: 12, scale: 0.96 }}
              animate={{ opacity: 1, y: 0, scale: 1 }}
              exit={{ opacity: 0, y: 12, scale: 0.96 }}
              transition={{ duration: 0.2 }}
              className="w-[min(100vw-3rem,380px)] bg-white rounded-2xl border border-gray-200 shadow-2xl shadow-gray-900/10 overflow-hidden"
            >
              <div className="px-4 py-3 border-b border-gray-100 flex items-center justify-between bg-gradient-to-r from-indigo-50 to-violet-50">
                <div>
                  <h3 className="text-sm font-bold text-gray-900">My Schedule</h3>
                  <p className="text-[11px] text-gray-500 mt-0.5">Appears 5 minutes before due time</p>
                </div>
                <button
                  type="button"
                  onClick={() => setOpen(false)}
                  className="w-7 h-7 rounded-lg hover:bg-white/80 flex items-center justify-center text-gray-400"
                >
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>

              <div className="max-h-[min(60vh,420px)] overflow-y-auto">
                {isLoading ? (
                  <div className="px-4 py-8 text-center text-sm text-gray-400">Loading…</div>
                ) : total === 0 ? (
                  <div className="px-4 py-10 text-center">
                    <div className="w-12 h-12 bg-emerald-50 rounded-full flex items-center justify-center mx-auto mb-2 text-xl">
                      ✓
                    </div>
                    <p className="text-sm font-semibold text-gray-700">All caught up!</p>
                    <p className="text-xs text-gray-400 mt-1">Nothing due in the next 5 minutes, and nothing missed.</p>
                  </div>
                ) : (
                  <>
                    <Section
                      title="Due today"
                      items={dueToday}
                      emptyText="Nothing due in the next 5 minutes"
                      onDone={handleDone}
                      marking={marking}
                    />
                    <Section
                      title="Missed"
                      items={missed}
                      emptyText="No missed follow-ups"
                      onDone={handleDone}
                      marking={marking}
                    />
                  </>
                )}
              </div>

              <div className="px-4 py-2.5 border-t border-gray-100 flex gap-3 bg-gray-50/80">
                <button
                  type="button"
                  onClick={() => { setOpen(false); router.push('/follow-ups'); }}
                  className="text-xs font-semibold text-indigo-600 hover:text-indigo-700"
                >
                  Follow-ups →
                </button>
                <button
                  type="button"
                  onClick={() => { setOpen(false); router.push('/meetings'); }}
                  className="text-xs font-semibold text-violet-600 hover:text-violet-700"
                >
                  Meetings →
                </button>
              </div>
            </motion.div>
          )}
        </AnimatePresence>

        <div className="relative p-2">
          <motion.button
            type="button"
            onClick={() => setOpen((v) => !v)}
            whileTap={{ scale: 0.94 }}
            className={`relative w-14 h-14 rounded-2xl shadow-lg flex items-center justify-center transition-colors ${
              missed.length > 0
                ? 'bg-gradient-to-br from-red-500 to-orange-500 shadow-red-500/30'
                : total > 0
                  ? 'bg-gradient-to-br from-amber-500 to-orange-500 shadow-amber-500/30'
                  : 'bg-gradient-to-br from-indigo-600 to-violet-600 shadow-indigo-500/30'
            }`}
            aria-label="Schedule reminders"
          >
            <svg className="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            {missed.length > 0 && (
              <span className="absolute inset-0 rounded-2xl ring-2 ring-red-400 animate-pulse pointer-events-none" />
            )}
          </motion.button>
          {total > 0 && (
            <span className="absolute top-0 right-0 min-w-[22px] h-[22px] px-1 rounded-full bg-white text-gray-900 text-[11px] font-bold leading-none flex items-center justify-center shadow-md border border-gray-100 z-10">
              {total > 99 ? '99+' : total}
            </span>
          )}
        </div>
      </div>
    </>
  );
}
