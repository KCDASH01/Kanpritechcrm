import client from './client';
import type { SalesTargetRow, MyTargetProgress, LeaderboardData, LeaderboardSettings } from '@/types';

export const salesTargetsApi = {
  // GET /sales-targets?month=YYYY-MM
  // Managers: all org users; team members: own only
  list: (params?: { month?: string; user_id?: number; department_id?: number; target_type?: string; status?: string; from?: string; to?: string }) =>
    client.get<{ data: SalesTargetRow[] }>('/sales-targets', { params }).then((r) => r.data.data),

  // POST /sales-targets — admin/owner only
  upsert: (payload: {
    target_id?: number;
    user_id: number;
    target_type: 'monthly' | 'custom';
    target_amount: number;
    receivable_amount: number;
    period_start: string;
    period_end?: string;
    notes?: string;
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

  details: (targetId: number) =>
    client.get<{ data: SalesTargetRow & { deals: Record<string, unknown>[]; payments: Record<string, unknown>[] } }>(`/sales-targets/${targetId}/details`).then((r) => r.data.data),

  leaderboard: (params?: { category?: string; period_scope?: string; department_id?: number; mode?: string }) =>
    client.get<{ data: LeaderboardData }>('/sales-targets/leaderboard', { params }).then((r) => r.data.data),

  leaderboardSettings: () =>
    client.get<{ data: LeaderboardSettings }>('/sales-targets/leaderboard-settings').then((r) => r.data.data),

  updateLeaderboardSettings: (payload: LeaderboardSettings) =>
    client.put<{ data: LeaderboardSettings }>('/sales-targets/leaderboard-settings', payload).then((r) => r.data.data),
};
