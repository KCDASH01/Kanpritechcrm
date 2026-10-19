'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { billingInvoicingApi, BillingDocument } from '@/lib/api/billingInvoicing';

const money = (value: string, currency: string) => new Intl.NumberFormat('en-IN', { style: 'currency', currency }).format(Number(value || 0));

export default function DocumentList({ type }: { type: 'proforma' | 'invoice' }) {
  const [rows, setRows] = useState<BillingDocument[]>([]); const [loading, setLoading] = useState(true); const [error, setError] = useState(''); const [search, setSearch] = useState('');
  useEffect(() => { setLoading(true); billingInvoicingApi.documents({ document_type: type, search }).then((r) => setRows(r.data)).catch(() => setError('Billing documents could not be loaded.')).finally(() => setLoading(false)); }, [type, search]);
  return <div className="rounded-2xl border border-gray-200 bg-white shadow-sm">
    <div className="border-b border-gray-100 p-4"><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search number or customer…" className="w-full max-w-md rounded-xl border border-gray-200 px-3 py-2 text-sm" /></div>
    {loading ? <p className="p-8 text-center text-gray-500">Loading…</p> : error ? <p className="p-8 text-center text-red-600">{error}</p> : rows.length === 0 ? <p className="p-8 text-center text-gray-500">No {type === 'invoice' ? 'invoices' : 'Proformas'} yet.</p> : <div className="divide-y divide-gray-100">{rows.map((row) => <Link href={`/billing-invoicing/documents/${row.id}`} key={row.id} className="grid gap-2 p-4 hover:bg-gray-50 sm:grid-cols-[1fr_auto_auto_auto] sm:items-center">
      <div><p className="font-semibold text-gray-900">{row.document_number || `Draft #${row.id}`}</p><p className="text-sm text-gray-500">{row.customer_snapshot?.company_name || row.customer_snapshot?.contact_name || 'Customer'}</p></div>
      <span className="w-fit rounded-full bg-gray-100 px-2 py-1 text-xs capitalize text-gray-700">{row.status.replaceAll('_', ' ')}</span>
      <span className="font-semibold text-gray-900">{money(row.grand_total, row.currency)}</span><span className="text-sm text-indigo-600">View →</span>
    </Link>)}</div>}
  </div>;
}
