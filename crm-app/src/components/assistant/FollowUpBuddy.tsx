'use client';

import { useEffect, useMemo, useState } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import { AnimatePresence, motion, useReducedMotion } from 'framer-motion';
import { AssistantRobot, type RobotState } from '@/components/assistant/AssistantRobot';
import { assistantApi, type AssistantAlert } from '@/lib/api/assistant';
import { useAuthStore } from '@/store/authStore';

const STORAGE_KEY = 'growneq.followup-buddy.v1';
type BuddyPrefs = { enabled: boolean; muted: boolean; snoozedUntil: number; dismissed: string[] };
const defaults: BuddyPrefs = { enabled: true, muted: false, snoozedUntil: 0, dismissed: [] };

function message(alert: AssistantAlert) {
  if (alert.type === 'overdue_followup') return `${alert.lead_name}'s follow-up is overdue. Let's prioritize it.`;
  if (alert.type === 'meeting_attention') return `${alert.lead_name}'s meeting needs a status review.`;
  if (alert.type === 'upcoming_meeting') return `Your meeting with ${alert.lead_name} starts soon. Ready to prepare?`;
  if (alert.type === 'urgent_activity') return `An important activity for ${alert.lead_name} is still pending.`;
  return `${alert.lead_name}'s important follow-up is due soon.`;
}

function dueLabel(value?: string | null) {
  if (!value) return 'No due time';
  return new Date(value).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

export function FollowUpBuddy() {
  const pathname = usePathname();
  const router = useRouter();
  const user = useAuthStore((state) => state.user);
  const reducedMotion = useReducedMotion();
  const [open, setOpen] = useState(false);
  const [prefs, setPrefs] = useState<BuddyPrefs>(defaults);

  useEffect(() => {
    try { setPrefs({ ...defaults, ...JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}') }); } catch { setPrefs(defaults); }
  }, []);
  const save = (next: BuddyPrefs) => { setPrefs(next); localStorage.setItem(STORAGE_KEY, JSON.stringify(next)); };
  const query = useQuery({
    queryKey: ['assistant-alerts', user?.id], queryFn: assistantApi.alerts,
    enabled: pathname === '/dashboard' && !!user?.id && prefs.enabled && prefs.snoozedUntil <= Date.now(),
    refetchInterval: 60_000, refetchOnWindowFocus: true, retry: 1,
  });
  const alerts = useMemo(() => (query.data ?? []).filter((item) => !prefs.dismissed.includes(item.id)), [query.data, prefs.dismissed]);
  const active = alerts[0];
  const robotState: RobotState = prefs.snoozedUntil > Date.now() ? 'snoozed' : active?.severity === 'critical' ? 'urgent' : active ? 'important' : 'idle';

  if (pathname !== '/dashboard' || !user || !prefs.enabled) return null;

  return <div className="fixed bottom-24 right-3 z-50 sm:bottom-6 sm:right-28">
    <AnimatePresence>{open && <motion.section
      initial={reducedMotion ? false : { opacity: 0, y: 12, scale: .97 }} animate={{ opacity: 1, y: 0, scale: 1 }} exit={{ opacity: 0, y: 10, scale: .98 }}
      role="dialog" aria-label="Follow-up Buddy alerts" className="mb-3 w-[min(calc(100vw-1.5rem),390px)] overflow-hidden rounded-3xl border border-violet-100 bg-white shadow-2xl">
      <header className="flex items-center gap-3 bg-gradient-to-r from-violet-50 to-blue-50 px-4 py-3">
        <AssistantRobot variant="buddy" state={active ? 'talking' : 'idle'} muted={prefs.muted} className="h-14 w-12 shrink-0" />
        <div className="min-w-0 flex-1"><h2 className="font-bold text-gray-900">Follow-up Buddy</h2><p className="text-xs text-gray-500">Important actions only</p></div>
        <button onClick={() => setOpen(false)} aria-label="Minimize Follow-up Buddy" className="h-9 w-9 rounded-xl text-gray-500 hover:bg-white">—</button>
      </header>
      <div className="max-h-[52vh] overflow-y-auto">
        {query.isLoading ? <p className="p-6 text-center text-sm text-gray-500">Checking important actions…</p> : query.isError ? <p className="p-6 text-center text-sm text-red-600">Buddy could not load alerts. Try again shortly.</p> : !alerts.length ? <div className="p-6 text-center"><p className="font-semibold text-emerald-700">All important actions are under control.</p><p className="mt-1 text-xs text-gray-500">Buddy will alert you when something needs attention.</p></div> : alerts.map((alert) => <article key={alert.id} className="border-b border-gray-100 p-4 last:border-0">
          <div className="flex items-start gap-3"><span className={`mt-1 h-2.5 w-2.5 shrink-0 rounded-full ${alert.severity === 'critical' ? 'bg-red-500' : alert.severity === 'urgent' ? 'bg-orange-500' : 'bg-violet-500'}`} /><div className="min-w-0 flex-1"><p className="text-sm font-semibold text-gray-900">{message(alert)}</p><p className="mt-1 text-xs text-gray-500">{alert.title} · {dueLabel(alert.due_at)}</p></div></div>
          <div className="mt-3 flex items-center gap-2 pl-5"><button onClick={() => { setOpen(false); router.push(alert.navigation); }} className="min-h-9 rounded-xl bg-indigo-600 px-3 text-xs font-semibold text-white hover:bg-indigo-700">{alert.navigation === '/meetings' ? 'View meetings' : alert.navigation === '/activities' ? 'View activities' : 'View follow-ups'}</button><button onClick={() => save({ ...prefs, dismissed: [...prefs.dismissed, alert.id].slice(-100) })} className="min-h-9 rounded-xl px-3 text-xs font-semibold text-gray-500 hover:bg-gray-100">Dismiss</button></div>
        </article>)}
      </div>
      <footer className="flex flex-wrap items-center gap-2 border-t bg-gray-50 px-4 py-3 text-xs">
        <button onClick={() => { save({ ...prefs, snoozedUntil: Date.now() + 60 * 60 * 1000 }); setOpen(false); }} className="rounded-lg px-2 py-1.5 font-semibold text-gray-600 hover:bg-white">Snooze 1 hour</button>
        <label className="ml-auto flex items-center gap-2 text-gray-500"><input type="checkbox" checked={prefs.muted} onChange={(event) => save({ ...prefs, muted: event.target.checked })} /> Mute motion</label>
      </footer>
    </motion.section>}</AnimatePresence>
    <div className="relative ml-auto w-fit"><motion.button type="button" onClick={() => setOpen((value) => !value)} whileTap={{ scale: .95 }} aria-label={`${open ? 'Minimize' : 'Open'} Follow-up Buddy${alerts.length ? `, ${alerts.length} important alerts` : ''}`} className="flex h-16 w-16 items-center justify-center rounded-2xl border border-violet-200 bg-white shadow-xl hover:shadow-2xl sm:h-20 sm:w-20"><AssistantRobot variant="buddy" state={robotState} muted={prefs.muted} className="h-[72px] w-[62px] sm:h-[86px] sm:w-[72px]" /></motion.button>{!!alerts.length && <span className="absolute -right-1 -top-1 flex h-6 min-w-6 items-center justify-center rounded-full bg-red-500 px-1 text-[11px] font-bold text-white shadow">{alerts.length > 9 ? '9+' : alerts.length}</span>}</div>
  </div>;
}


