import client from './client';
import type { ReportsData } from '@/types';

export const reportsApi = {
  get: (params?: { assigned_to?: number }) =>
    client.get<{ data: ReportsData }>('/reports', { params }).then((r) => r.data.data),
};
