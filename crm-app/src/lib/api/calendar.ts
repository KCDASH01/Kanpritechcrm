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

export interface CollectionAmount {
  currency: string;
  amount: number;
}

export interface CollectionCalendarDay {
  date: string;
  transaction_count: number;
  totals: CollectionAmount[];
}

export interface CollectionCalendarTransaction {
  id: number;
  payment_date: string;
  client_name: string | null;
  deal_id: number;
  deal_reference: string | null;
  amount: number;
  currency: string;
  payment_method: string;
  responsible_employee: string | null;
  payment_status: 'received';
  deal_status: string | null;
}

export interface CollectionCalendarData {
  month: string;
  summary: {
    collection_days: number;
    transaction_count: number;
    totals: CollectionAmount[];
  };
  daily: CollectionCalendarDay[];
  transactions: CollectionCalendarTransaction[];
}

export const calendarApi = {
  list: (filters: CalendarFilters) =>
    client.get<ApiResponse<CalendarEvent[]>>('/calendar', { params: filters }).then((r) => r.data.data),
  collections: (filters: { month: string; assigned_to?: number }) =>
    client.get<ApiResponse<CollectionCalendarData>>('/calendar/collections', { params: filters }).then((r) => r.data.data),
};
