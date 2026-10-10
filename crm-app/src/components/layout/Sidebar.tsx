'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';

function NavIcon({ d }: { d: string }) {
  return (
    <svg className="w-[18px] h-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d={d} />
    </svg>
  );
}

interface NavItem { href: string; label: string; icon: string }

const SECTIONS: { label: string; items: NavItem[] }[] = [
  {
    label: 'Workspace',
    items: [
      { href: '/dashboard',  label: 'Dashboard',  icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6' },
      { href: '/leads',      label: 'Leads',      icon: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z' },
      { href: '/clients',    label: 'Clients',    icon: 'M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2m7-10a4 4 0 100-8 4 4 0 000 8zm13 10v-2a4 4 0 00-3-3.87m-4-9.13a4 4 0 010 7.75' },
      { href: '/customer-growth', label: 'Customer Growth', icon: 'M3 17l6-6 4 4 8-8M14 7h7v7' },
      { href: '/follow-ups', label: 'FollowUp',   icon: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z' },
      { href: '/meetings',   label: 'Meetings',   icon: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z' },
      { href: '/important',  label: 'Important',  icon: 'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z' },
      { href: '/pipelines',  label: 'Pipelines',  icon: 'M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2' },
      { href: '/deals',      label: 'Deals',      icon: 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z' },
      { href: '/recurring-business', label: 'Recurring Business', icon: 'M4 4v6h6M20 20v-6h-6M5.64 18.36A9 9 0 0018.36 5.64L20 7M4 17l1.64 1.36' },
      { href: '/activities', label: 'Activities', icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4' },
      { href: '/calendar',   label: 'Calendar',   icon: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z' },
      { href: '/geographic-analytics', label: 'Geographic Analytics', icon: 'M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.21 0 4-4.03 4-9s-1.79-9-4-9-4 4.03-4 9 1.79 9 4 9zm-8.5-9h17M5 7h14M5 17h14' },
      { href: '/reports',    label: 'Reports',    icon: 'M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z' },
      { href: '/targets',    label: 'Targets',    icon: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z' },
    ],
  },
  {
    label: 'Admin',
    items: [
      { href: '/team',        label: 'Team',        icon: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z' },
      { href: '/departments', label: 'Departments', icon: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4' },
    ],
  },
  {
    label: 'Account',
    items: [
      { href: '/plans',    label: 'Plans',    icon: 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z' },
      { href: '/billing',  label: 'Billing',  icon: 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z' },
      { href: '/settings', label: 'Settings', icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z' },
    ],
  },
];

interface SidebarProps {
  open: boolean;
  onClose: () => void;
}

export default function Sidebar({ open, onClose }: SidebarProps) {
  const pathname = usePathname();
  const { user, isBusinessPlan, isEnterprisePlan, isPaidPlan, canManageBilling, canManageTeam, isSsoUser, clearAuth } = useAuthStore();
  const [showLogoutConfirm, setShowLogoutConfirm] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);

  useEffect(() => {
    onClose();
  }, [pathname]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!open) return;
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', handleKeyDown);
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', handleKeyDown);
      document.body.style.overflow = previousOverflow;
    };
  }, [open, onClose]);

  const isVisible = (href: string) => {
    if (href === '/team' || href === '/departments') return canManageTeam();
    if (href === '/billing') return canManageBilling() || isSsoUser();
    // Reports & Targets are Business/Enterprise-only features — hide when not on a paid plan
    if (href === '/reports' || href === '/geographic-analytics' || href === '/customer-growth') return isPaidPlan();
    if (href === '/calendar' || href === '/targets') return isPaidPlan();
    return true;
  };

  const isActive = (href: string) =>
    pathname === href || (href !== '/dashboard' && pathname.startsWith(href));

  const handleLogout = async () => {
    setLoggingOut(true);
    try {
      const { authApi } = await import('@/lib/api/auth');
      await authApi.logout();
    } catch { /* ignore */ } finally {
      clearAuth();
      window.location.href = '/login';
    }
  };

  return (
    <>
      <button
        type="button"
        aria-label="Close navigation"
        className={`fixed inset-0 z-40 bg-gray-950/55 backdrop-blur-[1px] transition-opacity lg:hidden ${
          open ? 'pointer-events-auto opacity-100' : 'pointer-events-none opacity-0'
        }`}
        onClick={onClose}
      />
      <aside
        aria-label="Main navigation"
        className={`fixed inset-y-0 left-0 z-50 flex h-[100dvh] w-[min(18rem,86vw)] shrink-0 flex-col border-r border-white/5 bg-gray-950 transition-transform duration-200 ease-out lg:static lg:z-auto lg:h-full lg:w-64 lg:translate-x-0 ${
          open ? 'translate-x-0 shadow-2xl' : '-translate-x-full'
        }`}
      >

      {/* Logo */}
      <div className="flex items-center gap-3 px-5 py-5 border-b border-white/5">
        <div className="w-8 h-8 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600
                        flex items-center justify-center shadow-lg shadow-indigo-500/30">
          <svg className="text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" width={17} height={17}>
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
          </svg>
        </div>
        <span className="text-white font-bold text-base tracking-tight">CRM</span>
        {isEnterprisePlan() ? (
          <span className="ml-auto relative flex items-center">
            <span className="absolute inset-0 rounded-full bg-violet-500/20 animate-ping" />
            <span className="relative bg-violet-500/20 text-violet-300 text-[10px] px-2 py-0.5
                             rounded-full font-semibold border border-violet-500/30 whitespace-nowrap">
              Enterprise
            </span>
          </span>
        ) : isBusinessPlan() ? (
          <span className="ml-auto relative flex items-center">
            <span className="absolute inset-0 rounded-full bg-indigo-500/20 animate-ping" />
            <span className="relative bg-indigo-500/20 text-indigo-300 text-[10px] px-2 py-0.5
                             rounded-full font-semibold border border-indigo-500/30 whitespace-nowrap">
              Business
            </span>
          </span>
        ) : (
          <span className="ml-auto bg-gray-500/20 text-gray-400 text-[10px] px-2 py-0.5
                           rounded-full font-semibold border border-gray-500/30 whitespace-nowrap">
            Free
          </span>
        )}
        <button
          type="button"
          aria-label="Close navigation"
          onClick={onClose}
          className="ml-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-400 transition-colors hover:bg-white/10 hover:text-white lg:hidden"
        >
          <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      {/* Nav */}
      <nav className="flex-1 px-3 py-4 space-y-5 overflow-y-auto">
        {SECTIONS.map((section) => {
          const visible = section.items.filter((item) => isVisible(item.href));
          if (!visible.length) return null;
          return (
            <div key={section.label}>
              <p className="px-3 mb-1.5 text-[10px] font-semibold tracking-widest text-gray-600 uppercase select-none">
                {section.label}
              </p>
              <div className="space-y-0.5">
                {visible.map((item) => {
                  const active = isActive(item.href);
                  return (
                    <Link
                      key={item.href}
                      href={item.href}
                      onClick={onClose}
                      className={[
                        'flex items-center gap-3 pl-[10px] pr-3 py-2.5 rounded-xl text-sm font-medium',
                        'transition-all duration-150 group relative border-l-2',
                        active
                          ? 'bg-white/10 text-white border-indigo-400'
                          : 'text-gray-500 hover:bg-white/5 hover:text-gray-200 border-transparent',
                      ].join(' ')}
                    >
                      <span className={`transition-colors duration-150 ${
                        active ? 'text-indigo-400' : 'text-gray-600 group-hover:text-gray-400'
                      }`}>
                        <NavIcon d={item.icon} />
                      </span>
                      <span className="flex-1">{item.label}</span>
                      {active && (
                        <span className="w-1.5 h-1.5 rounded-full bg-indigo-400 shrink-0" />
                      )}
                    </Link>
                  );
                })}
              </div>
            </div>
          );
        })}
      </nav>

      {/* User */}
      <div className="px-4 py-4 border-t border-white/5">
        <div className="flex items-center gap-3">
          <div className="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500
                          flex items-center justify-center text-white text-sm font-bold shrink-0 shadow-md">
            {user?.name?.[0]?.toUpperCase() ?? 'U'}
          </div>
          <div className="flex-1 min-w-0">
            <p className="text-sm font-semibold text-white truncate leading-tight">{user?.name}</p>
            <div className="flex items-center gap-1.5 mt-0.5">
              {user?.is_sso_user && (
                <span className="text-[10px] text-indigo-400 font-medium bg-indigo-400/10 px-1.5 py-0.5 rounded-full">
                  SSO
                </span>
              )}
              <span className="text-xs text-gray-500 capitalize truncate">{user?.role}</span>
            </div>
          </div>
          <button
            onClick={() => setShowLogoutConfirm(true)}
            title="Logout"
            className="text-gray-600 hover:text-red-400 transition-colors duration-150
                       p-1.5 rounded-lg hover:bg-red-500/10"
          >
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
            </svg>
          </button>
        </div>
      </div>

      {/* ── Logout confirmation modal ─────────────────────────────────────── */}
      {showLogoutConfirm && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          {/* Backdrop */}
          <div
            className="absolute inset-0 bg-black/60 backdrop-blur-sm"
            onClick={() => !loggingOut && setShowLogoutConfirm(false)}
          />

          {/* Card */}
          <div className="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 flex flex-col gap-4">
            {/* Icon */}
            <div className="w-12 h-12 rounded-full bg-red-100 flex items-center justify-center mx-auto">
              <svg className="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
              </svg>
            </div>

            {/* Text */}
            <div className="text-center">
              <h2 className="text-lg font-semibold text-gray-900">Sign out?</h2>
              <p className="mt-1 text-sm text-gray-500">
                Are you sure you want to sign out of your account?
              </p>
            </div>

            {/* Actions */}
            <div className="flex gap-3 mt-1">
              <button
                onClick={() => setShowLogoutConfirm(false)}
                disabled={loggingOut}
                className="flex-1 px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-medium
                           text-gray-700 hover:bg-gray-50 transition-colors disabled:opacity-50"
              >
                Cancel
              </button>
              <button
                onClick={handleLogout}
                disabled={loggingOut}
                className="flex-1 px-4 py-2.5 rounded-xl bg-red-500 hover:bg-red-600 text-white
                           text-sm font-medium transition-colors disabled:opacity-70 flex items-center
                           justify-center gap-2"
              >
                {loggingOut ? (
                  <>
                    <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                    </svg>
                    Signing out…
                  </>
                ) : (
                  'Proceed'
                )}
              </button>
            </div>
          </div>
        </div>
      )}
      </aside>
    </>
  );
}
