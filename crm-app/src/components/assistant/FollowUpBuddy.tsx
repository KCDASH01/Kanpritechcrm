'use client';

import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useQuery } from '@tanstack/react-query';
import { AnimatePresence, motion, useReducedMotion } from 'framer-motion';
import { assistantApi, type AssistantAlert } from '@/lib/api/assistant';
import { useAuthStore } from '@/store/authStore';
import { AssistantRobot, type RobotState } from './AssistantRobot';
import { useDashboardAssistantAudio, useDashboardVisibility } from './DashboardAssistantAudio';

const defaults = { muted: false, snoozedUntil: 0, dismissed: [] as string[] };
const badge = { critical: 'bg-red-500', urgent: 'bg-orange-500', important: 'bg-violet-500' };
const rank = { critical: 0, urgent: 1, important: 2 };

function details(alert: AssistantAlert) {
  if ('amount' in alert) {
    const amount = new Intl.NumberFormat('en-IN', { style: 'currency', currency: alert.currency }).format(alert.amount);
    if (alert.type === 'recurring_collection') return {
      message: `${amount} ${alert.due_at ? 'due soon' : 'outstanding'} for ${alert.title}.`,
      action: 'View recurring business',
    };
    if (alert.type === 'invoice_collection') return { message: `${amount} pending on invoice ${alert.title}.`, action: 'View invoice' };
    return { message: `${amount} pending for ${alert.title}.`, action: 'View deal' };
  }
  const action = alert.navigation === '/meetings' ? 'View meetings' : alert.navigation === '/activities' ? 'View activities' : 'View follow-ups';
  if (alert.type === 'overdue_followup') return { message: `${alert.lead_name}'s follow-up is overdue.`, action };
  if (alert.type === 'meeting_attention') return { message: `${alert.lead_name}'s meeting needs a status review.`, action };
  if (alert.type === 'upcoming_meeting') return { message: `Your meeting with ${alert.lead_name} starts soon.`, action };
  if (alert.type === 'urgent_activity') return { message: `An important activity for ${alert.lead_name} is still pending.`, action };
  return { message: `${alert.lead_name}'s important follow-up is due soon.`, action };
}

function dueLabel(value: string) {
  const date = { day: 'numeric', month: 'short' } as const;
  return value.length === 10
    ? new Date(`${value}T00:00:00`).toLocaleDateString('en-IN', date)
    : new Date(value).toLocaleString('en-IN', { ...date, hour: '2-digit', minute: '2-digit' });
}

export function FollowUpBuddy() {
  const user = useAuthStore((state) => state.user);
  if (!user) return null;
  const scope = `${user.organization?.id ?? ''}:${user.id}:${user.role}`;
  return <Buddy key={scope} scope={scope} />;
}

