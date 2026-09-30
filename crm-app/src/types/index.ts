// ── Organization ──────────────────────────────────────────────────────────────
export interface Organization {
  id: number;
  name: string;
  slug: string;
  email: string;
  phone?: string;
  website?: string;
  logo?: string;
  address?: string;
  city?: string;
  country?: string;
  timezone: string;
  is_active: boolean;
}

// ── Subscription ──────────────────────────────────────────────────────────────
export interface Subscription {
  id: number;
  plan: 'free' | 'business' | 'enterprise';
  subscription_source: 'internal' | 'external';
  status: 'active' | 'cancelled' | 'expired' | 'trialing';
  is_active: boolean;
  start_date: string | null;
  end_date: string | null;
  is_expired: boolean;
  gateway?: string;
  amount?: number;
  currency?: string;
  // Seat billing fields
  extra_members_purchased?: number;
  total_team_limit?: number;
  extra_member_price?: number;
}

// ── Subscription Payment ──────────────────────────────────────────────────────
export interface SubscriptionPayment {
  id: number;
  type: 'plan_upgrade' | 'seat_purchase';
  description: string;
  gateway: string;
  gateway_order_id?: string;
  gateway_payment_id?: string;
  quantity?: number;
  amount: number;
  currency: string;
  status: 'pending' | 'completed' | 'failed';
  valid_until?: string;
  created_at: string;
  user?: { name: string; email: string };
  organization?: { name: string; email: string };
}

// ── User ──────────────────────────────────────────────────────────────────────
export interface User {
  id: number;
  name: string;
  email: string;
  phone?: string;
  avatar?: string;
  role: 'owner' | 'admin' | 'employee';
  is_sso_user: boolean;
  has_password: boolean;
  sso_provider?: string;
  is_active: boolean;
  organization?: Organization;
  subscription?: Subscription;
  created_at: string;
}

// ── Pipeline & Stage ──────────────────────────────────────────────────────────
export interface Stage {
  id: number;
  pipeline_id: number;
  name: string;
  color: string;
  sort_order: number;
  probability: number;
  is_won: boolean;
  is_lost: boolean;
}

export interface Pipeline {
  id: number;
  name: string;
  description?: string;
  is_default: boolean;
  sort_order: number;
  stages: Stage[];
}

// ── Lead ──────────────────────────────────────────────────────────────────────
export interface Lead {
  id: number;
  full_name: string;
  first_name: string;
  last_name?: string;
  email?: string;
  phone?: string;
  company?: string;
  job_title?: string;
  website?: string;
  status: 'new' | 'contacted' | 'ringing' | 'important' | 'converted' | 'lost' | 'followup' | 'meeting' | 'not_interested';
  source?: string;
  types?: 'webapp_development' | 'mobile_app_development' | 'website_development' | 'digital_marketing' | 'others' | null;
  industry?: string;
  city?: string;
  country?: string;
  notes?: string;
  score?: number;
  pipeline_id?: number;
  stage_id?: number;
  external_lead_id?: string;
  custom_fields?: Record<string, unknown>;
  lost_reason?: string;
  lead_date?: string;
  proposals_count?: number;
  assigned_to?: User;
  created_by?: User;
  stage?: Stage;
  pipeline?: Pipeline;
  created_at: string;
  updated_at: string;
}

// ── Deal ──────────────────────────────────────────────────────────────────────
export interface Deal {
  id: number;
  title: string;
  value?: number;
  currency: string;
  status: 'open' | 'won' | 'lost';
  lost_reason?: string;
  original_value?: number;
  counter_offer_value?: number;
  negotiation_notes?: string;
  probability?: number;
  description?: string;
  expected_close_date?: string;
  closed_at?: string;
  handed_off_at?: string;
  total_received?: number | null;
  payments_count?: number;
  custom_fields?: Record<string, unknown>;
  pipeline_id: number;
  stage_id: number;
  lead_id?: number;
  lead?: Lead;
  stage?: Stage;
  pipeline?: Pipeline;
  assigned_to?: User;
  created_by?: User;
  created_at: string;
  updated_at: string;
}

