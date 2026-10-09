'use client';

import { useState, useMemo } from 'react';
import Link from 'next/link';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { motion, AnimatePresence } from 'framer-motion';
import { activitiesApi } from '@/lib/api/activities';
import { calendarApi, type CalendarEvent } from '@/lib/api/calendar';
import { useAuthStore } from '@/store/authStore';
import { AccessDenied } from '@/components/ui/AccessDenied';
import { CollectionCalendar } from '@/components/calendar/CollectionCalendar';

// ── Helpers ───────────────────────────────────────────────────────────────────

const TYPE_COLORS: Record<string, { dot: string; chip: string; label: string }> = {
  meeting: { dot: 'bg-blue-500',   chip: 'bg-blue-100 text-blue-700',     label: '🤝 Meeting'  },
  task:    { dot: 'bg-orange-500', chip: 'bg-orange-100 text-orange-700', label: '📅 Follow-up' },
};

const DONE_COLORS = {
  dot:  'bg-emerald-500',
  chip: 'bg-emerald-100 text-emerald-700',
  label: '✓ Done',
};

function eventColors(event: CalendarEvent): { dot: string; chip: string; label: string } {
  if (event.is_done || event.status === 'done') return DONE_COLORS;
  return TYPE_COLORS[event.type] ?? { dot: 'bg-gray-400', chip: 'bg-gray-100 text-gray-600', label: event.type };
}

function eventLabel(event: CalendarEvent): string {
  if (event.title?.trim()) return event.title;
  if (event.type === 'meeting') return `Meeting with ${event.lead_name}`;
  return `Follow up with ${event.lead_name}`;
}

