'use client';

import { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { AnimatePresence, motion, useReducedMotion } from 'framer-motion';
import { AssistantRobot, type RobotState } from '@/components/assistant/AssistantRobot';
import { assistantApi, type TargetCoachStatus, type TargetCoachTarget } from '@/lib/api/assistant';
import { useDashboardAssistantAudio, useDashboardVisibility } from '@/components/assistant/DashboardAssistantAudio';
import { useAuthStore } from '@/store/authStore';

const STATUS: Record<TargetCoachStatus, { label: string; tone: string; robot: RobotState }> = {
  upcoming: { label: 'Upcoming', tone: 'bg-blue-50 text-blue-700', robot: 'idle' },
  on_track: { label: 'On track', tone: 'bg-emerald-50 text-emerald-700', robot: 'idle' },
  at_risk: { label: 'At risk', tone: 'bg-orange-50 text-orange-700', robot: 'sad' },
  behind: { label: 'Behind target', tone: 'bg-red-50 text-red-700', robot: 'sad' },
  achieved: { label: 'Target achieved', tone: 'bg-emerald-50 text-emerald-700', robot: 'happy' },
  exceeded: { label: 'Target exceeded', tone: 'bg-emerald-100 text-emerald-800', robot: 'happy' },
  unavailable: { label: 'Projection unavailable', tone: 'bg-gray-100 text-gray-600', robot: 'thoughtful' },
};

const isAchievement = (status: TargetCoachStatus) => status === 'achieved' || status === 'exceeded';
const targetKey = (target: TargetCoachTarget) => `${target.source}:${target.id}:${target.period_start}:${target.period_end}`;
const noTargets: TargetCoachTarget[] = [];

function value(amount: number | null | undefined, target: TargetCoachTarget) {
  if (amount == null) return '—';
  if (target.unit_type === 'currency') return `₹${amount.toLocaleString('en-IN', { maximumFractionDigits: 2 })}`;
  if (target.unit_type === 'percentage') return `${amount.toLocaleString('en-IN', { maximumFractionDigits: 2 })}%`;
  return `${amount.toLocaleString('en-IN', { maximumFractionDigits: 2 })}${target.unit_label ? ` ${target.unit_label}` : ''}`;
}

function coachMessage(target: TargetCoachTarget) {
  if (isAchievement(target.projection_status)) return `Congratulations! You've ${target.projection_status === 'exceeded' ? 'exceeded' : 'achieved'} your ${target.target_name}.`;
  if (!target.projection_available) return `There isn't enough compatible data yet to calculate a reliable ${target.target_name} projection.`;
  if (target.projection_status === 'on_track') return `Your ${target.target_name} is on track. Maintain your current pace.`;
  if (target.projection_status === 'at_risk') return `Your ${target.target_name} is at risk. Aim for ${value(target.required_daily_run_rate, target)} per working day.`;
  if (target.projection_status === 'behind') return `Your ${target.target_name} has a remaining gap of ${value(target.remaining_value, target)}. You need ${value(target.required_daily_run_rate, target)} per working day.`;
  return `Your ${target.target_name} period has not started yet.`;
}

export function TargetCoach({ selectedUserId }: { selectedUserId?: number }) {
  const user = useAuthStore((state) => state.user);
  if (!user) return null;
  const scope = `${user.organization?.id ?? ''}:${user.id}:${user.role}`;
  return <Coach key={`${scope}:${selectedUserId ?? ''}`} scope={scope} selectedUserId={selectedUserId} />;
}

function Coach({ scope, selectedUserId }: { scope: string; selectedUserId?: number }) {
  const reducedMotion = useReducedMotion();
  const visible = useDashboardVisibility();
  const { play, playWhenHidden } = useDashboardAssistantAudio();
  const [expanded, setExpanded] = useState(false);
  const [selected, setSelected] = useState('');
  const [muted, setMuted] = useState(false);
  const [celebrating, setCelebrating] = useState('');
  const previousStatuses = useRef<Map<string, TargetCoachStatus>>();
  const achieved = useRef(new Set<string>());
  const motionKey = `growneq.target-coach.reduced-motion:${scope}`;
  useEffect(() => {
    try { setMuted(localStorage.getItem(motionKey) === '1'); } catch { /* Preferences are optional. */ }
  }, [motionKey]);
  const query = useQuery({
    queryKey: ['target-coach', scope, selectedUserId],
    queryFn: ({ signal }) => assistantApi.targets(selectedUserId, signal),
    staleTime: 60_000,
    refetchInterval: 60_000,
    refetchIntervalInBackground: playWhenHidden,
    refetchOnWindowFocus: true,
  });
  const targets = query.data?.targets ?? noTargets;
  const target = targets.find((candidate) => targetKey(candidate) === selected) ?? targets[0];
  const state = target ? STATUS[target.projection_status] : STATUS.unavailable;
  const ahead = target?.projection_status === 'on_track' && target.direction === 'higher'
    && target.actual_daily_run_rate != null && target.required_daily_run_rate != null
    && target.actual_daily_run_rate > target.required_daily_run_rate;
  const robotState = celebrating ? 'dance' : ahead ? 'happy' : target ? state.robot : 'idle';

  useEffect(() => {
    if (!query.data || query.isError || !query.isFetchedAfterMount) return;
    const previous = previousStatuses.current;
    const newlyAchieved = targets.filter((item) => {
      const key = targetKey(item);
      const before = previous?.get(key);
      return before && !isAchievement(before) && isAchievement(item.projection_status) && !achieved.current.has(key);
    });
    targets.filter((item) => isAchievement(item.projection_status)).forEach((item) => achieved.current.add(targetKey(item)));
    previousStatuses.current = new Map(targets.map((item) => [targetKey(item), item.projection_status]));
    if (!newlyAchieved.length) return;
    if (visible) setCelebrating(newlyAchieved[0].target_name);
    play('coach', ['celebrate', 'success']);
  }, [play, query.data, query.isError, query.isFetchedAfterMount, targets, visible]);

  useEffect(() => {
    if (!celebrating) return;
    const timeout = window.setTimeout(() => setCelebrating(''), 3500);
    return () => window.clearTimeout(timeout);
  }, [celebrating]);

  const summary = query.isError ? 'Target data is temporarily unavailable.'
    : query.isLoading ? 'Checking assigned targets…'
    : celebrating ? `Target reached: ${celebrating}.`
    : ahead ? `You’re ahead of pace for your ${target.target_name}.`
    : target ? coachMessage(target) : 'No active assigned targets need coaching right now.';

  return <div className="mx-2 mb-2 overflow-hidden rounded-2xl border border-indigo-100 bg-gradient-to-r from-white via-indigo-50/60 to-emerald-50/40">
    <div className="flex flex-wrap items-center gap-3 px-4 py-3">
      <AssistantRobot variant="coach" state={query.isError ? 'thoughtful' : robotState} muted={muted} className="h-16 w-14 shrink-0" />
      <div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><h3 className="text-sm font-bold text-gray-900">Target Coach</h3>{target && !query.isError && <span className={`rounded-full px-2 py-1 text-[10px] font-bold uppercase tracking-wide ${state.tone}`}>{state.label}</span>}</div><p aria-live="polite" className="mt-1 break-words text-xs leading-5 text-gray-600">{summary}</p></div>
      {!query.isError && !!targets.length && <button type="button" onClick={() => setExpanded((value) => !value)} aria-expanded={expanded} aria-controls={expanded ? 'coach-analytics' : undefined} className="min-h-11 rounded-xl border border-indigo-200 bg-white px-3 text-xs font-semibold text-indigo-700 hover:bg-indigo-50">{expanded ? 'Hide analytics' : `View analytics (${targets.length})`}</button>}
    </div>
    <AnimatePresence>{expanded && target && !query.isError && <motion.div id="coach-analytics" initial={reducedMotion ? false : { opacity: 0, height: 0 }} animate={{ opacity: 1, height: 'auto' }} exit={reducedMotion ? undefined : { opacity: 0, height: 0 }} className="border-t border-indigo-100 bg-white/80">
      <div className="p-4"><div className="flex flex-wrap items-end justify-between gap-3"><label className="w-full max-w-xs text-[10px] font-bold uppercase tracking-wide text-gray-500">Assigned target<select value={targetKey(target)} onChange={(event) => setSelected(event.target.value)} className="mt-1 block min-h-11 w-full rounded-xl border border-gray-200 bg-white px-3 text-sm font-semibold normal-case text-gray-800">{targets.map((item) => <option key={targetKey(item)} value={targetKey(item)}>{item.target_name} · {item.user?.name || item.department?.name || 'Team'}</option>)}</select></label><label className="flex items-center gap-2 text-xs text-gray-500"><input type="checkbox" checked={muted} onChange={(event) => {
        setMuted(event.target.checked);
        try { localStorage.setItem(motionKey, event.target.checked ? '1' : '0'); } catch { /* Keep the current session usable. */ }
      }} /> Reduce motion</label></div>
        <div className="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4"><Metric label="Target" value={value(target.target_value, target)} /><Metric label="Achieved" value={value(target.actual_value, target)} /><Metric label="Remaining" value={value(target.remaining_value, target)} /><Metric label="Achievement" value={target.achievement_percentage == null ? '—' : `${target.achievement_percentage}%`} /><Metric label="Elapsed workdays" value={String(target.elapsed_working_days)} /><Metric label="Remaining workdays" value={String(target.remaining_working_days)} /><Metric label="Actual daily pace" value={value(target.actual_daily_run_rate, target)} /><Metric label="Required daily pace" value={value(target.required_daily_run_rate, target)} /><Metric label="Projected finish" value={value(target.projected_value, target)} /><Metric label="Projected gap" value={value(target.projected_shortfall, target)} /></div>
        <div className="mt-4"><div className="mb-1 flex justify-between text-[10px] font-semibold text-gray-500"><span>Actual progress</span><span>Projected {target.projected_percentage == null ? '—' : `${target.projected_percentage}%`}</span></div><div className="h-3 overflow-hidden rounded-full bg-gray-200"><div className={`h-full rounded-full ${target.projection_status === 'behind' ? 'bg-red-500' : target.projection_status === 'at_risk' ? 'bg-orange-500' : 'bg-emerald-500'}`} style={{ width: `${Math.min(100, Math.max(0, target.achievement_percentage ?? 0))}%` }} /></div></div>
        <p className="mt-3 text-[10px] text-gray-400">{target.period_start} – {target.period_end} · {query.data?.meta.working_day_rule}</p>
      </div>
    </motion.div>}</AnimatePresence>
  </div>;
}

function Metric({ label, value }: { label: string; value: string }) {
  return <div className="rounded-xl border border-gray-100 bg-white p-3"><p className="text-[10px] font-semibold uppercase tracking-wide text-gray-400">{label}</p><p className="mt-1 text-sm font-bold text-gray-900">{value}</p></div>;
}