function Buddy({ scope }: { scope: string }) {
  const reducedMotion = useReducedMotion();
  const visible = useDashboardVisibility();
  const { play, playWhenHidden } = useDashboardAssistantAudio();
  const trigger = useRef<HTMLButtonElement>(null);
  const history = useRef<{ seen: Set<string>; activities: number[] }>();
  const tracked = useRef<number[]>([]);
  const storageKey = `growneq.followup-buddy.v2:${scope}`;
  const [prefs, setPrefs] = useState(defaults);
  const [ready, setReady] = useState(false);
  const [open, setOpen] = useState(false);
  const [awake, setAwake] = useState(0);
  const [sleeping, setSleeping] = useState(false);
  const [snoozed, setSnoozed] = useState(false);
  const [reaction, setReaction] = useState<{ state: RobotState; duration: number }>({ state: 'wave', duration: 1250 });

  useEffect(() => {
    try {
      const saved = { ...defaults, ...JSON.parse(localStorage.getItem(storageKey) ?? '{}') };
      setPrefs(saved);
      setSnoozed(saved.snoozedUntil > Date.now());
    } catch { /* Preferences are optional. */ }
    setReady(true);
  }, [storageKey]);

  function save(next: typeof defaults) {
    setPrefs(next);
    try { localStorage.setItem(storageKey, JSON.stringify(next)); } catch { /* Keep the current session usable. */ }
  }

  useEffect(() => {
    if (!snoozed) return;
    const timeout = window.setTimeout(() => setSnoozed(false), Math.max(0, prefs.snoozedUntil - Date.now()));
    return () => window.clearTimeout(timeout);
  }, [prefs.snoozedUntil, snoozed]);

  const query = useQuery({
    queryKey: ['assistant-alerts', scope],
    queryFn: ({ signal }) => assistantApi.alerts(tracked.current, signal),
    enabled: ready && !snoozed,
    refetchInterval: 60_000,
    refetchIntervalInBackground: playWhenHidden,
    refetchOnWindowFocus: true,
    staleTime: 0,
    gcTime: 0,
  });
  const result = query.data;
  const alerts = (query.isError || snoozed ? [] : [...(result?.data ?? []), ...(result?.collections ?? [])])
    .filter((alert) => !prefs.dismissed.includes(alert.id))
    .sort((a, b) => rank[a.severity] - rank[b.severity]);
  const active = alerts[0];
  const canSleep = !active && !open && !snoozed && visible && !query.isError && !!result;
  const overdue = alerts.some((alert) => alert.type === 'overdue_followup' || alert.type === 'meeting_attention');

  useEffect(() => {
    if (!result || snoozed || query.isError || !query.isFetchedAfterMount) return;
    const activities = result.data.map((alert) => alert.record_id);
    const all = [...result.data, ...(result.collections ?? [])];
    const previous = history.current;
    const seen = previous?.seen ?? new Set<string>();
    const newAlert = previous && all.some((alert) => !seen.has(alert.id) && !prefs.dismissed.includes(alert.id));
    const completed = new Set(result.completed_activity_ids);
    const finished = previous?.activities.length && !activities.length && previous.activities.every((id) => completed.has(id));
    tracked.current = Array.from(new Set([...activities, ...tracked.current.filter((id) => !completed.has(id))])).slice(0, 50);
    all.forEach((alert) => seen.add(alert.id));
    history.current = { seen, activities };

    if (newAlert) {
      if (visible) setReaction({ state: 'alert', duration: 1300 });
      play('buddy', ['alert']);
    } else if (finished) {
      if (visible) setReaction({ state: 'celebrate', duration: 1350 });
      play('buddy', ['success', 'celebrate']);
    }
  }, [result, query.isError, query.isFetchedAfterMount, query.dataUpdatedAt, snoozed, prefs.dismissed, play, visible]);

  useEffect(() => {
    if (!reaction.duration) return;
    const timeout = window.setTimeout(() => setReaction((current) => ({
      state: current.state === 'celebrate' ? 'happy' : 'idle',
      duration: current.state === 'celebrate' ? 1700 : 0,
    })), reaction.duration);
    return () => window.clearTimeout(timeout);
  }, [reaction]);

  useEffect(() => {
    if (!canSleep) {
      setSleeping(false);
      return;
    }
    const timeout = window.setTimeout(() => setSleeping(true), 90_000);
    return () => window.clearTimeout(timeout);
  }, [awake, canSleep]);

  useEffect(() => {
    if (!overdue || !visible) return;
    const timer = window.setInterval(() => setReaction({ state: 'alert', duration: 1300 }), 45_000);
    return () => window.clearInterval(timer);
  }, [overdue, visible]);

  const state = snoozed ? 'sleep' : reaction.duration ? reaction.state
    : overdue ? 'sad' : active ? 'alert' : sleeping && !open ? 'sleep' : 'idle';
  const completionMessage = state === 'celebrate' || state === 'happy' ? 'All tracked important activities are complete.' : '';

  function close() {
    setOpen(false);
    trigger.current?.focus();
  }

  return (
    <div className="fixed bottom-[calc(.75rem+env(safe-area-inset-bottom))] right-[calc(.75rem+env(safe-area-inset-right))] z-30 sm:bottom-[calc(1.25rem+env(safe-area-inset-bottom))]"
      onKeyDown={(event) => { if (open && event.key === 'Escape') { event.stopPropagation(); close(); } }}>
      <span className="sr-only" role="status">{completionMessage}</span>
      <div className="relative ml-auto w-fit">
        <motion.button type="button" ref={trigger} onClick={() => {
          if (state === 'sleep' && !snoozed) setReaction({ state: 'wave', duration: 1250 });
          setSleeping(false);
          setAwake((value) => value + 1);
          setOpen((value) => !value);
        }} whileTap={reducedMotion ? undefined : { scale: .95 }} aria-expanded={open} aria-controls={open ? 'buddy-alerts' : undefined}
          aria-label={`Follow-up Buddy${alerts.length ? `, ${alerts.length} important alerts` : ''}`}
          className="flex h-14 w-14 items-center justify-center rounded-2xl border border-violet-200 bg-white shadow-xl hover:shadow-2xl sm:h-16 sm:w-16">
          <AssistantRobot variant="buddy" state={state} muted={prefs.muted} className="h-14 w-12 sm:h-16 sm:w-14" />
        </motion.button>
        {!!alerts.length && <span aria-hidden="true" className="pointer-events-none absolute -right-1 -top-1 flex h-6 min-w-6 items-center justify-center rounded-full bg-red-600 px-1 text-[11px] font-bold text-white shadow">{alerts.length > 9 ? '9+' : alerts.length}</span>}
      </div>
      <AnimatePresence>{open && <motion.section id="buddy-alerts"
        initial={reducedMotion ? false : { opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} exit={reducedMotion ? { opacity: 0 } : { opacity: 0, y: 8 }}
        aria-labelledby="buddy-alerts-title"
        className="absolute bottom-full right-0 mb-3 flex max-h-[calc(100dvh-6.5rem-env(safe-area-inset-bottom)-env(safe-area-inset-top))] w-[min(390px,calc(100vw-1.5rem-env(safe-area-inset-left)-env(safe-area-inset-right)))] flex-col overflow-hidden rounded-2xl border border-violet-100 bg-white shadow-2xl">
        <header className="flex shrink-0 items-center justify-between bg-gradient-to-r from-violet-50 to-blue-50 px-4 py-1">
          <h2 id="buddy-alerts-title" className="font-bold text-gray-900">Follow-up Buddy</h2>
          <button type="button" onClick={close} aria-label="Minimize Follow-up Buddy" className="h-11 w-11 rounded-xl text-gray-600 hover:bg-white">—</button>
        </header>
        <div className="min-h-0 overflow-y-auto overscroll-contain">
          {snoozed ? <p className="p-4 text-sm text-gray-600">Reminders are snoozed.</p>
            : !ready || query.isPending ? <p className="p-4 text-sm text-gray-500">Checking reminders…</p>
            : query.isError ? <p className="p-4 text-sm text-red-700">Reminders are temporarily unavailable.</p>
            : !alerts.length ? <p className="p-4 text-sm font-medium text-emerald-700">No important actions need your attention.</p>
            : alerts.map((alert) => {
              const { message, action } = details(alert);
              const subtitle = ['amount' in alert ? '' : alert.title, alert.due_at ? dueLabel(alert.due_at) : ''].filter(Boolean).join(' · ');
              return (
                <article key={alert.id} className="border-b border-gray-100 p-4">
                  <div className="flex items-start gap-3">
                    <span className={`mt-1 h-2.5 w-2.5 shrink-0 rounded-full ${badge[alert.severity]}`} />
                    <div className="min-w-0 break-words"><p className="text-sm font-semibold text-gray-900">{message}</p>{subtitle && <p className="mt-1 text-xs text-gray-500">{subtitle}</p>}</div>
                  </div>
                  <div className="mt-2 flex flex-wrap items-center gap-2 pl-5">
                    <Link href={alert.navigation} onClick={close} className="inline-flex min-h-11 items-center rounded-xl bg-indigo-600 px-3 text-xs font-semibold text-white hover:bg-indigo-700">{action}</Link>
                    <button type="button" onClick={() => save({ ...prefs, dismissed: [...prefs.dismissed, alert.id].slice(-100) })} className="min-h-11 rounded-xl px-3 text-xs font-semibold text-gray-600 hover:bg-gray-100">Dismiss</button>
                  </div>
                </article>
              );
            })}
        </div>
        <footer className="flex shrink-0 flex-wrap items-center gap-2 border-t bg-gray-50 px-4 py-1 text-xs">
          <button type="button" onClick={() => {
            if (snoozed) { save({ ...prefs, snoozedUntil: 0 }); setSnoozed(false); }
            else { save({ ...prefs, snoozedUntil: Date.now() + 60 * 60 * 1000 }); setSnoozed(true); close(); }
          }} className="min-h-11 rounded-lg px-2 font-semibold text-gray-600 hover:bg-white">{snoozed ? 'Resume reminders' : 'Snooze 1 hour'}</button>
          <label className="ml-auto flex min-h-11 items-center gap-2 text-gray-600"><input type="checkbox" checked={prefs.muted} onChange={(event) => save({ ...prefs, muted: event.target.checked })} /> Reduce motion</label>
        </footer>
      </motion.section>}</AnimatePresence>
    </div>
  );
}
