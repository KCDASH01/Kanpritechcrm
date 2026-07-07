import client from './client';
import type { Activity, ApiResponse, PaginatedResponse } from '@/types';

export interface ActivityPayload {
  subject_type?: 'lead' | 'deal';
  subject_id?: number;
  type: 'call' | 'email' | 'meeting' | 'task' | 'note' | 'deadline' | 'whatsapp';
  title: string;
  description?: string;
  due_at?: string;
  priority?: 'low' | 'medium' | 'high';
  assigned_to?: number;
  is_done?: boolean;
}

export interface ActivityFilters {
  type?: string;
  is_done?: boolean;
  overdue?: boolean;
  subject_type?: 'lead' | 'deal';
  subject_id?: number;
  assigned_to?: number;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
  per_page?: number;
  page?: number;
  date_from?: string;
  date_to?: string;
}

export const activitiesApi = {
  list: (filters?: ActivityFilters) =>
    client.get<PaginatedResponse<Activity>>('/activities', { params: filters }).then((r) => r.data),

  get: (id: number) =>
    client.get<ApiResponse<Activity>>(`/activities/${id}`).then((r) => r.data.data),

  create: (payload: ActivityPayload) =>
    client.post<ApiResponse<Activity>>('/activities', payload).then((r) => r.data.data),

  update: (id: number, payload: Partial<ActivityPayload>) =>
    client.put<ApiResponse<Activity>>(`/activities/${id}`, payload).then((r) => r.data.data),

  markDone: (id: number) =>
    client.patch<ApiResponse<Activity>>(`/activities/${id}/done`).then((r) => r.data.data),

  delete: (id: number) =>
    client.delete(`/activities/${id}`).then((r) => r.data),
};
