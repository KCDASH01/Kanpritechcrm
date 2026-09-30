import { followUpsApi, type FollowUp } from '@/lib/api/followUps';
import { meetingsApi, type Meeting } from '@/lib/api/meetings';

export type ScheduleAlertKind = 'followup' | 'meeting';
export type ScheduleAlertState = 'today' | 'missed';

export interface ScheduleAlertItem {
  id: string;
  kind: ScheduleAlertKind;
  state: ScheduleAlertState;
  lead_id: number;
  lead_name: string;
  title: string;
  due_at?: string;
  activity_id: number;
  company?: string;
}

export interface ScheduleAlertsData {
  upcoming: ScheduleAlertItem[];
  missed: ScheduleAlertItem[];
}

/** Show under Due today from 5 minutes before due_at until the due time. */
export const DUE_SOON_MS = 5 * 60 * 1000;

export function splitScheduleAlerts(
  upcoming: ScheduleAlertItem[],
  missed: ScheduleAlertItem[],
  nowMs: number = Date.now(),
): { dueToday: ScheduleAlertItem[]; missed: ScheduleAlertItem[] } {
  const justDue: ScheduleAlertItem[] = [];
  const dueToday: ScheduleAlertItem[] = [];

  for (const item of upcoming) {
    const t = item.due_at ? new Date(item.due_at).getTime() : NaN;
    if (!Number.isFinite(t)) continue;
    if (t <= nowMs) {
      justDue.push({ ...item, state: 'missed' });
    } else if (t - nowMs <= DUE_SOON_MS) {
      dueToday.push({ ...item, state: 'today' });
    }
  }

  const missedIds = new Set(missed.map((i) => i.id));
  const mergedMissed = [
    ...missed,
    ...justDue.filter((i) => !missedIds.has(i.id)),
  ];

  return {
    dueToday: sortByDueAt(dueToday, 'asc'),
    missed: sortByDueAt(mergedMissed, 'asc'),
  };
}

function mapFollowUp(item: FollowUp, state: ScheduleAlertState): ScheduleAlertItem {
  return {
    id:          `followup-${item.lead_id ?? item.id}`,
    kind:        'followup',
    state,
    lead_id:     item.lead_id ?? item.id,
    lead_name:   item.lead_name,
    title:       item.title,
    due_at:      item.due_at,
    activity_id: item.activity_id,
    company:     item.company,
  };
}

function mapMeeting(item: Meeting, state: ScheduleAlertState): ScheduleAlertItem {
  return {
    id:          `meeting-${item.lead_id ?? item.id}`,
    kind:        'meeting',
    state,
    lead_id:     item.lead_id ?? item.id,
    lead_name:   item.lead_name,
    title:       item.title,
    due_at:      item.due_at,
    activity_id: item.activity_id,
    company:     item.company,
  };
}

function sortByDueAt(items: ScheduleAlertItem[], dir: 'asc' | 'desc'): ScheduleAlertItem[] {
  return [...items].sort((a, b) => {
    const ta = a.due_at ? new Date(a.due_at).getTime() : 0;
    const tb = b.due_at ? new Date(b.due_at).getTime() : 0;
    return dir === 'asc' ? ta - tb : tb - ta;
  });
}

export const scheduleAlertsApi = {
  fetch: async (): Promise<ScheduleAlertsData> => {
    // Remaining today stays in memory so the FAB can surface a lead at T-5min
    // without a page refresh. The 5-minute window is applied in splitScheduleAlerts.
    const [fuToday, fuOverdue, mtToday, mtOverdue] = await Promise.all([
      followUpsApi.list({ due: 'today', per_page: 50 }),
      followUpsApi.list({ due: 'overdue', per_page: 50 }),
      meetingsApi.list({ due: 'today', per_page: 50 }),
      meetingsApi.list({ due: 'overdue', per_page: 50 }),
    ]);

    const upcoming = sortByDueAt([
      ...fuToday.data.map((r) => mapFollowUp(r, 'today')),
      ...mtToday.data.map((r) => mapMeeting(r, 'today')),
    ], 'asc');

    const missed = sortByDueAt([
      ...fuOverdue.data.map((r) => mapFollowUp(r, 'missed')),
      ...mtOverdue.data.map((r) => mapMeeting(r, 'missed')),
    ], 'asc');

    return { upcoming, missed };
  },
};