// ── Deal Payment ──────────────────────────────────────────────────────────────
export interface DealPayment {
  id: number;
  deal_id?: number;
  amount: number;
  payment_date: string;
  payment_mode: 'cash' | 'cheque' | 'bank_transfer' | 'upi' | 'card' | 'aggregator' | 'other';
  txn_or_utr_number?: string | null;
  notes?: string | null;
  created_by?: { id: number; name: string } | null;
  created_at: string;
}

// ── Activity ──────────────────────────────────────────────────────────────────
export type ActivityType = 'call' | 'email' | 'meeting' | 'task' | 'note' | 'deadline' | 'whatsapp';
export type Priority = 'low' | 'medium' | 'high';

export interface Activity {
  id: number;
  type: ActivityType;
  title: string;
  description?: string;
  due_at?: string;
  completed_at?: string;
  is_done: boolean;
  priority: Priority;
  subject_type: string;
  subject_id: number;
  assigned_to?: User;
  created_by?: User;
  created_at: string;
}

// ── Note ──────────────────────────────────────────────────────────────────────
export interface Note {
  id: number;
  content: string;
  is_pinned: boolean;
  notable_type: string;
  notable_id: number;
  created_by?: User;
  created_at: string;
  updated_at: string;
}

// ── Department ────────────────────────────────────────────────────────────────
export interface Department {
  id: number;
  name: string;
  description?: string;
  members: User[];
  members_count?: number;
  created_at: string;
}

// ── Sales Targets ─────────────────────────────────────────────────────────────
export interface SalesTarget {
  id: number;
  user_id: number;
  target_amount: number;
  receivable_amount: number;
  received_amount: number | null;
  notes?: string;
  period_start: string;
  user?: { id: number; name: string };
}

// Single-month progress row returned by /sales-targets (list)
export interface SalesTargetRow {
  id: number;
  user: { id: number; name: string } | null;
  target_amount: number;
  receivable_amount: number;
  received_amount: number | null;
  achieved_amount: number;
  notes?: string;
  period_start: string;
}

// 6-month history row returned by /sales-targets/my-progress
export interface MyTargetProgress {
  period_start: string;
  target_amount: number;
  receivable_amount: number;
  received_amount: number | null;
  achieved_amount: number;
  target_id: number | null;
}

// Embedded in DashboardData for team-member view
export interface TargetProgress {
  target_amount: number;
  receivable_amount: number;
  received_amount: number | null;
  achieved_amount: number;
  target_id: number;
}

// ── Proposals ─────────────────────────────────────────────────────────────────
export type ProposalTheme  = 'modern' | 'corporate' | 'minimal' | 'vibrant';
export type ProposalStatus = 'draft' | 'sent' | 'accepted' | 'rejected';

export interface ProposalPainPoint {
  title: string;
  points: string[];
}

export interface ProposalPlatformTech {
  label: string;
  value: string;
}

export interface ProposalMaintenanceSupport {
  period: string;
  includes: string[];
}

export interface ProposalScopeItem {
  item: string;
  description: string;
  key_deliverables?: string[];
}

export interface ProposalTimelineItem {
  phase: string;
  duration: string;
  deliverables: string;
  start_week?: number;
  end_week?: number;
}

export interface ProposedSolution {
  overview: string;
  approach: string;
  technology_stack: string[];
  key_features: { feature: string; benefit: string }[];
  differentiators: string;
  success_metrics: { metric: string; target: string }[];
  implementation_approach: string;
}

export interface ProposalResourceBreakdown {
  resource: string;
  rate: number;
  hours: number;
  amount: number;
}

export interface ProposalInvestment {
  resource_breakdown?: ProposalResourceBreakdown[];
  breakdown: { item: string; amount: number }[];
  total: number;
  currency: string;
  payment_terms: string;
}

