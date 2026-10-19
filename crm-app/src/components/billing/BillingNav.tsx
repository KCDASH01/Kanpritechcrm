'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';

export const BILLING_LINKS = [
  ['/billing-invoicing', 'Overview'], ['/billing-invoicing/catalogue', 'Items & Services'],
  ['/billing-invoicing/proformas', 'Proforma Invoices'], ['/billing-invoicing/invoices', 'Invoices'],
  ['/billing-invoicing/receipts', 'Receipts'], ['/billing-invoicing/consolidated-receipts', 'Consolidated Receipts'],
  ['/billing-invoicing/payments', 'Payments & Outstanding'], ['/billing-invoicing/recurring', 'Recurring Billing'],
  ['/billing-invoicing/settings', 'Billing Settings'],
] as const;

export default function BillingNav() {
  const path = usePathname();
  return <nav className="mb-6 flex gap-2 overflow-x-auto pb-2" aria-label="Billing navigation">
    {BILLING_LINKS.map(([href, label]) => {
      const active = href === '/billing-invoicing' ? path === href : path.startsWith(href);
      return <Link key={href} href={href} className={`shrink-0 rounded-xl px-3 py-2 text-sm font-medium ${active ? 'bg-indigo-600 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-50'}`}>{label}</Link>;
    })}
  </nav>;
}
