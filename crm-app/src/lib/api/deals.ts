import client from './client';
import type { ApiResponse, Deal, DealPayment, PaginatedResponse } from '@/types';

export interface DealFilters {
  search?: string;
  status?: string;
  stage_id?: number;
  pipeline_id?: number;
  assigned_to?: number;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
  per_page?: number;
  page?: number;
}

export interface DealPayload {
  title: string;
  lead_id?: number;
  pipeline_id: number;
  stage_id: number;
  assigned_to?: number;
  value?: number;
  currency?: string;
  status?: string;
  expected_close_date?: string;
  description?: string;
  probability?: number;
  custom_fields?: Record<string, unknown>;
  lost_reason?: string;
  original_value?: number;
  counter_offer_value?: number;
  negotiation_notes?: string;
  handed_off_at?: string;
  source_deal_id?: number;
}

export const dealsApi = {
  list: (filters?: DealFilters) =>
    client.get<PaginatedResponse<Deal>>('/deals', { params: filters }).then((r) => r.data),

  get: (id: number) =>
    client.get<ApiResponse<Deal>>(`/deals/${id}`).then((r) => r.data.data),

  create: (payload: DealPayload) =>
    client.post<ApiResponse<Deal>>('/deals', payload).then((r) => r.data.data),

  update: (id: number, payload: Partial<DealPayload>) =>
    client.put<ApiResponse<Deal>>(`/deals/${id}`, payload).then((r) => r.data.data),

  moveStage: (id: number, stageId: number) =>
    client.patch<ApiResponse<Deal>>(`/deals/${id}/stage`, { stage_id: stageId }).then((r) => r.data.data),

  listPayments: (dealId: number) =>
    client.get<{ data: DealPayment[]; total: number }>(`/deals/${dealId}/payments`).then((r) => r.data),

  addPayment: (dealId: number, payload: {
    amount: number;
    payment_date: string;
    payment_mode: string;
    txn_or_utr_number?: string;
    notes?: string;
  }) =>
    client.post<{ data: DealPayment; message: string }>(`/deals/${dealId}/payments`, payload).then((r) => r.data.data),

  delete: (id: number) =>
    client.delete(`/deals/${id}`).then((r) => r.data),
};
