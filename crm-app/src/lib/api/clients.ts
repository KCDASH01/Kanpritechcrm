import client from './client';
import type { ApiResponse, Client, Deal, Lead, PaginatedResponse } from '@/types';

export interface ClientDetails {
  client: Client;
  leads: Lead[];
  deals: Deal[];
  revenue: { one_time_revenue: number; recurring_revenue: number; total_revenue: number };
}

export const clientsApi = {
  list: (params?: { search?: string; per_page?: number; page?: number }) =>
    client.get<PaginatedResponse<Client>>('/clients', { params }).then((r) => r.data),
  get: (id: number) =>
    client.get<ApiResponse<ClientDetails>>(`/clients/${id}`).then((r) => r.data.data),
};
