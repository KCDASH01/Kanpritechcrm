'use client';

import Link from 'next/link';
import { useParams } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import { clientsApi } from '@/lib/api/clients';
import { LEAD_TYPE_LABELS } from '@/lib/leadTypes';

const money = (v: number) => `₹${Number(v || 0).toLocaleString('en-IN')}`;

export default function ClientDetailsPage() {
  const id = Number(useParams<{ id: string }>().id);
  const { data, isLoading } = useQuery({ queryKey: ['client', id], queryFn: () => clientsApi.get(id), enabled: !!id });
  if (isLoading) return <p className="p-10 text-center text-gray-400">Loading client…</p>;
  if (!data) return <p className="p-10 text-center text-gray-400">Client not found.</p>;
  const { client, leads, deals, revenue } = data;
  return <div className="space-y-6 animate-fade-in">
    <div className="flex flex-wrap items-start justify-between gap-4"><div><Link href="/clients" className="text-xs text-indigo-600">← Clients</Link><h1 className="text-2xl font-bold text-gray-900 mt-2">{client.company || client.full_name}</h1><p className="text-sm text-gray-500">{[client.full_name, client.email, client.phone].filter(Boolean).join(' · ')}</p></div><Link href={`/leads?new=1&client_id=${client.id}`} className="bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl px-4 py-2.5 text-sm font-semibold">+ New Opportunity</Link></div>
    <div className="grid grid-cols-1 lg:grid-cols-3 gap-4"><div className="bg-white border rounded-2xl p-5"><h2 className="font-semibold text-gray-900">Client Information</h2><dl className="mt-3 space-y-2 text-sm">{[['Contact',client.full_name],['Email',client.email],['Phone',client.phone],['Location',[client.city,client.state,client.country].filter(Boolean).join(', ')],['Website',client.website]].map(([k,v]) => <div key={k} className="flex gap-3"><dt className="w-20 text-gray-400">{k}</dt><dd className="text-gray-700 break-all">{v || '—'}</dd></div>)}</dl></div><div className="lg:col-span-2 grid grid-cols-3 gap-3">{[['One-Time Revenue',revenue.one_time_revenue],['Recurring Revenue',revenue.recurring_revenue],['Total Revenue',revenue.total_revenue]].map(([k,v]) => <div key={String(k)} className="bg-white border rounded-2xl p-5"><p className="text-xs text-gray-500">{k}</p><p className="text-xl font-bold text-gray-900 mt-2">{money(Number(v))}</p></div>)}</div></div>
    <section className="bg-white border rounded-2xl overflow-hidden"><h2 className="px-5 py-4 font-semibold border-b">Leads / Opportunities</h2><div className="divide-y">{leads.map((lead) => <Link key={lead.id} href={`/leads/${lead.id}`} className="flex items-center justify-between px-5 py-3 hover:bg-gray-50"><div><p className="font-medium text-gray-900">Lead #{lead.id} · {lead.types ? LEAD_TYPE_LABELS[lead.types] : 'Opportunity'}</p><p className="text-xs text-gray-400">{lead.business_type?.replace('_',' ')} · {lead.market_type}</p></div><span className="text-xs uppercase text-gray-500">{lead.status}</span></Link>)}{!leads.length && <p className="p-5 text-sm text-gray-400">No linked leads.</p>}</div></section>
    <section className="bg-white border rounded-2xl overflow-hidden"><h2 className="px-5 py-4 font-semibold border-b">Deals & Recurring Business</h2><div className="divide-y">{deals.map((deal) => <div key={deal.id} className="flex items-center justify-between px-5 py-3"><div><p className="font-medium text-gray-900">{deal.title}</p><p className="text-xs text-gray-400">{deal.business_type === 'RECURRING' ? `${money(deal.recurring_amount || 0)} / ${deal.recurring_frequency?.toLowerCase().replace('_','-')}` : money(deal.value || 0)}</p></div><div className="text-right"><span className="text-xs uppercase text-gray-500">{deal.status}</span>{deal.business_type === 'RECURRING' && <p><Link href="/recurring-business" className="text-xs text-violet-600">Recurring contract</Link></p>}</div></div>)}{!deals.length && <p className="p-5 text-sm text-gray-400">No linked deals.</p>}</div></section>
  </div>;
}
