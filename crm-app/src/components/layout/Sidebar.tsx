'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { GrowneqLogo } from '@/components/brand/GrowneqLogo';
import { usePathname } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import { billingInvoicingApi, type BillingPermission } from '@/lib/api/billingInvoicing';
import { useAuthStore } from '@/store/authStore';

const BILLING_EXPANSION_KEY = 'crm-sidebar-billing-expanded';

const ICONS = {
  dashboard: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
  people: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656-.126-1.283-.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
  integrations: 'M8 12h8m-4-4v8M5 5l3 3m11-3-3 3M5 19l3-3m11 3-3-3',
  pipelines: 'M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2',
  money: 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
  clients: 'M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2m7-10a4 4 0 100-8 4 4 0 000 8zm13 10v-2a4 4 0 00-3-3.87m-4-9.13a4 4 0 010 7.75',
  recurring: 'M4 4v6h6M20 20v-6h-6M5.64 18.36A9 9 0 0018.36 5.64L20 7M4 17l1.64 1.36',
  billing: 'M9 14l6-6m-5.5.5h.01m4.99 5h.01M5 3h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z',
  calendar: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
  activities: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
  important: 'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z',
  targets: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
  reports: 'M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z',
  globe: 'M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.21 0 4-4.03 4-9s-1.79-9-4-9-4 4.03-4 9 1.79 9 4 9zm-8.5-9h17M5 7h14M5 17h14',
  growth: 'M3 17l6-6 4 4 8-8M14 7h7v7',
  departments: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
  plans: 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z',
  card: 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z',
  settings: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
} as const;

function NavIcon({ d }: { d: string }) {
  return <svg aria-hidden="true" className="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d={d} /></svg>;
}

interface NavItem { href: string; label: string; icon: string }
interface NavSection { label: string; items: NavItem[] }

const SECTIONS: NavSection[] = [
  { label: 'Dashboard', items: [{ href: '/dashboard', label: 'Dashboard', icon: ICONS.dashboard }] },
  { label: 'Sales & CRM', items: [
    { href: '/leads', label: 'Leads', icon: ICONS.people },
    { href: '/lead-integrations', label: 'Lead Integrations', icon: ICONS.integrations },
    { href: '/pipelines', label: 'Pipelines', icon: ICONS.pipelines },
    { href: '/deals', label: 'Deals', icon: ICONS.money },
    { href: '/clients', label: 'Clients', icon: ICONS.clients },
    { href: '/recurring-business', label: 'Recurring Business', icon: ICONS.recurring },
  ] },
  { label: 'Daily Operations', items: [
    { href: '/follow-ups', label: 'Follow Up', icon: ICONS.calendar },
    { href: '/calendar', label: 'Calendar', icon: ICONS.calendar },
    { href: '/meetings', label: 'Meetings', icon: ICONS.people },
    { href: '/activities', label: 'Activities', icon: ICONS.activities },
    { href: '/important', label: 'Important', icon: ICONS.important },
  ] },
  { label: 'Analytics & Performance', items: [
    { href: '/targets', label: 'Targets', icon: ICONS.targets },
    { href: '/reports', label: 'Reports', icon: ICONS.reports },
    { href: '/geographic-analytics', label: 'Geographic Analytics', icon: ICONS.globe },
    { href: '/customer-growth', label: 'Customer Growth', icon: ICONS.growth },
  ] },
  { label: 'Administration', items: [
    { href: '/team', label: 'Team', icon: ICONS.people },
    { href: '/departments', label: 'Departments', icon: ICONS.departments },
  ] },
  { label: 'Account', items: [
    { href: '/plans', label: 'Plans', icon: ICONS.plans },
    { href: '/billing', label: 'Subscription & Payments', icon: ICONS.card },
    { href: '/settings', label: 'Settings', icon: ICONS.settings },
  ] },
];

interface BillingChild extends NavItem { permission?: BillingPermission }

