import client from './client';

export type IntegrationProvider = 'meta' | 'whatsapp' | 'google' | 'microsoft' | 'email';
export interface IntegrationConnection { id: number; provider: IntegrationProvider; name: string; status: string; account_email?: string | null; external_account_id?: string | null; last_synced_at?: string | null; last_error?: string | null; settings?: Record<string, unknown> | null }
export interface IntegrationCampaign { id: number; platform: IntegrationProvider; external_id?: string | null; name: string; status: string; assigned_to?: { id: number; name: string } | null; start_date?: string | null; end_date?: string | null; target_market?: string | null; service?: string | null }
export interface RoutingRule { id: number; name: string; source_type: string; source_key?: string | null; strategy: 'fixed' | 'round_robin'; priority: number; is_active: boolean; assigned_to?: { id: number; name: string } | null; backup_user?: { id: number; name: string } | null; department?: { id: number; name: string } | null }
export interface IntegrationEvent { id: number; provider: IntegrationProvider; event_type: string; status: string; classification?: string | null; assignment_reason?: string | null; error_message?: string | null; created_at: string; lead?: { id: number; first_name: string; last_name?: string | null } | null; assigned_to?: { id: number; name: string } | null; campaign?: { id: number; name: string } | null }
export interface ReviewItem { id: number; reason: string; status: string; source_summary?: Record<string, unknown>; extracted_data?: Record<string, unknown>; created_at: string; event?: { id: number; provider: string; event_type: string; status: string } | null }
export interface IntegrationSettings { default_owner_id?: number | null; meta_enabled: boolean; whatsapp_enabled: boolean; email_enabled: boolean; auto_import_enabled: boolean; review_unmatched: boolean; raw_content_retention_days: number; field_mappings?: Record<string, unknown> | null }
export interface IntegrationOverview { summary: { total_leads: number; qualified_leads: number; meta_leads: number; email_leads: number; campaign_replies: number; interested_replies: number; review_required: number; assigned: number; deals_created: number; deals_won: number; conversion_rate: number; revenue_collected: Record<string, string | number> }; source_performance: Array<{ provider: string; leads: number }>; connections: IntegrationConnection[]; campaigns: IntegrationCampaign[]; routing_rules: RoutingRule[]; settings: IntegrationSettings | null; provider_readiness: Record<string, boolean> }

export const leadIntegrationsApi = {
  overview: (params?: Record<string, string | number | undefined>) => client.get<{ data: IntegrationOverview }>('/lead-integrations', { params }).then((r) => r.data.data),
  history: (params?: Record<string, string>) => client.get<{ data: IntegrationEvent[] }>('/lead-integrations/history', { params }).then((r) => r.data.data),
  reviewQueue: () => client.get<{ data: ReviewItem[] }>('/lead-integrations/review-queue').then((r) => r.data.data),
  oauthUrl: (provider: 'meta' | 'google' | 'microsoft') => client.get<{ data: { authorization_url: string } }>(`/lead-integrations/oauth/${provider}`).then((r) => r.data.data),
  createInboundEmail: (payload: Record<string, unknown>) => client.post('/lead-integrations/connections', payload).then((r) => r.data.data),
  disconnect: (id: number) => client.delete(`/lead-integrations/connections/${id}`),
  syncMetaHistory: (id: number, payload: { form_id: string; date_from: string; date_to: string }) => client.post(`/lead-integrations/connections/${id}/meta-history`, payload).then((r) => r.data.data),
  createCampaign: (payload: Record<string, unknown>) => client.post('/lead-integrations/campaigns', payload).then((r) => r.data.data),
  deleteCampaign: (id: number) => client.delete(`/lead-integrations/campaigns/${id}`),
  registerRecipients: (id: number, recipients: Array<{ email: string; message_id?: string; thread_id?: string; lead_id?: number }>) => client.post(`/lead-integrations/campaigns/${id}/recipients`, { recipients }).then((r) => r.data.data),
  createRule: (payload: Record<string, unknown>) => client.post('/lead-integrations/routing-rules', payload).then((r) => r.data.data),
  deleteRule: (id: number) => client.delete(`/lead-integrations/routing-rules/${id}`),
  updateSettings: (payload: Partial<IntegrationSettings>) => client.put('/lead-integrations/settings', payload).then((r) => r.data.data),
  resolveReview: (id: number, payload: Record<string, unknown>) => client.post(`/lead-integrations/review-queue/${id}/resolve`, payload).then((r) => r.data.data),
};
