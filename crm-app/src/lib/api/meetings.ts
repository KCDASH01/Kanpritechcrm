import client from './client';
import type { PaginatedResponse, User } from '@/types';

export type MeetingStatus = 'pending' | 'overdue' | 'done';
export type MeetingDueFilter = 'today' | 'overdue' | 'all';

export interface Meeting {
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
  status: MeetingStatus;
  assigned_to?: User;
}

export interface MeetingFilters {
  search?: string;
  status?: MeetingStatus;
  due?: MeetingDueFilter;
  assigned_to?: number;
  unassigned?: boolean;
  page?: number;
  per_page?: number;
}

export const meetingsApi = {
  list: (filters?: MeetingFilters) =>
    client.get<PaginatedResponse<Meeting>>('/meetings', { params: filters }).then((r) => r.data),
};
