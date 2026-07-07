import client from './client';
import type { ApiResponse, Department } from '@/types';

export interface DepartmentPayload {
  name: string;
  description?: string;
  member_ids?: number[];
}

export const departmentsApi = {
  list: () =>
    client.get<ApiResponse<Department[]>>('/departments').then((r) => r.data.data),

  get: (id: number) =>
    client.get<ApiResponse<Department>>(`/departments/${id}`).then((r) => r.data.data),

  create: (payload: DepartmentPayload) =>
    client.post<ApiResponse<Department>>('/departments', payload).then((r) => r.data.data),

  update: (id: number, payload: DepartmentPayload) =>
    client.put<ApiResponse<Department>>(`/departments/${id}`, payload).then((r) => r.data.data),

  delete: (id: number) =>
    client.delete(`/departments/${id}`).then((r) => r.data),
};
