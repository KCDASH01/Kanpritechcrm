import client from './client';
import type { ApiResponse, Lead, PaginatedResponse } from '@/types';

export interface LeadDepartmentCount {
  id: number;
  name: string;
  leads_count: number;
}

export interface LeadDepartmentCounts {
  total: number;
  departments: LeadDepartmentCount[];
}

export interface LeadFilters {
  search?: string;
  status?: string;
  source?: string;
  types?: string;
  stage_id?: number;
  pipeline_id?: number;
  assigned_to?: number | null;
  unassigned?: boolean;
  department_id?: number;
  client_type?: string;
  business_type?: string;
  market_type?: string;
  date_from?: string;
  date_to?: string;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
  per_page?: number;
  page?: number;
}

export interface LeadPayload {
  client_id?: number | null;
  client_type: 'NEW' | 'EXISTING';
  business_type: 'ONE_TIME' | 'RECURRING';
  market_type: 'DOMESTIC' | 'INTERNATIONAL';
  first_name: string;
  last_name?: string;
  email?: string;
  phone?: string;
  company?: string;
  job_title?: string;
  website?: string;
  status?: string;
  source?: string;
  types?: string;
  industry?: string;
  city?: string;
  state?: string;
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
  remark?:     string;
  department_id?: number | null;
  expected_value?: number | null;
  currency?: 'INR' | 'USD';
  recurring_frequency?: 'MONTHLY' | 'QUARTERLY' | 'HALF_YEARLY' | 'YEARLY' | null;
  recurring_amount?: number | null;
  recurring_start_date?: string;
  recurring_end_type?: 'ONGOING' | 'FIXED';
  recurring_end_date?: string | null;
  next_billing_date?: string | null;
  billing_cycles?: number | null;
  contract_value?: number | null;
}

export interface LeadTimelineEntry {
  id:          number;
  action:      string;
  description: string;
  meta:        Record<string, unknown> | null;
  user:        { id: number; name: string } | null;
  created_at:  string;
}

export interface ConvertPaymentPayload {
  amount: number;
  payment_date: string;
  payment_mode: 'cash' | 'cheque' | 'bank_transfer' | 'upi' | 'card' | 'aggregator' | 'other';
  txn_or_utr_number?: string;
  notes?: string;
}

export interface ConvertPayload {
  pipeline_id: number;
  stage_id:    number;
  title?:      string;
  value?:      number;
  currency?:   'INR' | 'USD';
  payment?:    ConvertPaymentPayload;
}

export interface ConvertToDealResponse {
  data: unknown;
  message: string;
  deal_status?: string;
  deal_marked_won?: boolean;
}

export const leadsApi = {
  departmentCounts: () =>
    client.get<{ data: LeadDepartmentCounts }>('/leads/department-counts').then((r) => r.data.data),

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
    client.post<ConvertToDealResponse>(`/leads/${id}/convert`, payload).then((r) => r.data),
};
