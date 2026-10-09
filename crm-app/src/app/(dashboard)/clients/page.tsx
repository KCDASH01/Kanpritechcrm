'use client';

import { useState } from 'react';
import Link from 'next/link';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { clientsApi } from '@/lib/api/clients';

export default function ClientsPage() {
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const { data, isLoading } = useQuery({
    queryKey: ['clients', search, page],
    queryFn: () => clientsApi.list({ search: search || undefined, page }),
    placeholderData: keepPreviousData,
  });

  return (
    <div className="animate-fade-in space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Clients</h1>
        <p className="mt-1 text-sm text-gray-500">Canonical customers and their complete commercial relationship.</p>
      </div>

      <input
        value={search}
        onChange={(event) => {
          setSearch(event.target.value);
          setPage(1);
        }}
        placeholder="Search name, company, email or phone…"
        className="w-full max-w-md rounded-xl border border-gray-200 px-4 py-2.5 text-sm"
      />

      <div className="touch-scroll overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
        {isLoading ? (
          <p className="p-10 text-center text-gray-400">Loading clients…</p>
        ) : (
          <table className="min-w-[760px] w-full text-sm">
            <thead>
              <tr className="border-b border-gray-100 bg-gray-50">
                {['Client', 'Contact', 'Owner', 'Leads', 'Deals', 'Action'].map((heading) => (
                  <th key={heading} className="px-5 py-3 text-left text-[11px] uppercase tracking-wide text-gray-500">
                    {heading}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {data?.data.map((client) => (
                <tr key={client.id} className="hover:bg-indigo-50/20">
                  <td className="px-5 py-4">
                    <p className="font-semibold text-gray-900">{client.company || client.full_name}</p>
                    <p className="text-xs text-gray-400">{client.company ? client.full_name : client.country}</p>
                  </td>
                  <td className="px-5 py-4 text-gray-600">
                    <p>{client.email || '—'}</p>
                    <p className="text-xs text-gray-400">{client.phone}</p>
                  </td>
                  <td className="px-5 py-4 text-gray-600">{client.assigned_to?.name || '—'}</td>
                  <td className="px-5 py-4">{client.leads_count ?? 0}</td>
                  <td className="px-5 py-4">{client.deals_count ?? 0}</td>
                  <td className="px-5 py-4">
                    <Link href={`/clients/${client.id}`} className="font-medium text-indigo-600 hover:underline">
                      View 360°
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {!isLoading && !data?.data.length && <p className="p-10 text-center text-gray-400">No clients found.</p>}
      </div>

      {data?.meta && data.meta.last_page > 1 && (
        <div className="flex justify-end gap-2">
          <button disabled={page === 1} onClick={() => setPage((current) => current - 1)} className="rounded-lg border px-3 py-2 disabled:opacity-40">
            Previous
          </button>
          <button disabled={page === data.meta.last_page} onClick={() => setPage((current) => current + 1)} className="rounded-lg border px-3 py-2 disabled:opacity-40">
            Next
          </button>
        </div>
      )}
    </div>
  );
}
