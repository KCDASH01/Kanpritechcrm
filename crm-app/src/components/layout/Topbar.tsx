'use client';

import { useState } from 'react';
import { useRouter, usePathname } from 'next/navigation';
import { useQueryClient, useQuery, useMutation } from '@tanstack/react-query';
import { motion, AnimatePresence } from 'framer-motion';
import { useAuthStore } from '@/store/authStore';
import { authApi } from '@/lib/api/auth';
import { notificationsApi } from '@/lib/api/notifications';
import { salesTargetsApi } from '@/lib/api/salesTargets';
import type { CrmNotification, MyTargetProgress } from '@/types';

const PAGE_TITLES: Record<string, string> = {
  '/dashboard':   'Dashboard',
  '/leads':       'Leads',
  '/follow-ups':  'FollowUp',
  '/meetings':    'Meetings',
  '/important':   'Important',
  '/pipelines':   'Pipelines',
  '/deals':       'Deals',
  '/activities':  'Activities',
  '/calendar':    'Calendar',
  '/reports':     'Reports',
  '/targets':     'Targets',
  '/team':        'Team',
  '/departments': 'Departments',
  '/billing':     'Billing',
  '/settings':    'Settings',
};

const NOTIF_ICONS: Record<string, string> = {
  lead_assigned:      '👤',
  deal_stage_changed: '📊',
  activity_assigned:  '✓',
};

function fmtCurrency(n: number) {
  if (n >= 100_000) return `₹${(n / 100_000).toFixed(1)}L`;
  if (n >= 1_000)   return `₹${(n / 1_000).toFixed(1)}K`;
  return `₹${n.toFixed(0)}`;
}

function ProgressBar({ label, achieved, target, color }: {
  label: string; achieved: number; target: number; color: string;
}) {
  const pct = target > 0 ? Math.min(Math.round((achieved / target) * 100), 100) : 0;
  const pctFull = target > 0 ? Math.round((achieved / target) * 100) : 0;
  return (
    <div>
      <div className="flex items-center justify-between mb-1.5">
        <span className="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">{label}</span>
        <span className={`text-[11px] font-bold ${pctFull >= 100 ? 'text-emerald-600' : pctFull >= 50 ? 'text-amber-600' : 'text-red-500'}`}>
          {pctFull}%
        </span>
      </div>
      <div className="w-full bg-gray-100 rounded-full h-2.5 overflow-hidden">
        <motion.div
          className={`h-full rounded-full ${color}`}
          initial={{ width: 0 }}
          animate={{ width: `${pct}%` }}
          transition={{ duration: 0.6, ease: 'easeOut' }}
        />
      </div>
      <div className="flex items-center justify-between mt-1">
        <span className="text-[10px] text-gray-500">{fmtCurrency(achieved)}</span>
        <span className="text-[10px] text-gray-400">of {fmtCurrency(target)}</span>
      </div>
    </div>
  );
}

