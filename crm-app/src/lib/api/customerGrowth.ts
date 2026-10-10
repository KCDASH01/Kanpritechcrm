import client from './client';

export interface GrowthMoney { currency: string; amount: number }
export interface GrowthRecommendation {
  id: number; client_id: number; client?: { company?: string; full_name?: string; first_name: string; last_name?: string };
  assigned_to?: { id: number; name: string }; existing_service: string; suggested_service: string;
  recommendation_type: 'cross_sell' | 'upsell'; reason: string; potential_value?: string | number | null;
  currency?: string | null; estimate_source?: string | null; priority: 'low' | 'medium' | 'high';
  suggested_follow_up_date?: string | null; status: string; converted_deal_id?: number | null;
}
export interface HealthRow { client_id: number; client_name: string; assigned_employee?: string; status: string; reasons: string[] }
export interface RenewalRow { id: number; client_id: number; client_name: string; service: string; start_date?: string; renewal_date?: string; contract_value?: string | number; currency: string; responsible_employee?: string; days_remaining: number; status: string }
export interface GrowthSettings {
  cross_sell_enabled: boolean; upsell_enabled: boolean; renewal_reminders_enabled: boolean; health_alerts_enabled: boolean;
  reminder_intervals: number[]; service_mappings: Array<{ source: string; target: string; type: 'cross_sell' | 'upsell'; reason?: string; priority?: string }>;
  health_thresholds: Record<string, number | null>; notification_channels: string[]; default_assignment: string;
}
export interface GrowthOverview {
  summary: { total: number; potential_revenue: GrowthMoney[]; cross_sell: number; upsell: number; converted: number; actual_won_value: GrowthMoney[] };
  recommendations: GrowthRecommendation[]; health: HealthRow[]; renewals: RenewalRow[]; settings: GrowthSettings;
}
export interface LinkableDeal { id: number; title: string; value: string | number; currency: string; status: 'open' | 'won' | 'lost'; closed_at?: string | null }

export const customerGrowthApi = {
  overview: (params?: Record<string, string | number | undefined>) => client.get<{ data: GrowthOverview }>('/customer-growth', { params }).then((r) => r.data.data),
  refresh: () => client.post<{ data: { created: number } }>('/customer-growth/refresh').then((r) => r.data.data),
  updateRecommendation: (id: number, payload: Record<string, unknown>) => client.patch(`/customer-growth/recommendations/${id}`, payload).then((r) => r.data.data),
  candidateDeals: (id: number) => client.get<{ data: LinkableDeal[] }>(`/customer-growth/recommendations/${id}/deals`).then((r) => r.data.data),
  linkDeal: (id: number, dealId: number) => client.post(`/customer-growth/recommendations/${id}/link-deal`, { deal_id: dealId }).then((r) => r.data.data),
  createRetentionTask: (payload: Record<string, unknown>) => client.post('/customer-growth/retention-tasks', payload).then((r) => r.data.data),
  getSettings: () => client.get<{ data: GrowthSettings }>('/customer-growth/settings').then((r) => r.data.data),
  updateSettings: (payload: Partial<GrowthSettings>) => client.put<{ data: GrowthSettings }>('/customer-growth/settings', payload).then((r) => r.data.data),
};

