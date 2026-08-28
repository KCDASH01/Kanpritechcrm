import client from './client';
import type { PaginatedResponse, User } from '@/types';

export type ImportantStatus = 'pending' | 'done';

export interface ImportantLead {
  id: number;
  activity_id: number;
  lead_id?: number;
  lead_name: string;
  company?: string;
  email?: string;
  phone?: string;
  types?: string | null;
  title: string;
  remark?: string | null;
  marked_at?: string;
  lead_date?: string;
  is_done: boolean;
  status: ImportantStatus;
  assigned_to?: User;
}

export interface ImportantFilters {
  search?: string;
  status?: ImportantStatus;
  types?: string;
  date_from?: string;
  date_to?: string;
  assigned_to?: number;
  unassigned?: boolean;
  page?: number;
  per_page?: number;
}

export const importantApi = {
  list: (filters?: ImportantFilters) =>
    client.get<PaginatedResponse<ImportantLead>>('/important', { params: filters }).then((r) => r.data),
};
