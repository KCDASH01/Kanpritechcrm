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
  dueToday: ScheduleAlertItem[];
  missed: ScheduleAlertItem[];
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
    const [fuToday, fuOverdue, mtToday, mtOverdue] = await Promise.all([
      followUpsApi.list({ due: 'today', per_page: 50 }),
      followUpsApi.list({ due: 'overdue', per_page: 50 }),
      meetingsApi.list({ due: 'today', per_page: 50 }),
      meetingsApi.list({ due: 'overdue', per_page: 50 }),
    ]);

    const dueToday = sortByDueAt([
      ...fuToday.data.map((r) => mapFollowUp(r, 'today')),
      ...mtToday.data.map((r) => mapMeeting(r, 'today')),
    ], 'asc');

    const missed = sortByDueAt([
      ...fuOverdue.data.map((r) => mapFollowUp(r, 'missed')),
      ...mtOverdue.data.map((r) => mapMeeting(r, 'missed')),
    ], 'asc');

    return { dueToday, missed };
  },
};
