import client from './client';
import type { ApiResponse, DynamicTargetDefinition, DynamicTargetMetricSource, DynamicTargetProgress, DynamicTargetUnit } from '@/types';

export interface TargetDefinitionPayload {
  code: string;
  name: string;
  description?: string;
  unit_type: DynamicTargetUnit;
  unit_label?: string;
  metric_source: DynamicTargetMetricSource;
  direction: 'higher' | 'lower';
  is_active: boolean;
  department_ids: number[];
}

export interface TargetAssignmentPayload {
  scope_type: 'employee' | 'department_shared';
  user_id?: number;
  department_id?: number;
  period_type: 'monthly' | 'quarterly' | 'yearly' | 'custom';
  period_start: string;
  period_end: string;
  targets: { target_definition_id: number; target_value: number }[];
}

export const dynamicTargetsApi = {
  definitions: () => client.get<{ data: DynamicTargetDefinition[]; meta: { units: DynamicTargetUnit[]; metric_sources: Record<DynamicTargetMetricSource, string> } }>('/performance-targets/definitions').then((response) => response.data),
  createDefinition: (payload: TargetDefinitionPayload) => client.post<ApiResponse<DynamicTargetDefinition>>('/performance-targets/definitions', payload).then((response) => response.data.data),
  updateDefinition: (id: number, payload: TargetDefinitionPayload) => client.put<ApiResponse<DynamicTargetDefinition>>(`/performance-targets/definitions/${id}`, payload).then((response) => response.data.data),
  assignments: (params?: Record<string, string | number | undefined>) => client.get<ApiResponse<DynamicTargetProgress[]>>('/performance-targets/assignments', { params }).then((response) => response.data.data),
  createAssignments: (payload: TargetAssignmentPayload) => client.post('/performance-targets/assignments', payload).then((response) => response.data.data),
  updateAssignment: (id: number, payload: { target_value: number; period_type: string; period_start: string; period_end: string; status: string }) => client.put(`/performance-targets/assignments/${id}`, payload).then((response) => response.data.data),
  manualEntry: (id: number, payload: { entry_date: string; actual_value: number; notes?: string; user_id?: number }) => client.post(`/performance-targets/assignments/${id}/manual-entries`, payload).then((response) => response.data.data),
};
