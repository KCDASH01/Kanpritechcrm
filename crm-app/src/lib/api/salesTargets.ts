import client from './client';
import type { SalesTargetRow, MyTargetProgress } from '@/types';

export const salesTargetsApi = {
  // GET /sales-targets?month=YYYY-MM
  // Managers: all org users; team members: own only
  list: (params?: { month?: string }) =>
    client.get<{ data: SalesTargetRow[] }>('/sales-targets', { params }).then((r) => r.data.data),

  // POST /sales-targets — admin/owner only
  upsert: (payload: {
    user_id: number;
    target_amount: number;
    receivable_amount: number;
    period_start: string; // YYYY-MM-DD first of month
  }) => client.post('/sales-targets', payload).then((r) => r.data),

  // PATCH /sales-targets/{id}/received — admin OR own row
  updateReceived: (id: number, received_amount: number, notes?: string) =>
    client.patch(`/sales-targets/${id}/received`, { received_amount, notes }).then((r) => r.data),

  // GET /sales-targets/my-progress — last 6 months for current user
  myProgress: () =>
    client.get<{ data: MyTargetProgress[] }>('/sales-targets/my-progress').then((r) => r.data.data),

  // GET /sales-targets/{userId}/progress — last 6 months for any user (owner/admin only)
  userProgress: (userId: number) =>
    client
      .get<{ data: MyTargetProgress[]; user: { id: number; name: string; email: string } }>(
        `/sales-targets/${userId}/progress`
      )
      .then((r) => r.data),
};
