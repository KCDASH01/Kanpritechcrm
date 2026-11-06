import client from './client';
import type { ApiResponse, Subscription, SubscriptionPayment } from '@/types';

export interface RazorpayOrderResponse {
  order_id: string;
  amount:   number;
  currency: string;
  key:      string;
  quantity?: number;
  end_date?: string;
  plan?:     string;
  cycle?:    string;
}

export interface SubscribeOrderPayload {
  plan:                'business' | 'enterprise';
  cycle:               'monthly' | 'yearly';
  bill_to_name:        string;
  bill_to_email:       string;
  bill_to_company?:    string;
  bill_to_gstin?:      string;
  bill_to_address:     string;
  bill_to_city:        string;
  bill_to_pincode:     string;
  bill_to_state:       string;
  bill_to_state_code:  string;
  bill_to_country:     string;
}

export interface TrialAuthorization {
  subscription_id: string;
  key: string;
  plan: 'growth' | 'business';
  billing_cycle: 'monthly' | 'yearly';
  amount_minor: number;
  amount: number;
  currency: 'INR' | 'USD';
  tax_exclusive: true;
  first_billing_at: string;
  authorization_disclosure: string;
  promotion?: PromotionQuote;
}

export interface PromotionQuote {
  campaign_id: number;
  code: string | null;
  name: string;
  discount_type: 'percentage' | 'fixed';
  percentage: number | null;
  original_amount_minor: number;
  discount_minor: number;
  first_billing_amount_minor: number;
  regular_renewal_amount_minor: number;
  currency: 'INR' | 'USD';
  duration: 'first_cycle' | 'limited_cycles' | 'recurring';
  discount_cycles: number | null;
  gateway_supported: boolean;
}

export interface SeatVerifyPayload {
  razorpay_order_id: string;
  razorpay_payment_id: string;
  razorpay_signature: string;
  quantity: number;
}

export const subscriptionApi = {
  getPricing: () => client.get<{ data: Record<string, unknown>; trial_days: number; tax_disclosure: string; promotions_enabled: boolean }>('/subscription/pricing').then((r) => r.data),
  get: () =>
    client.get<ApiResponse<Subscription | null>>('/subscription').then((r) => r.data.data),

  upgrade: (payload: {
    plan: 'free' | 'business' | 'enterprise';
    gateway?: string;
    gateway_id?: string;
    amount?: number;
    currency?: string;
    end_date?: string;
    // SSO Enterprise upgrade fields
    razorpay_order_id?: string;
    razorpay_payment_id?: string;
    razorpay_signature?: string;
  }) => client.post<ApiResponse<Subscription>>('/subscription/upgrade', payload).then((r) => r.data.data),

  createUpgradeOrder: (cycle: 'monthly' | 'yearly') =>
    client
      .post<ApiResponse<{
        order_id: string;
        amount: number;
        currency: string;
        key: string;
        cycle: string;
        end_date: string;
      }>>('/subscription/upgrade/order', { cycle })
      .then((r) => r.data.data),

  cancel: () =>
    client.post<ApiResponse<Subscription>>('/subscription/cancel').then((r) => r.data.data),

  authorizeTrial: () =>
    client.post<ApiResponse<TrialAuthorization>>('/subscription/trial/authorize').then((r) => r.data.data),

  selectTrial: (payload: { plan: 'growth' | 'business'; billing_cycle: 'monthly' | 'yearly'; currency: 'INR'; coupon_code?: string }) =>
    client.post<ApiResponse<Subscription>>('/subscription/trial/select', payload).then((r) => r.data.data),

  validatePromotion: (payload: { plan: 'growth' | 'business'; billing_cycle: 'monthly' | 'yearly'; currency: 'INR'; coupon_code: string }) =>
    client.post<ApiResponse<PromotionQuote>>('/subscription/promotions/validate', payload).then((r) => r.data.data),

  verifyTrial: (payload: {
    razorpay_subscription_id: string;
    razorpay_payment_id: string;
    razorpay_signature: string;
  }) => client.post<ApiResponse<Subscription>>('/subscription/trial/verify', payload).then((r) => r.data.data),

  getTransactions: () =>
    client.get<{ data: unknown[] }>('/subscription/transactions').then((r) => r.data.data),

  // ── Non-SSO plan subscription ───────────────────────────────────────────────

  /** Create a Razorpay order for Business/Enterprise plan (non-SSO owners) */
  subscribeOrder: (payload: SubscribeOrderPayload) =>
    client
      .post<ApiResponse<RazorpayOrderResponse>>('/subscription/subscribe/order', payload)
      .then((r) => r.data.data),

  /** Verify Razorpay payment + activate subscription */
  subscribeVerify: (payload: {
    razorpay_order_id:   string;
    razorpay_payment_id: string;
    razorpay_signature:  string;
  }) =>
    client
      .post<ApiResponse<Subscription>>('/subscription/subscribe/verify', payload)
      .then((r) => r.data.data),

  // ── Per-seat billing ────────────────────────────────────────────────────────

  addSeat: (quantity: number) =>
    client
      .post<ApiResponse<RazorpayOrderResponse>>('/subscription/seats/add', { quantity })
      .then((r) => r.data.data),

  verifySeat: (payload: SeatVerifyPayload) =>
    client
      .post<ApiResponse<Subscription>>('/subscription/seats/verify', payload)
      .then((r) => r.data.data),

  removeSeat: () =>
    client
      .delete<ApiResponse<Subscription>>('/subscription/seats/remove')
      .then((r) => r.data.data),

  // ── Payment history ─────────────────────────────────────────────────────────

  getPayments: () =>
    client
      .get<{ data: SubscriptionPayment[] }>('/subscription/payments')
      .then((r) => r.data.data),

  getPayment: (id: number) =>
    client
      .get<ApiResponse<SubscriptionPayment>>(`/subscription/payments/${id}`)
      .then((r) => r.data.data),
};
