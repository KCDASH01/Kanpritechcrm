'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { DocumentActions } from '@/components/billing/DocumentActions';
import { billingInvoicingApi, type BillingDocument } from '@/lib/api/billingInvoicing';

const money = (value: string, currency: string) => new Intl.NumberFormat('en-IN', { style: 'currency', currency }).format(Number(value || 0));

export default function DocumentList({ type }: { type: 'proforma' | 'invoice' }) {
  const [rows, setRows] = useState<BillingDocument[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [refresh, setRefresh] = useState(0);
  const [notice, setNotice] = useState('');

  useEffect(() => {
    const url = new URL(window.location.href);
    const deleted = url.searchParams.get('deleted');
    if (deleted) {
      setNotice(`${deleted} deleted.`);
      url.searchParams.delete('deleted');
      window.history.replaceState(null, '', url);
    }
  }, []);

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError('');
    billingInvoicingApi.documents({ document_type: type, search, page }).then((result) => {
      if (!active) return;
      setRows(result.data);
      setLastPage(result.last_page);
      if (page > result.last_page) setPage(result.last_page);
    }).catch(() => {
      if (active) setError('Billing documents could not be loaded.');
    }).finally(() => {
      if (active) setLoading(false);
    });
    return () => { active = false; };
  }, [type, search, page, refresh]);

  return <div className="rounded-2xl border border-gray-200 bg-white shadow-sm">
    <div className="border-b border-gray-100 p-4">
      <input value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} aria-label="Search billing documents"
        placeholder="Search number or customer…" className="w-full max-w-md rounded-xl border border-gray-200 px-3 py-2 text-sm" />
    </div>
    {notice && <p role="status" className="mx-4 mt-3 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{notice}</p>}
    {loading ? <p className="p-8 text-center text-gray-500">Loading…</p> : error ? <p role="alert" className="p-8 text-center text-red-600">{error}</p> : rows.length === 0 ? (
      <p className="p-8 text-center text-gray-500">No {type === 'invoice' ? 'invoices' : 'Proformas'} found.</p>
    ) : <div className="divide-y divide-gray-100">{rows.map((row) => {
      const label = row.document_number || `Draft #${row.id}`;
      const href = `/billing-invoicing/documents/${row.id}`;
      return <div key={row.id} className="flex flex-wrap items-center gap-3 p-4 hover:bg-gray-50">
        <Link href={href} className="min-w-36 flex-1">
          <p className="font-semibold text-gray-900">{label}</p>
          <p className="text-sm text-gray-500">{row.customer_snapshot?.company_name || row.customer_snapshot?.contact_name || 'Customer'}</p>
        </Link>
        <span className="w-fit rounded-full bg-gray-100 px-2 py-1 text-xs capitalize text-gray-700">{row.status.replaceAll('_', ' ')}</span>
        <span className="font-semibold text-gray-900">{money(row.grand_total, row.currency)}</span>
        <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto">
          <Link href={href} aria-label={`View ${label}`} className="inline-flex min-h-10 items-center px-2 text-sm text-indigo-600">View →</Link>
          <DocumentActions document={row} onDeleted={(deleted) => {
            setNotice(`${deleted} deleted.`);
            setRefresh((value) => value + 1);
          }} />
        </div>
      </div>;
    })}</div>}
    {lastPage > 1 && !error && <nav aria-label="Billing document pages" className="flex items-center justify-between gap-2 border-t p-4 text-sm">
      <button type="button" disabled={page <= 1 || loading} onClick={() => setPage(page - 1)} className="rounded-lg border px-3 py-2 disabled:opacity-50">Previous</button>
      <span>Page {page} of {lastPage}</span>
      <button type="button" disabled={page >= lastPage || loading} onClick={() => setPage(page + 1)} className="rounded-lg border px-3 py-2 disabled:opacity-50">Next</button>
    </nav>}
  </div>;
}
