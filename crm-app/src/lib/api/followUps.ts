import client from './client';
import type { PaginatedResponse, User } from '@/types';

export type FollowUpStatus = 'pending' | 'overdue' | 'done';
export type FollowUpDueFilter = 'today' | 'overdue' | 'all';

export interface FollowUp {
  id: number;
  activity_id: number;
  lead_id?: number;
  lead_name: string;
  company?: string;
  email?: string;
  phone?: string;
  title: string;
  due_at?: string;
  completed_at?: string;
  is_done: boolean;
  status: FollowUpStatus;
  assigned_to?: User;
}

export interface FollowUpFilters {
  search?: string;
  status?: FollowUpStatus;
  due?: FollowUpDueFilter;
  assigned_to?: number;
  unassigned?: boolean;
  page?: number;
  per_page?: number;
}

export const followUpsApi = {
  list: (filters?: FollowUpFilters) =>
    client.get<PaginatedResponse<FollowUp>>('/follow-ups', { params: filters }).then((r) => r.data),
};