const BILLING_CHILDREN: BillingChild[] = [
  { href: '/billing-invoicing', label: 'Billing Overview', icon: ICONS.billing, permission: 'view' },
  { href: '/billing-invoicing/catalogue', label: 'Items & Services', icon: ICONS.billing, permission: 'view' },
  { href: '/billing-invoicing/proformas', label: 'Proforma Invoices', icon: ICONS.billing, permission: 'view' },
  { href: '/billing-invoicing/invoices', label: 'Invoices', icon: ICONS.billing, permission: 'view' },
  { href: '/billing-invoicing/receipts', label: 'Payment Receipts', icon: ICONS.billing, permission: 'view' },
  { href: '/billing-invoicing/consolidated-receipts', label: 'Consolidated Receipts', icon: ICONS.billing, permission: 'view' },
  { href: '/billing-invoicing/payments', label: 'Payments & Outstanding', icon: ICONS.billing, permission: 'view' },
  { href: '/billing-invoicing/recurring', label: 'Recurring Billing', icon: ICONS.billing, permission: 'manage_recurring' },
  { href: '/billing-invoicing/settings', label: 'Billing Settings', icon: ICONS.billing, permission: 'manage_settings' },
];

interface SidebarProps { open: boolean; onClose: () => void }

function matchesRoute(pathname: string, href: string): boolean {
  return pathname === href || pathname.startsWith(`${href}/`);
}

