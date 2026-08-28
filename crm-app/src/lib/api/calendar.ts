import client from './client';
import type { ActivityType, ApiResponse, Priority, User } from '@/types';

export type CalendarEventStatus = 'pending' | 'overdue' | 'done';

export interface CalendarEvent {
  id: number;
  type: ActivityType;
  title: string;
  description?: string;
  due_at?: string;
  completed_at?: string;
  is_done: boolean;
  priority: Priority;
  subject_type: string;
  subject_id: number;
  lead_id: number;
  lead_name: string;
  lead_status?: string;
  status: CalendarEventStatus;
  assigned_to?: User;
  created_by?: User;
  created_at: string;
}

export interface CalendarFilters {
  date_from: string;
  date_to: string;
  assigned_to?: number;
  unassigned?: boolean;
}

export const calendarApi = {
  list: (filters: CalendarFilters) =>
    client.get<ApiResponse<CalendarEvent[]>>('/calendar', { params: filters }).then((r) => r.data.data),
};
