'use client';

import { useState, useMemo } from 'react';
import Link from 'next/link';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { motion, AnimatePresence } from 'framer-motion';
import { activitiesApi, type ActivityPayload } from '@/lib/api/activities';
import { useAuthStore } from '@/store/authStore';
import { AccessDenied } from '@/components/ui/AccessDenied';
import type { Activity } from '@/types';

// ── Helpers ───────────────────────────────────────────────────────────────────

const TYPE_COLORS: Record<string, { dot: string; chip: string; label: string }> = {
  call:     { dot: 'bg-green-500',  chip: 'bg-green-100 text-green-700',   label: '📞 Call'    },
  email:    { dot: 'bg-violet-500', chip: 'bg-violet-100 text-violet-700', label: '✉️ Email'   },
  meeting:  { dot: 'bg-blue-500',   chip: 'bg-blue-100 text-blue-700',     label: '🤝 Meeting' },
  task:     { dot: 'bg-orange-500', chip: 'bg-orange-100 text-orange-700', label: '✓ Task'     },
  note:     { dot: 'bg-yellow-500', chip: 'bg-yellow-100 text-yellow-700', label: '📝 Note'    },
  deadline: { dot: 'bg-red-500',    chip: 'bg-red-100 text-red-700',       label: '🚩 Deadline'},
};

function toLocalDate(date: Date): string {
  return date.toISOString().slice(0, 10);
}

function getMonthRange(year: number, month: number) {
  const start = new Date(year, month, 1);
  const end   = new Date(year, month + 1, 0);
  return {
    from: toLocalDate(start),
    to:   toLocalDate(end),
  };
}

function getWeekRange(date: Date) {
  const d   = new Date(date);
  const day = d.getDay();
  const mon = new Date(d); mon.setDate(d.getDate() - ((day + 6) % 7));
  const sun = new Date(mon); sun.setDate(mon.getDate() + 6);
  return { from: toLocalDate(mon), to: toLocalDate(sun), mon, sun };
}

function buildMonthGrid(year: number, month: number): Date[] {
  const firstDay = new Date(year, month, 1);
  const startOffset = (firstDay.getDay() + 6) % 7; // Mon=0
  const cells: Date[] = [];
  for (let i = 0; i < startOffset; i++) {
    const d = new Date(year, month, 1 - startOffset + i);
    cells.push(d);
  }
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  for (let i = 1; i <= daysInMonth; i++) cells.push(new Date(year, month, i));
  while (cells.length % 7 !== 0) {
    cells.push(new Date(year, month + 1, cells.length - startOffset - daysInMonth + 1));
  }
  return cells;
}

const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const WEEKDAYS = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
const HOURS = Array.from({ length: 13 }, (_, i) => i + 8); // 8am–8pm

/** Returns the navigation href for an activity's subject, or null if standalone. */
function subjectLink(act: Activity): { href: string; label: string } | null {
  if (!act.subject_type || !act.subject_id) return null;
  const t = act.subject_type.toLowerCase();
  if (t.includes('lead')) return { href: `/leads/${act.subject_id}`, label: 'View Lead' };
  if (t.includes('deal')) return { href: `/deals`,                   label: 'View Deals' };
  return null;
}

function toLocalDatetimeStr(date: Date, hour = 9): string {
  const d = new Date(date);
  d.setHours(hour, 0, 0, 0);
  const offset = d.getTimezoneOffset();
  return new Date(d.getTime() - offset * 60_000).toISOString().slice(0, 16);
}

// ── Component ─────────────────────────────────────────────────────────────────