export interface ProposalContent {
  title: string;
  executive_summary: string;
  about_us?: string;
  why_choose_us?: string[];
  understanding: string;
  client_pain_points?: ProposalPainPoint[];
  key_benefits?: { title: string; description: string }[];
  proposed_solution: ProposedSolution | string;   // string = legacy pre-v2 proposals
  scope_of_work: ProposalScopeItem[];
  timeline: ProposalTimelineItem[];
  team?: { role: string; responsibility: string }[];
  investment: ProposalInvestment;
  platform_tech?: ProposalPlatformTech[];
  risk_mitigation?: { risk: string; mitigation: string }[];
  assumptions?: string[];
  maintenance_support?: ProposalMaintenanceSupport;
  terms_and_conditions: string;
  next_steps: string;
  closing_note?: string;
  validity_days: number;
}

export interface Proposal {
  id: number;
  lead_id: number;
  title: string;
  theme: ProposalTheme;
  conversation_notes: string;
  content: ProposalContent;
  status: ProposalStatus;
  valid_until?: string;
  created_by?: { id: number; name: string };
  created_at: string;
  updated_at: string;
}

// ── Email Templates ───────────────────────────────────────────────────────────
export interface EmailTemplate {
  id: number;
  name: string;
  subject: string;
  body: string;
  stage_trigger?: string;
  created_at: string;
}

// ── API response shapes ───────────────────────────────────────────────────────
export interface ApiResponse<T> {
  data: T;
  message?: string;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    total: number;
    per_page: number;
    current_page: number;
    last_page: number;
  };
}

// ── CRM Notification ──────────────────────────────────────────────────────────
export interface CrmNotification {
  id: number;
  type: 'lead_assigned' | 'deal_stage_changed' | 'activity_assigned';
  title: string;
  message: string;
  data?: Record<string, unknown>;
  link?: string;
  is_read: boolean;
  read_at: string | null;
  time_ago: string;
  created_at: string;
}

// ── Reports ───────────────────────────────────────────────────────────────────
export interface ReportsData {
  funnel:       { status: string; count: number }[];
  lead_sources: { source: string; count: number }[];
  leaderboard:  { user_id: number; name: string; deals_won: number; revenue: number }[];
  lost_reasons: { lost_reason: string; count: number }[];
  avg_cycle:    { status: string; avg_days: number; count: number }[];
}

export interface LeadReportSummary {
  total: number;
  new: number;
  contacted: number;
  ringing: number;
  qualified?: number; // legacy alias for ringing
  proposal_sent: number;
  won: number;
  lost: number;
}

export interface LeadReportRow {
  id: number;
  lead_name: string;
  company?: string | null;
  contact_person: string;
  phone?: string | null;
  email?: string | null;
  assigned_member?: string | null;
  source?: string | null;
  status: string;
  created_at?: string | null;
  updated_at?: string | null;
  follow_up_date?: string | null;
}

export interface RevenueReportSummary {
  total_revenue: number;
  revenue_this_month: number;
  total_transactions: number;
  average_revenue_per_deal: number;
}

export interface RevenueReportRow {
  id: number;
  deal_name?: string | null;
  client_name?: string | null;
  assigned_member?: string | null;
  deal_value?: number | null;
  amount_received: number;
  remaining_amount?: number | null;
  transaction_amount: number;
  payment_date?: string | null;
  payment_method?: string | null;
  transaction_notes?: string | null;
  deal_status?: string | null;
}

export interface LeadReportFilters {
  search?: string;
  status?: string;
  assigned_to?: number;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
}

export interface RevenueReportFilters {
  assigned_to?: number;
  deal_status?: string;
  payment_mode?: string;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
}

// ── Dashboard ─────────────────────────────────────────────────────────────────
export interface DashboardStats {
  total_leads: number;
  new_leads: number;
  open_deals: number;
  won_deals: number;
  deal_value: number;
  due_today_activities: number;
  overdue_activities: number;
}

export interface DashboardCharts {
  leads_trend:    { date: string; count: number }[];
  deals_by_stage: { stage_id: number; count: number; total_value: number; stage?: { id: number; name: string; color: string } | null }[];
  revenue_trend:  { date: string; revenue: number }[];
}

export interface DashboardData {
  stats:               DashboardStats;
  recent_leads:        Lead[];
  recent_deals:        Deal[];
  reminders:           Activity[];
  charts:              DashboardCharts;
  target_progress?:    TargetProgress;
}