export default function Topbar() {
  const router   = useRouter();
  const pathname = usePathname();
  const qc       = useQueryClient();
  const { user, clearAuth, isEmployee, isPaidPlan } = useAuthStore();
  const [userOpen, setUserOpen]         = useState(false);
  const [notifOpen, setNotifOpen]       = useState(false);
  const [targetOpen, setTargetOpen]     = useState(false);
  const [refreshing, setRefreshing]     = useState(false);

  const showTargetBtn = isEmployee() && isPaidPlan();

  const pageTitle = Object.entries(PAGE_TITLES).find(([key]) =>
    pathname === key || pathname.startsWith(key + '/')
  )?.[1] ?? 'Dashboard';

  // ── Notification count (poll every 60s) ──────────────────────────────────
  const { data: unreadCount = 0 } = useQuery({
    queryKey: ['notifications-count'],
    queryFn:  notificationsApi.unreadCount,
    refetchInterval: 60_000,
  });

  // ── Notification list (fetched when panel opens) ──────────────────────────
  const { data: notifData } = useQuery({
    queryKey: ['notifications'],
    queryFn:  () => notificationsApi.list({ per_page: 15 }),
    enabled:  notifOpen,
  });
  const notifications: CrmNotification[] = notifData?.data ?? [];

  const markReadMutation = useMutation({
    mutationFn: notificationsApi.markRead,
    onSuccess:  () => {
      qc.invalidateQueries({ queryKey: ['notifications'] });
      qc.invalidateQueries({ queryKey: ['notifications-count'] });
    },
  });

  const markAllReadMutation = useMutation({
    mutationFn: notificationsApi.markAllRead,
    onSuccess:  () => {
      qc.invalidateQueries({ queryKey: ['notifications'] });
      qc.invalidateQueries({ queryKey: ['notifications-count'] });
    },
  });

  const handleNotifClick = (n: CrmNotification) => {
    if (!n.is_read) markReadMutation.mutate(n.id);
    if (n.link) router.push(n.link);
    setNotifOpen(false);
  };

  const handleLogout = async () => {
    try { await authApi.logout(); } catch { /* ignore */ }
    clearAuth();
    router.push('/login');
  };

  // ── My target progress (employees only) ──────────────────────────────────
  const { data: targetProgress } = useQuery({
    queryKey: ['my-target-progress'],
    queryFn:  salesTargetsApi.myProgress,
    enabled:  showTargetBtn,
    staleTime: 5 * 60_000,
  });

  const now = new Date();
  const currentMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
  const monthTarget: MyTargetProgress | undefined = targetProgress?.find(
    (t) => t.period_start.startsWith(currentMonth)
  );
  const fmtMonthLabel = now.toLocaleDateString('en-IN', { month: 'short', year: 'numeric' });

  const handleRefresh = async () => {
    setRefreshing(true);
    await qc.invalidateQueries();
    setTimeout(() => setRefreshing(false), 600);
  };

  return (
    <header className="h-14 bg-white border-b border-gray-100 shadow-sm flex items-center justify-between px-6 shrink-0 z-10">

      {/* Left — page title + org */}
      <div>
        <h1 className="text-base font-bold text-gray-900 leading-tight">{pageTitle}</h1>
        {user?.organization?.name && (
          <p className="text-[11px] text-gray-400 leading-tight truncate max-w-[200px]">
            {user.organization.name}
          </p>
        )}
      </div>

      {/* Right — refresh + bell + user */}
      <div className="flex items-center gap-2">

        {/* Refresh button */}
        <button
          onClick={handleRefresh}
          title="Refresh data"
          className="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400
                     hover:text-indigo-600 hover:bg-indigo-50 transition-colors"
        >
          <svg
            className={`w-4 h-4 transition-transform duration-500 ${refreshing ? 'animate-spin' : ''}`}
            fill="none" stroke="currentColor" viewBox="0 0 24 24"
          >
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
        </button>

        {/* Target progress button (employees only) */}
        {showTargetBtn && (
          <div className="relative">
            <button
              onClick={() => { setTargetOpen((v) => !v); setNotifOpen(false); setUserOpen(false); }}
              title="My Targets"
              className={`w-8 h-8 rounded-lg flex items-center justify-center transition-colors
                ${targetOpen
                  ? 'text-indigo-600 bg-indigo-50'
                  : 'text-gray-400 hover:text-indigo-600 hover:bg-indigo-50'}`}
            >
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
              </svg>
            </button>

            <AnimatePresence>
              {targetOpen && (
                <>
                  <div className="fixed inset-0 z-10" onClick={() => setTargetOpen(false)} />
                  <motion.div
                    className="absolute right-0 top-full mt-2 w-72 bg-white border border-gray-100
                               rounded-2xl shadow-xl shadow-black/10 z-20 overflow-hidden"
                    initial={{ opacity: 0, y: -8, scale: 0.96 }}
                    animate={{ opacity: 1, y: 0, scale: 1 }}
                    exit={{ opacity: 0, y: -8, scale: 0.96 }}
                    transition={{ type: 'spring', damping: 30, stiffness: 400 }}
                  >
                    <div className="px-4 py-3 border-b border-gray-100 bg-gray-50/80 flex items-center justify-between">
                      <span className="text-sm font-semibold text-gray-900">My Targets</span>
                      <span className="text-[10px] bg-indigo-100 text-indigo-600 px-2 py-0.5 rounded-full font-semibold">
                        {fmtMonthLabel}
                      </span>
                    </div>

                    <div className="px-4 py-4 space-y-4">
                      {!monthTarget || (monthTarget.target_amount === 0 && monthTarget.receivable_amount === 0) ? (
                        <div className="py-4 text-center">
                          <div className="text-2xl mb-2">🎯</div>
                          <p className="text-sm text-gray-500 font-medium">No targets set</p>
                          <p className="text-[11px] text-gray-400 mt-1">
                            Ask your manager to set targets for this month.
                          </p>
                        </div>
                      ) : (
                        <>
                          {monthTarget.target_amount > 0 && (
                            <ProgressBar
                              label="Sales Target"
                              achieved={monthTarget.achieved_amount}
                              target={monthTarget.target_amount}
                              color="bg-indigo-500"
                            />
                          )}
                          {monthTarget.receivable_amount > 0 && (
                            <ProgressBar
                              label="Collections"
                              achieved={monthTarget.received_amount ?? 0}
                              target={monthTarget.receivable_amount}
                              color="bg-emerald-500"
                            />
                          )}
                        </>
                      )}
                    </div>
                  </motion.div>
                </>
              )}
            </AnimatePresence>
          </div>
        )}

        {/* Notification bell */}
        <div className="relative">
          <button
            onClick={() => { setNotifOpen((v) => !v); setUserOpen(false); setTargetOpen(false); }}
            title="Notifications"
            className="relative w-8 h-8 rounded-lg flex items-center justify-center text-gray-400
                       hover:text-indigo-600 hover:bg-indigo-50 transition-colors"
          >
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
            {unreadCount > 0 && (
              <span className="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 bg-red-500 text-white
                               text-[9px] font-bold rounded-full flex items-center justify-center px-1 leading-none">
                {unreadCount > 9 ? '9+' : unreadCount}
              </span>
            )}
          </button>

          <AnimatePresence>
            {notifOpen && (
              <>
                <div className="fixed inset-0 z-10" onClick={() => setNotifOpen(false)} />
                <motion.div
                  className="absolute right-0 top-full mt-2 w-80 bg-white border border-gray-100
                             rounded-2xl shadow-xl shadow-black/10 z-20 overflow-hidden"
                  initial={{ opacity: 0, y: -8, scale: 0.96 }}
                  animate={{ opacity: 1, y: 0, scale: 1 }}
                  exit={{ opacity: 0, y: -8, scale: 0.96 }}
                  transition={{ type: 'spring', damping: 30, stiffness: 400 }}
                >
                  {/* Header */}
                  <div className="px-4 py-3 border-b border-gray-100 flex items-center justify-between bg-gray-50/80">
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-semibold text-gray-900">Notifications</span>
                      {unreadCount > 0 && (
                        <span className="px-1.5 py-0.5 bg-red-100 text-red-600 text-[10px] font-bold rounded-full">
                          {unreadCount}
                        </span>
                      )}
                    </div>
                    {unreadCount > 0 && (
                      <button
                        onClick={() => markAllReadMutation.mutate()}
                        className="text-[11px] text-indigo-600 hover:text-indigo-700 font-medium transition-colors"
                      >
                        Mark all read
                      </button>
                    )}
                  </div>

                  {/* List */}
                  <div className="max-h-[360px] overflow-y-auto divide-y divide-gray-50">
                    {notifications.length === 0 ? (
                      <div className="px-4 py-8 text-center">
                        <div className="w-10 h-10 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-2">
                          <svg className="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                              d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                          </svg>
                        </div>
                        <p className="text-sm text-gray-500">No notifications yet</p>
                      </div>
                    ) : (
                      notifications.map((n) => (
                        <button
                          key={n.id}
                          onClick={() => handleNotifClick(n)}
                          className={`w-full text-left px-4 py-3 flex items-start gap-3 transition-colors hover:bg-gray-50
                                      ${!n.is_read ? 'bg-indigo-50/40' : ''}`}
                        >
                          <div className={`w-8 h-8 rounded-xl flex items-center justify-center shrink-0 text-sm mt-0.5
                                          ${!n.is_read ? 'bg-indigo-100' : 'bg-gray-100'}`}>
                            {NOTIF_ICONS[n.type] ?? '🔔'}
                          </div>
                          <div className="flex-1 min-w-0">
                            <p className={`text-xs font-semibold truncate ${!n.is_read ? 'text-gray-900' : 'text-gray-600'}`}>
                              {n.title}
                            </p>
                            <p className="text-[11px] text-gray-500 mt-0.5 line-clamp-2">{n.message}</p>
                            <p className="text-[10px] text-gray-400 mt-1">{n.time_ago}</p>
                          </div>
                          {!n.is_read && (
                            <span className="w-2 h-2 bg-indigo-500 rounded-full shrink-0 mt-1.5" />
                          )}
                        </button>
                      ))
                    )}
                  </div>

                  {notifications.length > 0 && (
                    <div className="px-4 py-2.5 border-t border-gray-100 bg-gray-50/60">
                      <a
                        href="/activities"
                        className="text-xs text-indigo-600 hover:text-indigo-700 font-medium transition-colors"
                        onClick={() => setNotifOpen(false)}
                      >
                        View all activities →
                      </a>
                    </div>
                  )}
                </motion.div>
              </>
            )}
          </AnimatePresence>
        </div>

        {/* User dropdown */}
        <div className="relative">
          <button
            onClick={() => { setUserOpen((v) => !v); setNotifOpen(false); setTargetOpen(false); }}
            className="flex items-center gap-2.5 hover:bg-gray-50 rounded-xl px-2.5 py-1.5 transition-colors"
          >
            <div className="w-7 h-7 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500
                            flex items-center justify-center text-xs font-bold text-white shadow-sm">
              {user?.name?.[0]?.toUpperCase() ?? '?'}
            </div>
            <span className="text-sm font-medium text-gray-700 hidden sm:block">{user?.name}</span>
            <svg
              className={`w-3.5 h-3.5 text-gray-400 transition-transform duration-200 ${userOpen ? 'rotate-180' : ''}`}
              fill="none" stroke="currentColor" viewBox="0 0 24 24"
            >
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
            </svg>
          </button>

          <AnimatePresence>
            {userOpen && (
              <>
                <div className="fixed inset-0 z-10" onClick={() => setUserOpen(false)} />
                <motion.div
                  className="absolute right-0 top-full mt-2 w-52 bg-white border border-gray-100
                             rounded-2xl shadow-xl shadow-black/10 z-20 overflow-hidden"
                  initial={{ opacity: 0, y: -8, scale: 0.96 }}
                  animate={{ opacity: 1, y: 0, scale: 1 }}
                  exit={{ opacity: 0, y: -8, scale: 0.96 }}
                  transition={{ type: 'spring', damping: 30, stiffness: 400 }}
                >
                  <div className="px-4 py-3 border-b border-gray-100 bg-gray-50/80">
                    <p className="text-xs font-semibold text-gray-900 truncate">{user?.name}</p>
                    <p className="text-[11px] text-gray-400 truncate">{user?.email}</p>
                    <div className="mt-1.5 flex items-center gap-1">
                      <span className="text-[10px] bg-indigo-100 text-indigo-600 px-1.5 py-0.5 rounded-full font-medium capitalize">
                        {user?.role}
                      </span>
                      {user?.is_sso_user && (
                        <span className="text-[10px] bg-violet-100 text-violet-600 px-1.5 py-0.5 rounded-full font-medium">
                          SSO
                        </span>
                      )}
                    </div>
                  </div>

                  <div className="py-1">
                    <button
                      onClick={() => { setUserOpen(false); router.push('/settings'); }}
                      className="w-full text-left px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50
                                 flex items-center gap-2.5 transition-colors"
                    >
                      <svg className="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                          d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                      </svg>
                      Settings
                    </button>
                    <div className="mx-3 border-t border-gray-100" />
                    <button
                      onClick={handleLogout}
                      className="w-full text-left px-4 py-2.5 text-sm text-red-500 hover:bg-red-50
                                 flex items-center gap-2.5 transition-colors"
                    >
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                          d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                      </svg>
                      Sign out
                    </button>
                  </div>
                </motion.div>
              </>
            )}
          </AnimatePresence>
        </div>
      </div>
    </header>
  );
}
