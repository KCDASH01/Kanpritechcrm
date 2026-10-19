'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import BillingPage from '@/components/billing/BillingPage';
import { billingInvoicingApi, BillingOverview } from '@/lib/api/billingInvoicing';

export default function BillingInvoicingPage() {
  const [data, setData] = useState<BillingOverview | null>(null); const [error, setError] = useState('');
  useEffect(() => { billingInvoicingApi.overview().then(setData).catch(() => setError('Billing overview is unavailable. Please retry.')); }, []);
  const cards = data ? [['Issued invoices', data.issued_invoices], ['Invoiced', `₹${Number(data.invoiced_total).toLocaleString('en-IN')}`], ['Allocated collections', `₹${Number(data.allocated_total).toLocaleString('en-IN')}`], ['Outstanding', `₹${Number(data.outstanding_total).toLocaleString('en-IN')}`], ['Overdue', data.overdue_invoices], ['Draft / approvals', `${data.drafts} / ${data.pending_approvals}`]] : [];
  return <BillingPage title="Billing Overview" description="Document metrics are separate from CRM Deal revenue and Collection Targets." action={<Link href="/billing-invoicing/documents/new?type=invoice" className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">+ New Invoice</Link>}>
    {error ? <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700">{error}</div> : !data ? <p className="py-12 text-center text-gray-500">Loading billing metrics…</p> : <><div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{cards.map(([label, value]) => <div key={label} className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm"><p className="text-xs font-semibold uppercase text-gray-500">{label}</p><p className="mt-2 text-2xl font-bold text-gray-900">{value}</p></div>)}</div><div className="mt-6 rounded-2xl border border-gray-200 bg-white"><h2 className="border-b p-4 font-semibold">Recent documents</h2>{data.recent_documents.length ? data.recent_documents.map((d) => <Link className="flex justify-between border-b p-4 text-sm last:border-0 hover:bg-gray-50" href={`/billing-invoicing/documents/${d.id}`} key={d.id}><span>{d.document_number || `Draft #${d.id}`}</span><span>{d.currency} {d.grand_total}</span></Link>) : <p className="p-8 text-center text-gray-500">No billing documents yet.</p>}</div></>}
  </BillingPage>;
}