export default function CalendarPage() {
  const { user, isOwner, isAdmin, isPaidPlan } = useAuthStore();
  const qc = useQueryClient();
  const canManage = isOwner() || isAdmin();

  if (!isPaidPlan()) {
    return (
      <AccessDenied
        reason="The calendar view is available on the Business and Enterprise plans. Upgrade to visualise and manage activities."
        upgradeHref="/plans"
      />
    );
  }
  const assignedFilter = canManage ? undefined : user?.id;

  const [view, setView]             = useState<'month' | 'week'>('month');
  const [currentDate, setCurrentDate] = useState(new Date());
  const [selectedDay, setSelectedDay] = useState<Date | null>(null);
  const [createDate, setCreateDate]  = useState<Date | null>(null);

  // ── Date range for query ──────────────────────────────────────────────────
  const { from, to } = useMemo(() => {
    if (view === 'month') {
      return getMonthRange(currentDate.getFullYear(), currentDate.getMonth());
    }
    const w = getWeekRange(currentDate);
    return { from: w.from, to: w.to };
  }, [view, currentDate]);

  const { data, isLoading } = useQuery({
    queryKey: ['calendar-activities', from, to, assignedFilter],
    queryFn:  () => activitiesApi.list({ date_from: from, date_to: to, per_page: 300, assigned_to: assignedFilter }),
  });
  const activities: Activity[] = data?.data ?? [];

  // Group by date key
  const byDate = useMemo(() => {
    const map: Record<string, Activity[]> = {};
    activities.forEach((a) => {
      if (!a.due_at) return;
      const key = a.due_at.slice(0, 10);
      if (!map[key]) map[key] = [];
      map[key].push(a);
    });
    return map;
  }, [activities]);

  // ── Mark done ─────────────────────────────────────────────────────────────
  const doneMutation = useMutation({
    mutationFn: activitiesApi.markDone,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['calendar-activities'] }),
  });

  // ── Navigate ──────────────────────────────────────────────────────────────
  const navigate = (dir: -1 | 1) => {
    const d = new Date(currentDate);
    if (view === 'month') { d.setMonth(d.getMonth() + dir); }
    else                  { d.setDate(d.getDate() + dir * 7); }
    setCurrentDate(d);
    setSelectedDay(null);
  };

  // ── Month grid ────────────────────────────────────────────────────────────
  const monthCells = useMemo(() =>
    buildMonthGrid(currentDate.getFullYear(), currentDate.getMonth()),
  [currentDate]);

  const today = toLocalDate(new Date());
  const selectedKey = selectedDay ? toLocalDate(selectedDay) : null;
  const selectedActivities = selectedKey ? (byDate[selectedKey] ?? []) : [];

  // ── Week grid ─────────────────────────────────────────────────────────────
  const weekDays = useMemo(() => {
    const { mon } = getWeekRange(currentDate);
    return Array.from({ length: 7 }, (_, i) => {
      const d = new Date(mon); d.setDate(mon.getDate() + i);
      return d;
    });
  }, [currentDate]);

  // ── Header title ─────────────────────────────────────────────────────────
  const headerTitle = view === 'month'
    ? `${MONTHS[currentDate.getMonth()]} ${currentDate.getFullYear()}`
    : (() => {
        const { mon, sun } = getWeekRange(currentDate);
        return `${mon.getDate()} ${MONTHS[mon.getMonth()].slice(0,3)} – ${sun.getDate()} ${MONTHS[sun.getMonth()].slice(0,3)} ${sun.getFullYear()}`;
      })();

  return (
    <div className="flex gap-4 h-[calc(100vh-3.5rem-2rem)]">

      {/* ── Main calendar ─────────────────────────────────────────────────── */}
      <div className="flex-1 flex flex-col bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden min-w-0">

        {/* Toolbar */}
        <div className="flex items-center justify-between px-5 py-3.5 border-b border-gray-100 shrink-0">
          <div className="flex items-center gap-3">
            <button onClick={() => navigate(-1)}
              className="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:bg-gray-100 transition-colors">
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
              </svg>
            </button>
            <h2 className="text-sm font-bold text-gray-900 min-w-[180px] text-center">{headerTitle}</h2>
            <button onClick={() => navigate(1)}
              className="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:bg-gray-100 transition-colors">
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7"/>
              </svg>
            </button>
            <button onClick={() => setCurrentDate(new Date())}
              className="text-xs px-2.5 py-1 rounded-lg bg-indigo-50 text-indigo-600 font-semibold hover:bg-indigo-100 transition-colors">
              Today
            </button>
          </div>

          {/* View toggle */}
          <div className="flex bg-gray-100 rounded-xl p-1 gap-1">
            {(['month', 'week'] as const).map((v) => (
              <button key={v} onClick={() => setView(v)}
                className={`px-3 py-1 rounded-lg text-xs font-semibold transition-all capitalize ${
                  view === v ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'
                }`}>
                {v}
              </button>
            ))}
          </div>
        </div>

        {/* Loading */}
        {isLoading && (
          <div className="flex-1 flex items-center justify-center">
            <svg className="w-8 h-8 animate-spin text-indigo-400" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
          </div>
        )}

        {/* ── MONTH VIEW ───────────────────────────────────────────────────── */}
        {!isLoading && view === 'month' && (
          <div className="flex-1 flex flex-col overflow-hidden">
            {/* Day headers */}
            <div className="grid grid-cols-7 border-b border-gray-100">
              {WEEKDAYS.map((d) => (
                <div key={d} className="py-2 text-center text-[11px] font-semibold text-gray-400 uppercase tracking-wide">
                  {d}
                </div>
              ))}
            </div>
            {/* Cells */}
            <div className="flex-1 grid grid-cols-7 auto-rows-fr overflow-hidden">
              {monthCells.map((date, idx) => {
                const key      = toLocalDate(date);
                const isToday  = key === today;
                const isOther  = date.getMonth() !== currentDate.getMonth();
                const isSel    = key === selectedKey;
                const dayActs  = byDate[key] ?? [];

                return (
                  <div
                    key={idx}
                    onClick={() => setSelectedDay(isSel ? null : date)}
                    className={`border-b border-r border-gray-50 p-1.5 cursor-pointer transition-colors relative group
                      ${isOther ? 'bg-gray-50/50' : 'hover:bg-indigo-50/30'}
                      ${isSel   ? 'bg-indigo-50/60 ring-1 ring-inset ring-indigo-300' : ''}
                    `}
                  >
                    {/* Date number */}
                    <div className="flex items-center justify-between mb-1">
                      <span className={`text-xs font-semibold w-6 h-6 flex items-center justify-center rounded-full
                        ${isToday ? 'bg-indigo-600 text-white' : isOther ? 'text-gray-300' : 'text-gray-700'}
                      `}>
                        {date.getDate()}
                      </span>
                      {/* Quick create */}
                      <button
                        onClick={(e) => { e.stopPropagation(); setCreateDate(date); }}
                        className="opacity-0 group-hover:opacity-100 w-4 h-4 rounded-full bg-indigo-100
                                   text-indigo-600 flex items-center justify-center text-xs font-bold transition-opacity"
                      >+</button>
                    </div>

                    {/* Activity dots */}
                    <div className="space-y-0.5">
                      {dayActs.slice(0, 3).map((a) => (
                        <div key={a.id} className={`flex items-center gap-1 px-1 py-0.5 rounded text-[10px] font-medium truncate
                          ${TYPE_COLORS[a.type]?.chip ?? 'bg-gray-100 text-gray-600'}`}>
                          <span className={`w-1.5 h-1.5 rounded-full shrink-0 ${TYPE_COLORS[a.type]?.dot ?? 'bg-gray-400'}`}/>
                          <span className="truncate">{a.title}</span>
                        </div>
                      ))}
                      {dayActs.length > 3 && (
                        <p className="text-[10px] text-gray-400 pl-1">+{dayActs.length - 3} more</p>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        )}

        {/* ── WEEK VIEW ────────────────────────────────────────────────────── */}
        {!isLoading && view === 'week' && (
          <div className="flex-1 overflow-auto">
            <div className="min-w-[600px]">
              {/* Day headers */}
              <div className="grid grid-cols-8 border-b border-gray-100 sticky top-0 bg-white z-10">
                <div className="py-2 border-r border-gray-100" />
                {weekDays.map((d, i) => {
                  const key = toLocalDate(d);
                  const isToday = key === today;
                  return (
                    <div key={i} className={`py-2 text-center border-r border-gray-100 ${isToday ? 'bg-indigo-50/60' : ''}`}>
                      <p className="text-[10px] font-semibold text-gray-400 uppercase">{WEEKDAYS[i]}</p>
                      <span className={`text-sm font-bold w-7 h-7 flex items-center justify-center rounded-full mx-auto mt-0.5
                        ${isToday ? 'bg-indigo-600 text-white' : 'text-gray-700'}`}>
                        {d.getDate()}
                      </span>
                    </div>
                  );
                })}
              </div>
              {/* Time rows */}
              {HOURS.map((hour) => (
                <div key={hour} className="grid grid-cols-8 border-b border-gray-50 min-h-[56px]">
                  <div className="border-r border-gray-100 px-2 py-1 text-[10px] text-gray-400 font-medium text-right">
                    {hour % 12 === 0 ? 12 : hour % 12}{hour < 12 ? 'am' : 'pm'}
                  </div>
                  {weekDays.map((d, di) => {
                    const key = toLocalDate(d);
                    const slotActs = (byDate[key] ?? []).filter((a) => {
                      if (!a.due_at) return false;
                      return new Date(a.due_at).getHours() === hour;
                    });
                    return (
                      <div
                        key={di}
                        onClick={() => { setCreateDate(d); }}
                        className="border-r border-gray-50 p-1 hover:bg-indigo-50/20 cursor-pointer transition-colors"
                      >
                        {slotActs.map((a) => {
                          const link = subjectLink(a);
                          const content = (
                            <span className="truncate">{a.title}</span>
                          );
                          return link ? (
                            <Link
                              key={a.id}
                              href={link.href}
                              onClick={(e) => e.stopPropagation()}
                              title={`${a.title} — ${link.label}`}
                              className={`flex items-center gap-1 text-[10px] font-medium px-1.5 py-1 rounded mb-0.5 truncate
                                hover:opacity-80 transition-opacity cursor-pointer
                                ${TYPE_COLORS[a.type]?.chip ?? 'bg-gray-100 text-gray-600'}`}
                            >
                              {content}
                              <svg className="w-2.5 h-2.5 shrink-0 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                              </svg>
                            </Link>
                          ) : (
                            <div
                              key={a.id}
                              onClick={(e) => e.stopPropagation()}
                              className={`text-[10px] font-medium px-1.5 py-1 rounded mb-0.5 truncate
                                ${TYPE_COLORS[a.type]?.chip ?? 'bg-gray-100 text-gray-600'}`}
                            >
                              {a.title}
                            </div>
                          );
                        })}
                      </div>
                    );
                  })}
                </div>
              ))}
            </div>
          </div>
        )}
      </div>

      {/* ── Side panel — selected day or create ───────────────────────────── */}
      <AnimatePresence>
        {(selectedDay || createDate) && (
          <motion.div
            initial={{ opacity: 0, x: 24 }}
            animate={{ opacity: 1, x: 0 }}
            exit={{ opacity: 0, x: 24 }}
            transition={{ type: 'spring', damping: 28, stiffness: 350 }}
            className="w-72 shrink-0 bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden flex flex-col"
          >
            {createDate ? (
              /* Create form */
              <CreateActivityPanel
                date={createDate}
                onClose={() => setCreateDate(null)}
                onCreated={() => {
                  qc.invalidateQueries({ queryKey: ['calendar-activities'] });
                  setCreateDate(null);
                }}
                assignedTo={user?.id}
              />
            ) : selectedDay ? (
              /* Day detail */
              <>
                <div className="px-4 py-3.5 border-b border-gray-100 flex items-center justify-between">
                  <div>
                    <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                      {WEEKDAYS[(selectedDay.getDay() + 6) % 7]}
                    </p>
                    <p className="text-base font-bold text-gray-900">
                      {selectedDay.getDate()} {MONTHS[selectedDay.getMonth()].slice(0,3)}
                    </p>
                  </div>
                  <div className="flex gap-1">
                    <button
                      onClick={() => setCreateDate(selectedDay)}
                      className="px-2 py-1 bg-indigo-600 text-white text-xs font-semibold rounded-lg hover:bg-indigo-700 transition-colors"
                    >+ Add</button>
                    <button onClick={() => setSelectedDay(null)}
                      className="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:bg-gray-100 transition-colors">
                      <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12"/>
                      </svg>
                    </button>
                  </div>
                </div>

                <div className="flex-1 overflow-y-auto">
                  {selectedActivities.length === 0 ? (
                    <div className="px-4 py-8 text-center">
                      <p className="text-sm text-gray-400">No activities this day</p>
                      <button
                        onClick={() => setCreateDate(selectedDay)}
                        className="mt-2 text-xs text-indigo-600 hover:underline font-medium"
                      >+ Schedule one</button>
                    </div>
                  ) : (
                    <div className="divide-y divide-gray-50 p-2 space-y-1">
                      {selectedActivities
                        .sort((a, b) => (a.due_at ?? '').localeCompare(b.due_at ?? ''))
                        .map((act) => {
                          const link = subjectLink(act);
                          return (
                            <div key={act.id} className={`p-2.5 rounded-xl border border-transparent hover:border-gray-100 hover:bg-gray-50/60 transition-colors ${act.is_done ? 'opacity-50' : ''}`}>
                              <div className="flex items-start gap-2">
                                {/* Done checkbox */}
                                <button
                                  onClick={() => !act.is_done && doneMutation.mutate(act.id)}
                                  disabled={act.is_done || doneMutation.isPending}
                                  className={`w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0 mt-0.5 transition-colors ${
                                    act.is_done
                                      ? 'bg-emerald-500 border-emerald-500'
                                      : 'border-gray-300 hover:border-indigo-400'
                                  }`}
                                >
                                  {act.is_done && (
                                    <svg className="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 13l4 4L19 7"/>
                                    </svg>
                                  )}
                                </button>

                                <div className="flex-1 min-w-0">
                                  {/* Title */}
                                  <p className={`text-xs font-semibold text-gray-900 ${act.is_done ? 'line-through' : ''}`}>
                                    {act.title}
                                  </p>

                                  {/* Type chip + time */}
                                  <div className="flex items-center gap-1.5 mt-1 flex-wrap">
                                    <span className={`text-[10px] px-1.5 py-0.5 rounded-full font-semibold
                                      ${TYPE_COLORS[act.type]?.chip ?? 'bg-gray-100 text-gray-600'}`}>
                                      {TYPE_COLORS[act.type]?.label ?? act.type}
                                    </span>
                                    {act.due_at && (
                                      <span className="text-[10px] text-gray-400">
                                        {new Date(act.due_at).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })}
                                      </span>
                                    )}
                                  </div>

                                  {/* Lead / Deal link */}
                                  {link && (
                                    <Link
                                      href={link.href}
                                      className="inline-flex items-center gap-1 mt-1.5 text-[11px] font-semibold text-indigo-600
                                                 hover:text-indigo-700 hover:underline transition-colors"
                                    >
                                      <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                          d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                      </svg>
                                      {link.label}
                                    </Link>
                                  )}
                                </div>
                              </div>
                            </div>
                          );
                        })}
                    </div>
                  )}
                </div>
              </>
            ) : null}
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}

// ── Create Activity Panel ─────────────────────────────────────────────────────

interface CreateProps {
  date:      Date;
  onClose:   () => void;
  onCreated: () => void;
  assignedTo?: number;
}

function CreateActivityPanel({ date, onClose, onCreated, assignedTo }: CreateProps) {
  const [title, setTitle]   = useState('');
  const [type, setType]     = useState<ActivityPayload['type']>('task');
  const [priority, setPriority] = useState<'low' | 'medium' | 'high'>('medium');
  const [dueAt, setDueAt]   = useState(() => {
    const d = new Date(date);
    d.setHours(9, 0, 0, 0);
    const offset = d.getTimezoneOffset();
    return new Date(d.getTime() - offset * 60_000).toISOString().slice(0, 16);
  });

  const MONTHS_SHORT = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

  const mutation = useMutation({
    mutationFn: (payload: ActivityPayload) => activitiesApi.create(payload),
    onSuccess:  onCreated,
  });

  const handleSubmit = () => {
    if (!title.trim()) return;
    mutation.mutate({
      type,
      title:        title.trim(),
      due_at:       dueAt,
      priority,
      assigned_to:  assignedTo,
    });
  };

  const inputCls = 'w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white';

  return (
    <>
      <div className="px-4 py-3.5 border-b border-gray-100 flex items-center justify-between">
        <div>
          <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide">New Activity</p>
          <p className="text-sm font-bold text-gray-900">
            {date.getDate()} {MONTHS_SHORT[date.getMonth()]} {date.getFullYear()}
          </p>
        </div>
        <button onClick={onClose}
          className="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:bg-gray-100 transition-colors">
          <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>

      <div className="flex-1 p-4 space-y-3 overflow-y-auto">
        <div>
          <label className="block text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Title *</label>
          <input
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            placeholder="Activity title"
            className={inputCls}
            autoFocus
          />
        </div>

        <div>
          <label className="block text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Type</label>
          <select value={type} onChange={(e) => setType(e.target.value as ActivityPayload['type'])} className={inputCls}>
            <option value="call">📞 Call</option>
            <option value="email">✉️ Email</option>
            <option value="meeting">🤝 Meeting</option>
            <option value="task">✓ Task</option>
            <option value="note">📝 Note</option>
            <option value="deadline">🚩 Deadline</option>
          </select>
        </div>

        <div>
          <label className="block text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Date & Time</label>
          <input type="datetime-local" value={dueAt} onChange={(e) => setDueAt(e.target.value)} className={inputCls} />
        </div>

        <div>
          <label className="block text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Priority</label>
          <select value={priority} onChange={(e) => setPriority(e.target.value as 'low' | 'medium' | 'high')} className={inputCls}>
            <option value="low">Low</option>
            <option value="medium">Medium</option>
            <option value="high">High</option>
          </select>
        </div>

        {mutation.isError && (
          <p className="text-xs text-red-500">Failed to create. Please try again.</p>
        )}
      </div>

      <div className="p-4 border-t border-gray-100 flex gap-2">
        <button
          onClick={handleSubmit}
          disabled={!title.trim() || mutation.isPending}
          className="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-xs font-semibold
                     py-2 rounded-xl transition-colors flex items-center justify-center gap-1.5"
        >
          {mutation.isPending && (
            <svg className="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
          )}
          {mutation.isPending ? 'Saving…' : 'Save Activity'}
        </button>
        <button onClick={onClose}
          className="px-3 py-2 text-xs text-gray-500 border border-gray-200 rounded-xl hover:text-gray-700 transition-colors">
          Cancel
        </button>
      </div>
    </>
  );
}
