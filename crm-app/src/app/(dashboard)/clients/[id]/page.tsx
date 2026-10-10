'use client';

import Link from 'next/link';
import { useParams } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import { clientsApi } from '@/lib/api/clients';
import { LEAD_TYPE_LABELS } from '@/lib/leadTypes';

const money = (value: number) => `₹${Number(value || 0).toLocaleString('en-IN')}`;

export default function ClientDetailsPage() {
  const id = Number(useParams<{ id: string }>().id);
  const { data, isLoading } = useQuery({
    queryKey: ['client', id],
    queryFn: () => clientsApi.get(id),
    enabled: !!id,
  });

  if (isLoading) return <p className="p-10 text-center text-gray-400">Loading client…</p>;
  if (!data) return <p className="p-10 text-center text-gray-400">Client not found.</p>;

  const { client, leads, deals, revenue } = data;
  const revenueCards = [
    ['One-Time Revenue', revenue.one_time_revenue],
    ['Recurring Revenue', revenue.recurring_revenue],
    ['Total Revenue', revenue.total_revenue],
  ];

  return (
    <div className="animate-fade-in space-y-6">
      <div className="flex flex-col items-stretch gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <Link href="/clients" className="text-xs text-indigo-600">← Clients</Link>
          <h1 className="mt-2 break-words text-2xl font-bold text-gray-900">{client.company || client.full_name}</h1>
          <p className="break-words text-sm text-gray-500">{[client.full_name, client.email, client.phone].filter(Boolean).join(' · ')}</p>
        </div>
        <Link href={`/leads?new=1&client_id=${client.id}`} className="rounded-xl bg-indigo-600 px-4 py-2.5 text-center text-sm font-semibold text-white hover:bg-indigo-700">
          + New Opportunity
        </Link>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div className="rounded-2xl border bg-white p-5">
          <h2 className="font-semibold text-gray-900">Client Information</h2>
          <dl className="mt-3 space-y-2 text-sm">
            {[
              ['Contact', client.full_name],
              ['Email', client.email],
              ['Phone', client.phone],
              ['Location', [client.city, client.state, client.country].filter(Boolean).join(', ')],
              ['Website', client.website],
            ].map(([key, value]) => (
              <div key={key} className="flex gap-3">
                <dt className="w-20 shrink-0 text-gray-400">{key}</dt>
                <dd className="min-w-0 break-all text-gray-700">{value || '—'}</dd>
              </div>
            ))}
          </dl>
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3 lg:col-span-2">
          {revenueCards.map(([key, value]) => (
            <div key={String(key)} className="min-w-0 rounded-2xl border bg-white p-4 sm:p-5">
              <p className="text-xs text-gray-500">{key}</p>
              <p className="mt-2 break-words text-xl font-bold text-gray-900">{money(Number(value))}</p>
            </div>
          ))}
        </div>
      </div>

      <section className="rounded-2xl border border-indigo-100 bg-indigo-50/50 p-4 sm:p-5">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div><h2 className="font-semibold text-gray-900">Customer Growth & Retention</h2><p className="mt-1 text-xs text-gray-500">Review recommended services, customer health, renewals and retention actions without changing this customer record.</p></div>
          <div className="flex flex-wrap gap-2"><Link href={`/customer-growth?client_id=${client.id}`} className="flex min-h-10 items-center rounded-xl bg-indigo-600 px-3 text-xs font-semibold text-white">Growth Opportunities</Link><Link href={`/customer-growth?client_id=${client.id}&tab=retention`} className="flex min-h-10 items-center rounded-xl border border-indigo-200 bg-white px-3 text-xs font-semibold text-indigo-700">Health & Renewals</Link></div>
        </div>
      </section>

      <section className="overflow-hidden rounded-2xl border bg-white">
        <h2 className="border-b px-4 py-4 font-semibold sm:px-5">Leads / Opportunities</h2>
        <div className="divide-y">
          {leads.map((lead) => (
            <Link key={lead.id} href={`/leads/${lead.id}`} className="flex flex-col gap-2 px-4 py-3 hover:bg-gray-50 sm:flex-row sm:items-center sm:justify-between sm:px-5">
              <div className="min-w-0">
                <p className="break-words font-medium text-gray-900">Lead #{lead.id} · {lead.types ? LEAD_TYPE_LABELS[lead.types] : 'Opportunity'}</p>
                <p className="text-xs text-gray-400">{lead.business_type?.replace('_', ' ')} · {lead.market_type}</p>
              </div>
              <span className="shrink-0 text-xs uppercase text-gray-500">{lead.status}</span>
            </Link>
          ))}
          {!leads.length && <p className="p-5 text-sm text-gray-400">No linked leads.</p>}
        </div>
      </section>

      <section className="overflow-hidden rounded-2xl border bg-white">
        <h2 className="border-b px-4 py-4 font-semibold sm:px-5">Deals & Recurring Business</h2>
        <div className="divide-y">
          {deals.map((deal) => (
            <div key={deal.id} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
              <div className="min-w-0">
                <p className="break-words font-medium text-gray-900">{deal.title}</p>
                <p className="text-xs text-gray-400">
                  {deal.business_type === 'RECURRING'
                    ? `${money(deal.recurring_amount || 0)} / ${deal.recurring_frequency?.toLowerCase().replace('_', '-')}`
                    : money(deal.value || 0)}
                </p>
              </div>
              <div className="shrink-0 sm:text-right">
                <span className="text-xs uppercase text-gray-500">{deal.status}</span>
                {deal.business_type === 'RECURRING' && (
                  <p><Link href="/recurring-business" className="text-xs text-violet-600">Recurring contract</Link></p>
                )}
              </div>
            </div>
          ))}
          {!deals.length && <p className="p-5 text-sm text-gray-400">No linked deals.</p>}
        </div>
      </section>
    </div>
  );
}
