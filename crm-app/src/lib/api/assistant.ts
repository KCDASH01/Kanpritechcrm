import client from './client';

export type AssistantSeverity = 'critical' | 'urgent' | 'important';
export type TargetCoachStatus = 'upcoming' | 'on_track' | 'at_risk' | 'behind' | 'achieved' | 'exceeded' | 'unavailable';

export interface AssistantActivityAlert {
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

export interface AssistantCollectionAlert {
  id: string;
  type: 'recurring_collection' | 'deal_collection' | 'invoice_collection';
  severity: AssistantSeverity;
  title: string;
  record_id: number;
  amount: number;
  currency: string;
  due_at?: string | null;
  navigation: string;
}

export type AssistantAlert = AssistantActivityAlert | AssistantCollectionAlert;
export type AssistantSound = 'success' | 'alert' | 'celebrate';

export interface AssistantSoundSettings {
  enabled: boolean;
  success: boolean;
  alert: boolean;
  celebrate: boolean;
  volume: 'low' | 'medium' | 'high';
  play_when_hidden: boolean;
  buddy_interval_seconds: number;
  coach_interval_seconds: number;
}

export const assistantSoundKey = (organizationId?: number, userId?: number) =>
  ['assistant-sound-settings', organizationId, userId] as const;

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
  alerts: (trackedIds: number[], signal?: AbortSignal) => client.get<{
    data: AssistantActivityAlert[];
    collections?: AssistantCollectionAlert[];
    completed_activity_ids?: number[];
  }>('/assistant/alerts', {
    params: { include_collections: 1, tracked_activity_ids: trackedIds }, signal,
  }).then((response) => response.data),
  targets: (userId?: number, signal?: AbortSignal) => client.get<{ data: TargetCoachData }>('/assistant/targets', {
    params: userId ? { user_id: userId } : undefined,
    signal,
  }).then((response) => response.data.data),
  soundSettings: (signal?: AbortSignal) => client.get<{ data: AssistantSoundSettings }>('/organization/assistant-sound-settings', { signal }).then((response) => response.data.data),
  updateSoundSettings: (settings: AssistantSoundSettings) => client.put<{ data: AssistantSoundSettings }>('/organization/assistant-sound-settings', settings).then((response) => response.data.data),
};
