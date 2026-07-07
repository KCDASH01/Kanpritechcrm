import client from './client';
import type { ApiResponse, Lead, PaginatedResponse } from '@/types';

export interface LeadFilters {
  search?: string;
  status?: string;
  source?: string;
  stage_id?: number;
  pipeline_id?: number;
  assigned_to?: number | null;
  unassigned?: boolean;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
  per_page?: number;
  page?: number;
}

export interface LeadPayload {
  first_name: string;
  last_name?: string;
  email?: string;
  phone?: string;
  company?: string;
  job_title?: string;
  website?: string;
  status?: string;
  source?: string;
  industry?: string;
  city?: string;
  country?: string;
  notes?: string;
  score?: number;
  pipeline_id?: number;
  stage_id?: number;
  assigned_to?: number | null;
  custom_fields?: Record<string, unknown>;
  external_lead_id?: string;
  lost_reason?: string;
  schedule_at?: string;
}

export interface LeadTimelineEntry {
  id:          number;
  action:      string;
  description: string;
  meta:        Record<string, unknown> | null;
  user:        { id: number; name: string } | null;
  created_at:  string;
}

export interface ConvertPayload {
  pipeline_id: number;
  stage_id:    number;
  title?:      string;
  value?:      number;
}

export const leadsApi = {
  list: (filters?: LeadFilters) =>
    client.get<PaginatedResponse<Lead>>('/leads', { params: filters }).then((r) => r.data),

  get: (id: number) =>
    client.get<ApiResponse<Lead>>(`/leads/${id}`).then((r) => r.data.data),

  create: (payload: LeadPayload) =>
    client.post<ApiResponse<Lead>>('/leads', payload).then((r) => r.data.data),

  update: (id: number, payload: Partial<LeadPayload>) =>
    client.put<ApiResponse<Lead>>(`/leads/${id}`, payload).then((r) => r.data.data),

  delete: (id: number) =>
    client.delete(`/leads/${id}`).then((r) => r.data),

  bulkImport: (leads: Partial<LeadPayload>[]) =>
    client.post<{ message: string; count: number }>('/leads/bulk-import', { leads }).then((r) => r.data),

  timeline: (id: number) =>
    client.get<{ data: LeadTimelineEntry[] }>(`/leads/${id}/timeline`).then((r) => r.data.data),

  convertToDeal: (id: number, payload: ConvertPayload) =>
    client.post(`/leads/${id}/convert`, payload).then((r) => r.data),
};
