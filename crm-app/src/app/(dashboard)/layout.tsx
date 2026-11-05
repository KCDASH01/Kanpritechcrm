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
import { SubscriptionStatusNotice } from '@/components/subscription/SubscriptionStatusNotice';

// ── Subscription banners ──────────────────────────────────────────────────────

function NoSubscriptionWall() {
  return (
    <div className="mb-4 flex flex-col items-stretch gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 sm:flex-row sm:items-center">
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
        className="shrink-0 rounded-lg bg-red-600 px-3 py-2 text-center text-xs font-bold text-white transition-colors hover:bg-red-700 sm:py-1.5"
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
  const noActiveSub = subReady && liveSub === null;

  return (
    <>
      {showSetPassword && <SetPasswordModal />}
      <DashboardLayout>
        {/* ── Subscription banners (shown at top of content area) ── */}
        {noActiveSub && <NoSubscriptionWall />}
        {!noActiveSub && liveSub && <div className="mb-4"><SubscriptionStatusNotice subscription={liveSub} /></div>}

        {children}
      </DashboardLayout>
    </>
  );
}
