import type { Subscription } from '@/types';

export type SubscriptionNoticeTone = 'info' | 'success' | 'warning' | 'danger';

export interface SubscriptionStatusPresentation {
  title: string;
  description: string;
  tone: SubscriptionNoticeTone;
  action?: { label: string; href: string };
}

const planName = (plan: Subscription['plan']) => plan.charAt(0).toUpperCase() + plan.slice(1);

export function formatSubscriptionAmount(subscription: Subscription): string | null {
  const amount = Number(subscription.amount);
  if (!Number.isFinite(amount) || amount <= 0 || !subscription.currency) return null;

  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: subscription.currency,
    minimumFractionDigits: Number.isInteger(amount) ? 0 : 2,
    maximumFractionDigits: 2,
  }).format(amount);
}

export function formatSubscriptionDate(value?: string | null, currency?: string): string | null {
  if (!value) return null;
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return null;

  return new Intl.DateTimeFormat('en-IN', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    ...(currency === 'INR' ? { timeZone: 'Asia/Kolkata' } : {}),
  }).format(date);
}

function fallbackLifecycleState(subscription: Subscription): NonNullable<Subscription['lifecycle_state']> {
  if (subscription.plan === 'free') return 'free';
  if (subscription.is_expired || subscription.status === 'expired') return 'expired';
  if (subscription.grace_ends_at) return 'grace_period';
  if (subscription.cancel_requested_at) return 'cancellation_pending';
  if (subscription.status === 'cancelled') return 'cancelled';
  const authorized = Boolean(subscription.authorized_at)
    && ['authenticated', 'active'].includes(subscription.provider_status ?? '');
  if (subscription.status === 'trialing') return authorized ? 'trial_authorized' : 'trial_pending_authorization';
  if (subscription.status === 'active' && authorized && subscription.billing_cycle) return 'active_auto_renew';
  if (subscription.status === 'active') return 'active';
  return 'unknown';
}

export function getSubscriptionStatusPresentation(
  subscription: Subscription,
  options: { surface?: 'dashboard' | 'billing'; now?: Date } = {},
): SubscriptionStatusPresentation | null {
  const state = subscription.lifecycle_state ?? fallbackLifecycleState(subscription);
  const plan = planName(subscription.plan);
  const amount = formatSubscriptionAmount(subscription);
  const billingDate = formatSubscriptionDate(
    subscription.next_charge_at ?? subscription.current_period_end,
    subscription.currency,
  );
  const accessDate = formatSubscriptionDate(
    subscription.access_until ?? subscription.end_date,
    subscription.currency,
  );
  const graceDate = formatSubscriptionDate(subscription.grace_ends_at, subscription.currency);

  switch (state) {
    case 'free':
      return options.surface === 'billing'
        ? { title: 'Your Free plan is active.', description: 'Upgrade whenever you need additional CRM features.', tone: 'info' }
        : null;
    case 'trial_authorized':
      return {
        title: `Your ${plan} free trial is active.`,
        description: amount && billingDate
          ? `Your first payment of ${amount} is scheduled for ${billingDate}. Your subscription will renew automatically unless cancelled.`
          : 'Your payment method is authorized. Your first billing details will appear here as soon as synchronization completes.',
        tone: 'success',
      };
    case 'trial_pending_authorization':
      return {
        title: 'Payment authorization is pending.',
        description: 'Complete payment-method authorization before your free trial can begin. Automatic renewal is not enabled yet.',
        tone: 'warning',
        ...(subscription.can_resume_authorization
          ? { action: { label: 'Complete Authorization', href: '/start-trial' } }
          : {}),
      };
    case 'active_auto_renew':
      return {
        title: `Your ${plan} subscription renews automatically.`,
        description: amount && billingDate
          ? `Your next payment of ${amount} is scheduled for ${billingDate}.`
          : 'Your recurring payment method is authorized. The next billing details will appear after synchronization.',
        tone: 'success',
      };
    case 'cancellation_pending':
      return {
        title: subscription.status === 'trialing' ? `Your ${plan} trial has been cancelled.` : 'Subscription cancellation is scheduled.',
        description: accessDate
          ? `Automatic renewal is disabled. Your access remains available until ${accessDate}.`
          : 'Automatic renewal is disabled. Access remains available through the current eligible period.',
        tone: 'info',
      };
    case 'grace_period':
      return {
        title: 'We could not process your subscription payment.',
        description: graceDate
          ? `Please review your payment method. Access is available only through the grace period ending ${graceDate}.`
          : 'Please review your payment method. Continued access is subject to the configured payment grace period.',
        tone: 'warning',
      };
    case 'expired':
    case 'cancelled':
      return {
        title: `Your ${plan} subscription has expired.`,
        description: accessDate
          ? `Paid access ended on ${accessDate}. Choose a plan to restore paid features.`
          : 'Choose a plan to restore access to paid features.',
        tone: 'danger',
        action: { label: 'View Plans', href: '/plans' },
      };
    case 'active': {
      const end = subscription.access_until ?? subscription.end_date;
      const endMs = end ? new Date(end).getTime() : Number.NaN;
      const now = options.now ?? new Date();
      const expiringSoon = Number.isFinite(endMs) && endMs > now.getTime()
        && endMs - now.getTime() < 7 * 24 * 60 * 60 * 1000;
      if (!expiringSoon) return null;
      return {
        title: `Your ${plan} plan ends soon.`,
        description: accessDate ? `Your current access period ends on ${accessDate}.` : 'Your current access period is ending soon.',
        tone: 'warning',
        action: { label: 'View Plans', href: '/plans' },
      };
    }
    default:
      return options.surface === 'billing'
        ? { title: 'Subscription status is synchronizing.', description: 'Billing details will update after the payment provider confirms the latest status.', tone: 'info' }
        : null;
  }
}
