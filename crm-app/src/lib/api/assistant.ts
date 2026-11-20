import client from './client';

export type AssistantSeverity = 'critical' | 'urgent' | 'important';
export type TargetCoachStatus = 'upcoming' | 'on_track' | 'at_risk' | 'behind' | 'achieved' | 'exceeded' | 'unavailable';

export interface AssistantAlert {
  id: string;
  type: 'overdue_followup' | 'meeting_attention' | 'upcoming_meeting' | 'urgent_activity' | 'followup_due';
  severity: AssistantSeverity;
  title: string;
  record_id: number;
  lead_id: number;
  lead_name: string;
  company?: string | null;
  due_at?: string | null;
  navigation: '/follow-ups' | '/meetings' | '/activities';
}

export interface TargetCoachTarget {
  id: number | string;
  target_name: string;
  scope_type: 'employee' | 'department_shared';
  user?: { id: number; name: string } | null;
  department?: { id: number; name: string } | null;
  period_type: string;
  period_start: string;
  period_end: string;
  target_value: number;
  actual_value: number;
  remaining_value: number;
  achievement_percentage?: number | null;
  unit_type: string;
  unit_label?: string | null;
  metric_source: string;
  direction: 'higher' | 'lower';
  elapsed_working_days: number;
  remaining_working_days: number;
  actual_daily_run_rate?: number | null;
  required_daily_run_rate?: number | null;
  projected_value?: number | null;
  projected_shortfall?: number | null;
  projected_percentage?: number | null;
  projection_status: TargetCoachStatus;
  projection_available: boolean;
  projection_note?: string | null;
  source: 'legacy' | 'dynamic';
}

export interface TargetCoachData {
  targets: TargetCoachTarget[];
  meta: { timezone: string; working_day_rule: string; as_of: string };
}

export const assistantApi = {
  alerts: () => client.get<{ data: AssistantAlert[] }>('/assistant/alerts').then((response) => response.data.data),
  targets: (userId?: number) => client.get<{ data: TargetCoachData }>('/assistant/targets', {
    params: userId ? { user_id: userId } : undefined,
  }).then((response) => response.data.data),
};
