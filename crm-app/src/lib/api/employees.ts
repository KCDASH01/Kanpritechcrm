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

export const employeesApi = {
  list: (params?: { role?: string }) =>
    client.get<ApiResponse<User[]>>('/employees', { params }).then((r) => r.data.data),

  get: (id: number) =>
    client.get<ApiResponse<User>>(`/employees/${id}`).then((r) => r.data.data),

  create: (payload: EmployeePayload) =>
    client.post<ApiResponse<User>>('/employees', payload).then((r) => r.data.data),

  update: (id: number, payload: Partial<EmployeePayload>) =>
    client.put<ApiResponse<User>>(`/employees/${id}`, payload).then((r) => r.data.data),

  delete: (id: number) =>
    client.delete(`/employees/${id}`).then((r) => r.data),
};