/** Local calendar date YYYY-MM-DD (never use toISOString — that shifts the day in IST). */
function toLocalDate(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

/** Local calendar date from an API ISO datetime. */
function dueDateKey(iso: string): string {
  return toLocalDate(new Date(iso));
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

function leadLink(event: CalendarEvent): { href: string; label: string } {
  return { href: `/leads/${event.lead_id}`, label: event.lead_name || 'View Lead' };
}

function CalendarTabs({ active, onChange }: { active: 'collections' | 'activities'; onChange: (tab: 'collections' | 'activities') => void }) {
  return <div className="inline-flex max-w-full gap-1 rounded-xl border border-gray-200 bg-white p-1 shadow-sm">
    <button onClick={() => onChange('collections')} className={`rounded-lg px-3 py-2 text-xs font-semibold transition-colors sm:px-4 ${active === 'collections' ? 'bg-emerald-600 text-white shadow-sm' : 'text-gray-500 hover:bg-gray-50'}`}>Collections</button>
    <button onClick={() => onChange('activities')} className={`rounded-lg px-3 py-2 text-xs font-semibold transition-colors sm:px-4 ${active === 'activities' ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-500 hover:bg-gray-50'}`}>Follow-ups &amp; Meetings</button>
  </div>;
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

  const [activeTab, setActiveTab]   = useState<'collections' | 'activities'>('collections');
  const [view, setView]             = useState<'month' | 'week'>('month');
  const [currentDate, setCurrentDate] = useState(new Date());
  const [selectedDay, setSelectedDay] = useState<Date | null>(null);

  // ── Date range for query ──────────────────────────────────────────────────
  const { from, to } = useMemo(() => {
    if (view === 'month') {
      return getMonthRange(currentDate.getFullYear(), currentDate.getMonth());
    }
    const w = getWeekRange(currentDate);
    return { from: w.from, to: w.to };
  }, [view, currentDate]);

  const { data, isLoading } = useQuery({
    queryKey: ['calendar-events', from, to, assignedFilter],
    queryFn:  () => calendarApi.list({ date_from: from, date_to: to, assigned_to: assignedFilter }),
    enabled: activeTab === 'activities',
  });
  const events: CalendarEvent[] = data ?? [];

  // Group by local calendar date (not UTC slice of ISO string)
  const byDate = useMemo(() => {
    const map: Record<string, CalendarEvent[]> = {};
    events.forEach((event) => {
      if (!event.due_at) return;
      const key = dueDateKey(event.due_at);
      if (!map[key]) map[key] = [];
      map[key].push(event);
    });
    return map;
  }, [events]);

  // ── Mark done ─────────────────────────────────────────────────────────────
  const doneMutation = useMutation({
    mutationFn: activitiesApi.markDone,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['calendar-events'] });
      qc.invalidateQueries({ queryKey: ['follow-ups'] });
      qc.invalidateQueries({ queryKey: ['meetings'] });
      qc.invalidateQueries({ queryKey: ['schedule-alerts'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
    },
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

  if (activeTab === 'collections') {
    return <div className="space-y-4"><CalendarTabs active={activeTab} onChange={setActiveTab} /><CollectionCalendar canManage={canManage} /></div>;
  }

  return (
    <div className="space-y-4">
      <CalendarTabs active={activeTab} onChange={setActiveTab} />
      <div className="flex h-[calc(100dvh-9rem)] min-h-[560px] gap-4 lg:h-[calc(100dvh-9.5rem)]">

      {/* ── Main calendar ─────────────────────────────────────────────────── */}
      <div className="flex-1 flex flex-col bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden min-w-0">

        {/* Toolbar */}
        <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-2 py-3 sm:px-5 sm:py-3.5">
          <div className="flex min-w-0 items-center gap-1 sm:gap-3">
            <button onClick={() => navigate(-1)}
              className="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:bg-gray-100 transition-colors">
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
              </svg>
            </button>
            <h2 className="min-w-0 text-center text-xs font-bold text-gray-900 sm:min-w-[180px] sm:text-sm">{headerTitle}</h2>
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
                className={`px-2 py-1 rounded-lg text-xs font-semibold transition-all capitalize sm:px-3 ${
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
                    </div>

                    <div className="space-y-0.5">
                      {dayActs.slice(0, 3).map((event) => {
                        const colors = eventColors(event);
                        return (
                          <div key={event.id} className={`flex items-center gap-1 px-1 py-0.5 rounded text-[10px] font-medium truncate
                            ${colors.chip} ${event.is_done ? 'line-through opacity-90' : ''}`}>
                            <span className={`w-1.5 h-1.5 rounded-full shrink-0 ${colors.dot}`}/>
                            <span className="truncate">{eventLabel(event)}</span>
                          </div>
                        );
                      })}
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
                    const slotActs = (byDate[key] ?? []).filter((event) => {
                      if (!event.due_at) return false;
                      return new Date(event.due_at).getHours() === hour;
                    });
                    return (
                      <div
                        key={di}
                        onClick={() => setSelectedDay(d)}
                        className="border-r border-gray-50 p-1 hover:bg-indigo-50/20 cursor-pointer transition-colors"
                      >
                        {slotActs.map((event) => {
                          const link = leadLink(event);
                          const colors = eventColors(event);
                          return (
                            <Link
                              key={event.id}
                              href={link.href}
                              onClick={(e) => e.stopPropagation()}
                              title={`${eventLabel(event)} — ${link.label}`}
                              className={`flex items-center gap-1 text-[10px] font-medium px-1.5 py-1 rounded mb-0.5 truncate
                                hover:opacity-80 transition-opacity cursor-pointer
                                ${colors.chip} ${event.is_done ? 'line-through opacity-90' : ''}`}
                            >
                              <span className="truncate">{eventLabel(event)}</span>
                              <svg className="w-2.5 h-2.5 shrink-0 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                              </svg>
                            </Link>
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
        {selectedDay && (
          <motion.div
            initial={{ opacity: 0, x: 24 }}
            animate={{ opacity: 1, x: 0 }}
            exit={{ opacity: 0, x: 24 }}
            transition={{ type: 'spring', damping: 28, stiffness: 350 }}
            className="fixed inset-x-3 bottom-3 z-40 flex max-h-[65dvh] flex-col overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-2xl lg:static lg:z-auto lg:max-h-none lg:w-72 lg:shrink-0 lg:shadow-sm"
          >
            <div className="px-4 py-3.5 border-b border-gray-100 flex items-center justify-between">
              <div>
                <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                  {WEEKDAYS[(selectedDay.getDay() + 6) % 7]}
                </p>
                <p className="text-base font-bold text-gray-900">
                  {selectedDay.getDate()} {MONTHS[selectedDay.getMonth()].slice(0,3)}
                </p>
              </div>
              <button onClick={() => setSelectedDay(null)}
                className="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:bg-gray-100 transition-colors">
                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>

            <div className="flex-1 overflow-y-auto">
              {selectedActivities.length === 0 ? (
                <div className="px-4 py-8 text-center">
                  <p className="text-sm text-gray-400">No follow-ups or meetings this day</p>
                  <p className="text-xs text-gray-400 mt-2">
                    Schedule from a lead by setting its status to Follow-up or Meeting.
                  </p>
                </div>
              ) : (
                <div className="divide-y divide-gray-50 p-2 space-y-1">
                  {selectedActivities
                    .sort((a, b) => (a.due_at ?? '').localeCompare(b.due_at ?? ''))
                    .map((event) => {
                      const link = leadLink(event);
                      const colors = eventColors(event);
                      return (
                        <div key={event.id} className={`p-2.5 rounded-xl border border-transparent hover:border-gray-100 hover:bg-gray-50/60 transition-colors ${event.is_done ? 'opacity-80' : ''}`}>
                          <div className="flex items-start gap-2">
                            <button
                              onClick={() => !event.is_done && doneMutation.mutate(event.id)}
                              disabled={event.is_done || doneMutation.isPending}
                              className={`w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0 mt-0.5 transition-colors ${
                                event.is_done
                                  ? 'bg-emerald-500 border-emerald-500'
                                  : 'border-gray-300 hover:border-indigo-400'
                              }`}
                            >
                              {event.is_done && (
                                <svg className="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 13l4 4L19 7"/>
                                </svg>
                              )}
                            </button>

                            <div className="flex-1 min-w-0">
                              <p className={`text-xs font-semibold text-gray-900 ${event.is_done ? 'line-through' : ''}`}>
                                {eventLabel(event)}
                              </p>

                              <div className="flex items-center gap-1.5 mt-1 flex-wrap">
                                <span className={`text-[10px] px-1.5 py-0.5 rounded-full font-semibold ${colors.chip}`}>
                                  {event.is_done ? DONE_COLORS.label : (TYPE_COLORS[event.type]?.label ?? event.type)}
                                </span>
                                {event.due_at && (
                                  <span className="text-[10px] text-gray-400">
                                    {new Date(event.due_at).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })}
                                  </span>
                                )}
                              </div>

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
                            </div>
                          </div>
                        </div>
                      );
                    })}
                </div>
              )}
            </div>
          </motion.div>
        )}
      </AnimatePresence>
      </div>
    </div>
  );
}
