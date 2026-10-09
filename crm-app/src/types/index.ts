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
export type ClientType = 'NEW' | 'EXISTING';
export type BusinessType = 'ONE_TIME' | 'RECURRING';
export type MarketType = 'DOMESTIC' | 'INTERNATIONAL';
export type RecurringFrequency = 'MONTHLY' | 'QUARTERLY' | 'HALF_YEARLY' | 'YEARLY';

export interface Client {
  id: number;
  full_name: string;
  first_name: string;
  last_name?: string;
  company?: string;
  email?: string;
  phone?: string;
  job_title?: string;
  website?: string;
  city?: string;
  state?: string;
  country?: string;
  assigned_to?: User;
  leads_count?: number;
  deals_count?: number;
  created_at?: string;
}

export interface Lead {
  id: number;
  client_id?: number;
  client_type?: ClientType;
  business_type?: BusinessType;
  market_type?: MarketType;
  client?: Client;
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
  state?: string;
  country?: string;
  notes?: string;
  score?: number;
  expected_value?: number;
  currency?: 'INR' | 'USD';
  recurring_frequency?: RecurringFrequency;
  recurring_amount?: number;
  recurring_start_date?: string;
  recurring_end_type?: 'ONGOING' | 'FIXED';
  recurring_end_date?: string;
  next_billing_date?: string;
  billing_cycles?: number;
  contract_value?: number;
  department_id?: number;
  department?: Department;
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
  client_id?: number;
  client?: Client;
  client_type?: ClientType;
  business_type?: BusinessType;
  market_type?: MarketType;
  service_type?: string;
  recurring_frequency?: RecurringFrequency;
  recurring_amount?: number;
  recurring_start_date?: string;
  recurring_end_date?: string;
  next_billing_date?: string;
  billing_cycles?: number;
  contract_value?: number;
  department_id?: number;
  department?: Department;
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
export type SalesTargetType = 'monthly' | 'custom';

export interface SalesTargetUpsertPayload {
  target_id?: number;
  user_id: number;
  target_type: SalesTargetType;
  target_amount: number;
  receivable_amount: number;
  period_start: string;
  period_end: string;
  notes?: string;
}

export interface SalesTarget {
  id: number;
  user_id: number;
  target_amount: number;
  receivable_amount: number;
  received_amount: number | null;
  notes?: string;
  period_start: string;
  period_end: string;
  target_type: SalesTargetType;
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
  period_end: string;
  period_label: string;
  target_type: SalesTargetType;
  period_status: 'upcoming' | 'active' | 'completed';
  department?: string | null;
  sales_percentage: number | null;
  collection_percentage: number | null;
  remaining_sales_amount: number;
  remaining_collection_amount: number;
  duration_days: number;
  days_elapsed: number;
  days_remaining: number;
}

// 6-month history row returned by /sales-targets/my-progress
export interface MyTargetProgress {
  period_start: string;
  target_amount: number;
  receivable_amount: number;
  received_amount: number | null;
  achieved_amount: number;
  target_id: number | null;
  id?: number;
  period_end: string;
  period_label: string;
  target_type: SalesTargetType;
  period_status: 'upcoming' | 'active' | 'completed';
  sales_percentage: number | null;
  collection_percentage: number | null;
  remaining_sales_amount: number;
  remaining_collection_amount: number;
  duration_days: number;
  days_elapsed: number;
  days_remaining: number;
}

// Embedded in DashboardData for team-member view
export interface TargetProgress {
  period_start: string;
  period_end?: string;
  period_label?: string;
  period_status?: 'upcoming' | 'active' | 'completed';
  target_type?: SalesTargetType;
  has_target: boolean;
  target_count: number;
  target_amount: number;
  receivable_amount: number;
  received_amount: number;
  achieved_amount: number;
  sales_percentage: number | null;
  collection_percentage: number | null;
  scope: 'team' | 'member';
  user_id: number | null;
  remaining_sales_amount?: number;
  remaining_collection_amount?: number;
  days_elapsed?: number | null;
  days_remaining?: number | null;
  periods?: {
    user_id: number;
    user_name?: string | null;
    target_type?: SalesTargetType;
    period_start: string;
    period_end: string;
    period_label?: string;
    days_remaining?: number;
  }[];
}

export interface RevenueComparison {
  available: boolean;
  change_percent: number | null;
  direction: 'increase' | 'decrease' | 'neutral';
  label: string;
  context?: string;
}

export interface LeaderboardSettings {
  visibility: 'everyone' | 'management' | 'disabled';
  data_visibility: 'names_percentages' | 'amounts' | 'anonymous';
  sales_weight: number;
  collection_weight: number;
}

export interface LeaderboardRow {
  target_id: number;
  user_id: number;
  rank: number | null;
  employee_name: string | null;
  department: string | null;
  sales_percentage: number | null;
  collection_percentage: number | null;
  overall_score: number | null;
  ranking_score: number | null;
  expected_progress: number;
  target_amount: number | null;
  receivable_amount: number | null;
  period_start: string;
  period_end: string;
  period_label: string;
  period_status: 'active' | 'completed' | 'upcoming';
  badges: string[];
}

export interface LeaderboardData {
  rows: LeaderboardRow[];
  category: 'sales' | 'collection' | 'overall';
  mode: 'actual' | 'pace';
  period_scope: 'active' | 'completed';
  settings: LeaderboardSettings;
  own_rank: LeaderboardRow | null;
}

export interface DashboardPerformance {
  period_start: string;
  period_label: string;
  selected_user_id: number | null;
  target: TargetProgress;
  revenue: {
    total_collected: number;
    collected_this_month: number;
    receivable: number;
    comparisons?: {
      total_collected: RevenueComparison;
      collected_this_month: RevenueComparison;
      receivable: RevenueComparison;
    };
  };
  team_members: { id: number; name: string }[];
}

export interface DashboardPerformanceDetail {
  type: 'sales' | 'target_collections' | 'collections_month' | 'collections_all' | 'receivables';
  period_label: string;
  rows: Record<string, string | number | null>[];
  meta: { current_page: number; last_page: number; total: number };
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
  one_time_revenue: number;
  recurring_revenue: number;
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
  client_type?: ClientType;
  business_type?: BusinessType;
  market_type?: MarketType;
  service_type?: string;
}

export interface RevenueReportFilters {
  assigned_to?: number;
  deal_status?: string;
  payment_mode?: string;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
  client_type?: ClientType;
  business_type?: BusinessType;
  market_type?: MarketType;
  service_type?: string;
}

export interface RecurringBusiness {
  id: number;
  business_name: string;
  service_type?: string;
  amount: number;
  currency: string;
  frequency: RecurringFrequency;
  start_date: string;
  next_billing_date?: string;
  end_date?: string;
  billing_cycles?: number;
  contract_value?: number;
  status: 'ACTIVE' | 'PAUSED' | 'EXPIRED' | 'CANCELLED' | 'COMPLETED';
  notes?: string;
  collected_revenue: number;
  due_revenue: number;
  outstanding: number;
  client?: Client;
  deal?: Deal;
  assigned_to?: User;
  department?: Department;
}

export interface RecurringBusinessSummary {
  active_count: number;
  mrr: number;
  arr: number;
  expected_this_month: number;
  collected_this_month: number;
  overdue: number;
  upcoming_renewals: number;
  cancelled_or_expired: number;
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
  total_revenue: number;
  one_time_revenue: number;
  recurring_revenue: number;
}

export interface DashboardCharts {
  leads_trend:    { date: string; count: number }[];
  deals_by_stage: { stage_id: number; count: number; total_value: number; stage?: { id: number; name: string; color: string } | null }[];
  revenue_trend:  { date: string; revenue: number; one_time_revenue?: number; recurring_revenue?: number }[];
}

export interface DashboardData {
  stats:               DashboardStats;
  recent_leads:        Lead[];
  recent_deals:        Deal[];
  reminders:           Activity[];
  charts:              DashboardCharts;
  target_progress?:    TargetProgress;
  performance:         DashboardPerformance;
}
