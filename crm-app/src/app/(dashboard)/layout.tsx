'use client';

import { useEffect } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import { useAuthStore } from '@/store/authStore';
import { subscriptionApi } from '@/lib/api/subscription';
import type { Subscription } from '@/types';
import DashboardLayout from '@/components/layout/DashboardLayout';
import { SetPasswordModal } from '@/components/layout/SetPasswordModal';

// ── Subscription banners ──────────────────────────────────────────────────────

function SubscriptionExpiredBanner({ sub }: { sub: Subscription }) {
  const planLabel = sub.plan === 'enterprise' ? 'Enterprise' : 'Business';
  const expiredOn = sub.end_date
    ? new Date(sub.end_date).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })
    : null;

  return (
    <div className="mb-4 flex items-center gap-3 rounded-xl bg-red-50 border border-red-200 px-4 py-3">
      <div className="shrink-0 w-8 h-8 rounded-full bg-red-100 flex items-center justify-center">
        <svg className="w-4 h-4 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
            d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
        </svg>
      </div>
      <div className="flex-1 min-w-0">
        <p className="text-sm font-semibold text-red-800">
          Your {planLabel} subscription has expired
          {expiredOn ? ` (${expiredOn})` : ''}.
        </p>
        <p className="text-xs text-red-600 mt-0.5">
          Access to paid features is restricted. Renew or upgrade to continue.
        </p>
      </div>
      <Link
        href="/plans"
        className="shrink-0 text-xs font-bold text-white bg-red-600 hover:bg-red-700 transition-colors px-3 py-1.5 rounded-lg"
      >
        View Plans
      </Link>
    </div>
  );
}

function SubscriptionExpiringSoonBanner({ sub }: { sub: Subscription }) {
  const planLabel = sub.plan === 'enterprise' ? 'Enterprise' : 'Business';
  const endDate   = sub.end_date ? new Date(sub.end_date) : null;
  if (!endDate) return null;

  const daysLeft = Math.ceil((endDate.getTime() - Date.now()) / (1000 * 60 * 60 * 24));

  return (
    <div className="mb-4 flex items-center gap-3 rounded-xl bg-amber-50 border border-amber-200 px-4 py-3">
      <div className="shrink-0 w-8 h-8 rounded-full bg-amber-100 flex items-center justify-center">
        <svg className="w-4 h-4 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
            d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </div>
      <div className="flex-1 min-w-0">
        <p className="text-sm font-semibold text-amber-800">
          Your {planLabel} plan expires in {daysLeft} day{daysLeft !== 1 ? 's' : ''}.
        </p>
        <p className="text-xs text-amber-700 mt-0.5">
          Renew before{' '}
          {endDate.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })}{' '}
          to avoid any interruption.
        </p>
      </div>
      <Link
        href="/plans"
        className="shrink-0 text-xs font-bold text-amber-900 bg-amber-200 hover:bg-amber-300 transition-colors px-3 py-1.5 rounded-lg"
      >
        Renew Now
      </Link>
    </div>
  );
}

function NoSubscriptionWall() {
  return (
    <div className="mb-4 flex items-center gap-3 rounded-xl bg-red-50 border border-red-200 px-4 py-3">
      <div className="shrink-0 w-8 h-8 rounded-full bg-red-100 flex items-center justify-center">
        <svg className="w-4 h-4 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
            d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
        </svg>
      </div>
      <div className="flex-1 min-w-0">
        <p className="text-sm font-semibold text-red-800">No active subscription found.</p>
        <p className="text-xs text-red-600 mt-0.5">
          Subscribe to a plan to access CRM features.
        </p>
      </div>
      <Link
        href="/plans"
        className="shrink-0 text-xs font-bold text-white bg-red-600 hover:bg-red-700 transition-colors px-3 py-1.5 rounded-lg"
      >
        Choose Plan
      </Link>
    </div>
  );
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/** How many ms until end_date. Negative if already expired. */
function msUntilExpiry(sub: Subscription | null | undefined): number | null {
  if (!sub?.end_date) return null;
  return new Date(sub.end_date).getTime() - Date.now();
}

// ── Layout ────────────────────────────────────────────────────────────────────

export default function DashboardGroupLayout({ children }: { children: React.ReactNode }) {
  const router          = useRouter();
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);
  const hasHydrated     = useAuthStore((s) => s._hasHydrated);
  const user            = useAuthStore((s) => s.user);
  const setUser         = useAuthStore((s) => s.setUser);
  const isSsoUser       = useAuthStore((s) => s.isSsoUser)();

  const showSetPassword = isSsoUser && user?.has_password === false;

  // ── Live subscription sync ─────────────────────────────────────────────────
  // refetchInterval is dynamic:
  //   • Already expired or within 1 hour  → every 60 s  (detect expiry ASAP)
  //   • Expiring within 24 hours          → every 2 min
  //   • Expiring within 7 days            → every 5 min
  //   • Otherwise                         → no interval (staleTime handles it)
  const { data: liveSub, isLoading: subLoading } = useQuery({
    queryKey: ['subscription'],
    queryFn:  subscriptionApi.get,
    enabled:  isAuthenticated,
    staleTime: 5 * 60 * 1000,
    refetchInterval: (query) => {
      const sub = query.state.data as Subscription | null | undefined;
      const ms  = msUntilExpiry(sub);
      if (ms === null) return false;                          // free plan / no end date
      if (ms <= 0)                    return 60_000;         // already expired
      if (ms < 60 * 60 * 1000)       return 60_000;         // < 1 hour
      if (ms < 24 * 60 * 60 * 1000)  return 2 * 60_000;    // < 24 hours
      if (ms < 7 * 24 * 60 * 60 * 1000) return 5 * 60_000; // < 7 days
      return false;
    },
  });

  // Sync live sub into auth store whenever it changes
  useEffect(() => {
    if (liveSub !== undefined && user) {
      const stored = user.subscription;
      if (
        stored?.plan       !== liveSub?.plan       ||
        stored?.end_date   !== liveSub?.end_date   ||
        stored?.status     !== liveSub?.status     ||
        stored?.is_expired !== liveSub?.is_expired
      ) {
        setUser({ ...user, subscription: liveSub ?? undefined });
      }
    }
  }, [liveSub]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (hasHydrated && !isAuthenticated) {
      router.replace('/login');
    }
  }, [hasHydrated, isAuthenticated, router]);

  if (!hasHydrated || !isAuthenticated) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <div className="w-8 h-8 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
      </div>
    );
  }

  // ── Subscription status ────────────────────────────────────────────────────
  const subReady    = !subLoading;
  const isPaidPlan  = liveSub?.plan === 'business' || liveSub?.plan === 'enterprise';
  const isExpired   = liveSub?.is_expired === true;
  const noActiveSub = subReady && liveSub === null;

  // Days until expiry for "expiring soon" warning (null = free / no end_date)
  const ms = msUntilExpiry(liveSub);
  const expiringSoon = ms !== null && ms > 0 && ms < 7 * 24 * 60 * 60 * 1000 && isPaidPlan;

  return (
    <>
      {showSetPassword && <SetPasswordModal />}
      <DashboardLayout>
        {/* ── Subscription banners (shown at top of content area) ── */}
        {noActiveSub && <NoSubscriptionWall />}
        {!noActiveSub && isExpired && isPaidPlan && <SubscriptionExpiredBanner sub={liveSub!} />}
        {!noActiveSub && !isExpired && expiringSoon && <SubscriptionExpiringSoonBanner sub={liveSub!} />}

        {children}
      </DashboardLayout>
    </>
  );
}