export default function Sidebar({ open, onClose }: SidebarProps) {
  const pathname = usePathname();
  const { user, isBusinessPlan, isEnterprisePlan, isPaidPlan, canManageBilling, canManageTeam, isSsoUser, clearAuth } = useAuthStore();
  const [showLogoutConfirm, setShowLogoutConfirm] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
  const [manualBillingOpen, setManualBillingOpen] = useState(false);
  const [billingPreferenceReady, setBillingPreferenceReady] = useState(false);
  const billingRouteActive = matchesRoute(pathname, '/billing-invoicing');

  const billingPermissions = useQuery({
    queryKey: ['billing-invoicing-permissions'],
    queryFn: billingInvoicingApi.permissions,
    enabled: Boolean(user),
    staleTime: 5 * 60 * 1000,
    retry: false,
  });

  useEffect(() => {
    try {
      const saved = window.localStorage.getItem(BILLING_EXPANSION_KEY);
      if (saved === 'true' || saved === 'false') setManualBillingOpen(saved === 'true');
    } catch {
      // Storage can be unavailable in privacy modes. The in-memory state still works.
    } finally {
      setBillingPreferenceReady(true);
    }
  }, []);

  useEffect(() => { onClose(); }, [pathname]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!open) return;
    const handleKeyDown = (event: KeyboardEvent) => { if (event.key === 'Escape') onClose(); };
    document.addEventListener('keydown', handleKeyDown);
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', handleKeyDown);
      document.body.style.overflow = previousOverflow;
    };
  }, [open, onClose]);

  const effectiveBillingPermissions = billingPermissions.data?.effective;
  const visibleBillingChildren = BILLING_CHILDREN.filter((item) => {
    if (!item.permission || user?.role === 'owner') return true;
    return effectiveBillingPermissions?.[item.permission] === true;
  });
  const billingVisible = user?.role === 'owner' || (!billingPermissions.isLoading && visibleBillingChildren.length > 0);
  const billingOpen = billingRouteActive || (billingPreferenceReady && manualBillingOpen);

  const isVisible = (href: string) => {
    if (href === '/team' || href === '/departments') return canManageTeam();
    if (href === '/billing') return canManageBilling() || isSsoUser();
    if (['/reports', '/geographic-analytics', '/customer-growth', '/lead-integrations', '/calendar', '/targets'].includes(href)) return isPaidPlan();
    return true;
  };

  const setBillingExpansion = () => {
    const next = !billingOpen;
    setManualBillingOpen(next);
    try { window.localStorage.setItem(BILLING_EXPANSION_KEY, String(next)); } catch { /* Use in-memory state when storage is unavailable. */ }
  };

  const handleLogout = async () => {
    setLoggingOut(true);
    try {
      const { authApi } = await import('@/lib/api/auth');
      await authApi.logout();
    } catch {
      // Local logout must still complete if the remote session already expired.
    } finally {
      clearAuth();
      window.location.href = '/login';
    }
  };

  const navLink = (item: NavItem) => {
    const active = matchesRoute(pathname, item.href);
    return <Link key={item.href} href={item.href} onClick={onClose} aria-current={active ? 'page' : undefined} className={`group relative flex items-center gap-3 rounded-xl border-l-2 py-2.5 pl-[10px] pr-3 text-sm font-medium transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 ${active ? 'border-indigo-400 bg-white/10 text-white' : 'border-transparent text-gray-500 hover:bg-white/5 hover:text-gray-200'}`}><span className={`transition-colors ${active ? 'text-indigo-400' : 'text-gray-600 group-hover:text-gray-400'}`}><NavIcon d={item.icon} /></span><span className="min-w-0 flex-1">{item.label}</span>{active && <span aria-hidden="true" className="h-1.5 w-1.5 shrink-0 rounded-full bg-indigo-400" />}</Link>;
  };

  const renderSection = (section: NavSection, className: string) => {
    const visible = section.items.filter((item) => isVisible(item.href));
    if (!visible.length) return null;
    return <section key={section.label} className={className} aria-labelledby={`sidebar-${section.label.toLowerCase().replaceAll(/[^a-z]+/g, '-')}`}><h2 id={`sidebar-${section.label.toLowerCase().replaceAll(/[^a-z]+/g, '-')}`} className="mb-1.5 select-none px-3 text-[10px] font-semibold uppercase tracking-widest text-gray-600">{section.label}</h2><div className="space-y-0.5">{visible.map(navLink)}</div></section>;
  };

  return (
    <>
      <button type="button" aria-label="Close navigation" className={`fixed inset-0 z-40 bg-gray-950/55 backdrop-blur-[1px] transition-opacity motion-reduce:transition-none lg:hidden ${open ? 'pointer-events-auto opacity-100' : 'pointer-events-none opacity-0'}`} onClick={onClose} />
      <aside aria-label="Main navigation" className={`fixed inset-y-0 left-0 z-50 flex h-[100dvh] w-[min(18rem,86vw)] min-h-0 shrink-0 flex-col overflow-hidden border-r border-white/5 bg-gray-950 transition-transform duration-200 ease-out motion-reduce:transition-none lg:static lg:z-auto lg:h-full lg:w-64 lg:translate-x-0 ${open ? 'translate-x-0 shadow-2xl' : '-translate-x-full'}`}>
        <div className="flex shrink-0 items-center gap-2.5 border-b border-white/5 px-4 py-4">
          <GrowneqLogo variant="dark-icon" className="h-9 w-9 shrink-0 rounded-xl" priority />
          <span className="min-w-0 text-sm font-bold tracking-tight text-white">Growneq <span className="text-violet-300">CRM</span></span>
          {isEnterprisePlan() ? <span className="ml-auto whitespace-nowrap rounded-full border border-violet-500/30 bg-violet-500/20 px-2 py-0.5 text-[10px] font-semibold text-violet-300">Enterprise</span> : isBusinessPlan() ? <span className="ml-auto whitespace-nowrap rounded-full border border-indigo-500/30 bg-indigo-500/20 px-2 py-0.5 text-[10px] font-semibold text-indigo-300">Business</span> : user?.subscription?.plan === 'growth' ? <span className="ml-auto whitespace-nowrap rounded-full border border-emerald-500/30 bg-emerald-500/20 px-2 py-0.5 text-[10px] font-semibold text-emerald-300">Growth</span> : <span className="ml-auto whitespace-nowrap rounded-full border border-gray-500/30 bg-gray-500/20 px-2 py-0.5 text-[10px] font-semibold text-gray-400">Free</span>}
          <button type="button" aria-label="Close navigation" onClick={onClose} className="ml-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-400 transition-colors hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 lg:hidden"><svg aria-hidden="true" className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" /></svg></button>
        </div>

        <nav className="sidebar-scrollbar min-h-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain px-3 py-4" aria-label="CRM sections">
          {renderSection(SECTIONS[0], 'border-b border-white/5 pb-4')}
          {renderSection(SECTIONS[1], 'pt-5')}

          {billingVisible && <section className="pt-5" aria-labelledby="billing-finance-heading"><h2 id="billing-finance-heading" className="mb-1.5 select-none px-3 text-[10px] font-semibold uppercase tracking-widest text-gray-600">Billing &amp; Finance</h2><button type="button" onClick={setBillingExpansion} aria-expanded={billingOpen} aria-controls="billing-invoicing-menu" className={`group flex w-full items-center gap-3 rounded-xl border-l-2 py-2.5 pl-[10px] pr-3 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 ${billingRouteActive ? 'border-indigo-400 bg-white/10 text-white' : 'border-transparent text-gray-500 hover:bg-white/5 hover:text-gray-200'}`}><span className={billingRouteActive ? 'text-indigo-400' : 'text-gray-600 group-hover:text-gray-400'}><NavIcon d={ICONS.billing} /></span><span className="min-w-0 flex-1 text-left">Billing &amp; Invoicing</span><svg aria-hidden="true" className={`h-4 w-4 shrink-0 transition-transform duration-200 motion-reduce:transition-none ${billingOpen ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" /></svg></button><div className={`grid transition-[grid-template-rows,opacity] duration-200 motion-reduce:transition-none ${billingOpen ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'}`}><div className="overflow-hidden"><div id="billing-invoicing-menu" className="ml-5 mt-1 space-y-0.5 border-l border-white/10 pl-3">{visibleBillingChildren.map((child) => { const active = child.href === '/billing-invoicing' ? pathname === child.href : matchesRoute(pathname, child.href); return <Link key={child.href} href={child.href} onClick={onClose} aria-current={active ? 'page' : undefined} className={`block rounded-lg px-3 py-2 text-xs transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 ${active ? 'bg-indigo-500/15 font-semibold text-indigo-300' : 'text-gray-500 hover:bg-white/5 hover:text-gray-300'}`}>{child.label}</Link>; })}</div></div></div></section>}

          {SECTIONS.slice(2).map((section) => renderSection(section, 'pt-5'))}
        </nav>

        <div className="shrink-0 border-t border-white/5 bg-gray-950 px-4 py-4"><div className="flex items-center gap-3"><div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 text-sm font-bold text-white shadow-md">{user?.name?.[0]?.toUpperCase() ?? 'U'}</div><div className="min-w-0 flex-1"><p className="truncate text-sm font-semibold leading-tight text-white">{user?.name}</p><div className="mt-0.5 flex items-center gap-1.5">{user?.is_sso_user && <span className="rounded-full bg-indigo-400/10 px-1.5 py-0.5 text-[10px] font-medium text-indigo-400">SSO</span>}<span className="truncate text-xs capitalize text-gray-500">{user?.role}</span></div></div><button type="button" onClick={() => setShowLogoutConfirm(true)} aria-label="Logout" title="Logout" className="rounded-lg p-1.5 text-gray-600 transition-colors hover:bg-red-500/10 hover:text-red-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400"><svg aria-hidden="true" className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg></button></div></div>

        {showLogoutConfirm && <div className="fixed inset-0 z-[60] flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="logout-title"><div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={() => !loggingOut && setShowLogoutConfirm(false)} /><div className="relative flex w-full max-w-sm flex-col gap-4 rounded-2xl bg-white p-6 shadow-2xl"><div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100"><svg aria-hidden="true" className="h-6 w-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 013-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg></div><div className="text-center"><h2 id="logout-title" className="text-lg font-semibold text-gray-900">Sign out?</h2><p className="mt-1 text-sm text-gray-500">Are you sure you want to sign out of your account?</p></div><div className="mt-1 flex gap-3"><button type="button" onClick={() => setShowLogoutConfirm(false)} disabled={loggingOut} className="flex-1 rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:opacity-50">Cancel</button><button type="button" onClick={handleLogout} disabled={loggingOut} className="flex flex-1 items-center justify-center gap-2 rounded-xl bg-red-500 px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-red-600 disabled:opacity-70">{loggingOut ? 'Signing out…' : 'Proceed'}</button></div></div></div>}
      </aside>
    </>
  );
}
