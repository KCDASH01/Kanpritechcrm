'use client';

import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { User } from '@/types';

const SESSION_TTL_MS = 30 * 24 * 60 * 60 * 1000; // 30 days

interface AuthState {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  expiresAt: number | null;   // epoch ms — session expires after 30 days of inactivity
  _hasHydrated: boolean;

  setAuth: (user: User, token: string) => void;
  setUser: (user: User) => void;
  clearAuth: () => void;
  setHasHydrated: (v: boolean) => void;
  extendSession: () => void;

  // Derived helpers
  isOwner: () => boolean;
  isAdmin: () => boolean;
  isEmployee: () => boolean;
  isBusinessPlan: () => boolean;
  isEnterprisePlan: () => boolean;
  isPaidPlan: () => boolean;
  isSsoUser: () => boolean;
  canManageBilling: () => boolean;
  canManageTeam: () => boolean;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => ({
      user: null,
      token: null,
      isAuthenticated: false,
      expiresAt: null,
      _hasHydrated: false,

      setAuth: (user, token) => {
        localStorage.setItem('crm_token', token);
        set({
          user,
          token,
          isAuthenticated: true,
          expiresAt: Date.now() + SESSION_TTL_MS,
        });
      },

      setUser: (user) => set({ user }),

      clearAuth: () => {
        localStorage.removeItem('crm_token');
        localStorage.removeItem('crm_auth'); // also remove the zustand persist key
        set({ user: null, token: null, isAuthenticated: false, expiresAt: null });
      },

      setHasHydrated: (v) => set({ _hasHydrated: v }),

      // Push the expiry forward — called once after hydration to create a rolling window
      extendSession: () => {
        if (get().isAuthenticated) {
          set({ expiresAt: Date.now() + SESSION_TTL_MS });
        }
      },

      isOwner: () => get().user?.role === 'owner',
      isAdmin: () => ['owner', 'admin'].includes(get().user?.role ?? ''),
      isEmployee: () => get().user?.role === 'employee',

      isBusinessPlan: () => {
        const sub = get().user?.subscription;
        if (!sub || sub.plan !== 'business') return false;
        // Treat as Free if end_date has already passed — mirrors backend isExpired() check
        if (sub.end_date && new Date(sub.end_date) < new Date()) return false;
        return true;
      },

      isEnterprisePlan: () => {
        const sub = get().user?.subscription;
        if (!sub || sub.plan !== 'enterprise') return false;
        if (sub.end_date && new Date(sub.end_date) < new Date()) return false;
        return true;
      },

      isPaidPlan: () => {
        return get().isBusinessPlan() || get().isEnterprisePlan();
      },

      isSsoUser: () => get().user?.is_sso_user === true,

      canManageBilling: () => !get().user?.is_sso_user && get().user?.role === 'owner',

      canManageTeam: () => {
        const sub = get().user?.subscription;
        const isActivePaid =
          ['business', 'enterprise'].includes(sub?.plan ?? '') &&
          !(sub?.end_date && new Date(sub.end_date) < new Date());
        return isActivePaid && ['owner', 'admin'].includes(get().user?.role ?? '');
      },
    }),
    {
      name: 'crm_auth',
      partialize: (state) => ({
        user: state.user,
        token: state.token,
        isAuthenticated: state.isAuthenticated,
        expiresAt: state.expiresAt,
      }),
      onRehydrateStorage: () => (state) => {
        if (!state) return;

        // Session expired → clear everything before any UI renders
        if (state.expiresAt && Date.now() > state.expiresAt) {
          localStorage.removeItem('crm_token');
          state.clearAuth();
        } else {
          // Extend rolling window on each app open
          state.extendSession();
        }

        // Signal layouts that it is safe to check auth now
        state.setHasHydrated(true);
      },
    }
  )
);
