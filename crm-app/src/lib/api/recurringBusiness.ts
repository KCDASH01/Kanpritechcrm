import client from './client';
import type { ApiResponse, RecurringBusiness, RecurringBusinessSummary } from '@/types';

export interface RecurringBusinessResponse {
  data: { summary: RecurringBusinessSummary; rows: RecurringBusiness[] };
  meta: { total: number; per_page: number; current_page: number; last_page: number };
}

export const recurringBusinessApi = {
  list: (params?: Record<string, string | number | undefined>) =>
    client.get<RecurringBusinessResponse>('/recurring-businesses', { params }).then((r) => r.data),
  get: (id: number) => client.get<ApiResponse<RecurringBusiness>>(`/recurring-businesses/${id}`).then((r) => r.data.data),
  update: (id: number, payload: Partial<Pick<RecurringBusiness, 'status' | 'next_billing_date' | 'end_date' | 'notes'>>) =>
    client.put<ApiResponse<RecurringBusiness>>(`/recurring-businesses/${id}`, payload).then((r) => r.data.data),
};
