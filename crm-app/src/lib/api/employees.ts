import client from './client';
import type { ApiResponse, User } from '@/types';

export interface EmployeePayload {
  name: string;
  email: string;
  password?: string;
  phone?: string;
  role?: 'admin' | 'employee';
  avatar?: string;
}

export interface EmployeeRemovalPayload {
  lead_action: 'transfer' | 'unassign';
  transfer_to?: number | null;
  reason?: string;
}

export const employeesApi = {
  list: (params?: { role?: string; include_inactive?: boolean }) =>
    client.get<ApiResponse<User[]>>('/employees', { params }).then((r) => r.data.data),

  get: (id: number) =>
    client.get<ApiResponse<User>>(`/employees/${id}`).then((r) => r.data.data),

  create: (payload: EmployeePayload) =>
    client.post<ApiResponse<User>>('/employees', payload).then((r) => r.data.data),

  update: (id: number, payload: Partial<EmployeePayload>) =>
    client.put<ApiResponse<User>>(`/employees/${id}`, payload).then((r) => r.data.data),

  removalPreview: (id: number) =>
    client.get<{ data: { employee_id: number; assigned_leads: number } }>(`/employees/${id}/removal-preview`).then((r) => r.data.data),

  delete: (id: number, payload: EmployeeRemovalPayload) =>
    client.delete(`/employees/${id}`, { data: payload }).then((r) => r.data),

  deactivate: (id: number, payload: EmployeeRemovalPayload) =>
    client.post(`/employees/${id}/deactivate`, payload).then((r) => r.data),

  reactivate: (id: number) =>
    client.post<ApiResponse<User>>(`/employees/${id}/reactivate`).then((r) => r.data.data),
};
