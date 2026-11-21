'use client';

import { FormEvent, useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import type { Client, Deal, Lead } from '@/types';
import { clientsApi } from '@/lib/api/clients';
import { leadsApi } from '@/lib/api/leads';
import { billingInvoicingApi, BillingDocument, BillingLineItem, CatalogueItem } from '@/lib/api/billingInvoicing';

const CURRENCIES = [['INR', 'Indian Rupee'], ['USD', 'US Dollar'], ['EUR', 'Euro'], ['GBP', 'British Pound'], ['AED', 'UAE Dirham'], ['SGD', 'Singapore Dollar'], ['AUD', 'Australian Dollar'], ['CAD', 'Canadian Dollar']] as const;
const COUNTRIES = ['India', 'United States', 'United Kingdom', 'United Arab Emirates', 'Singapore', 'Canada', 'Australia', 'Germany', 'France'];
type CustomerSnapshot = Record<string, string | null>;
const emptyLine = (): BillingLineItem => ({ catalogue_item_id: null, description: '', quantity: 1, unit: 'unit', unit_price: 0, discount_type: null, discount_value: 0, gst_rate: 0 });
const emptyCustomer = (): CustomerSnapshot => ({ customer_type: 'business', contact_name: '', company_name: '', email: '', phone: '', alternative_phone: '', address_line1: '', address_line2: '', city: '', district: '', state: '', postal_code: '', country: 'India', gstin: '', pan: '', website: '', billing_contact_person: '', notes: '' });
const apiMessage = (error: unknown, fallback: string) => { const data = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data; return (data?.errors && Object.values(data.errors).flat()[0]) || data?.message || fallback; };
const customerFrom = (entry: Lead | Client): CustomerSnapshot => ({ ...emptyCustomer(), customer_type: entry.company ? 'business' : 'individual', contact_name: `${entry.first_name || ''} ${entry.last_name || ''}`.trim(), company_name: entry.company || '', email: entry.email || '', phone: entry.phone || '', city: entry.city || '', state: entry.state || '', country: entry.country || 'India', website: entry.website || '', notes: 'notes' in entry ? entry.notes || '' : '' });

export default function DocumentEditor({ type, initial, links }: { type: 'proforma' | 'invoice'; initial?: BillingDocument; links?: { lead_id?: number | null; client_id?: number | null; deal_id?: number | null } }) {
  const router = useRouter();
  const [catalogue, setCatalogue] = useState<CatalogueItem[]>([]);
  const [selectedLead, setSelectedLead] = useState<Lead | null>(null);
  const [selectedClient, setSelectedClient] = useState<Client | null>(null);
  const [deals, setDeals] = useState<Deal[]>([]);
  const [leadSearch, setLeadSearch] = useState(''); const [clientSearch, setClientSearch] = useState('');
  const [leadId, setLeadId] = useState<number | null>(links?.lead_id ?? initial?.lead_id ?? null); const [clientId, setClientId] = useState<number | null>(links?.client_id ?? initial?.client_id ?? null); const [dealId, setDealId] = useState<number | null>(links?.deal_id ?? initial?.deal_id ?? null);
  const [customerMode, setCustomerMode] = useState<'lead' | 'client' | 'manual'>(leadId ? 'lead' : clientId ? 'client' : 'manual'); const [customer, setCustomer] = useState<CustomerSnapshot>({ ...emptyCustomer(), ...(initial?.customer_snapshot || {}) });
  const [lines, setLines] = useState<BillingLineItem[]>(initial?.items?.length ? initial.items : [emptyLine()]);
  const [currency, setCurrency] = useState(initial?.currency || 'INR'); const [template, setTemplate] = useState(initial?.template || 'classic');
  const linkedConversion = Boolean(initial?.source_document_id);
  const [issueDate, setIssueDate] = useState(
    (!linkedConversion || initial?.tax_context?.draft_issue_date_selected) ? initial?.issue_date?.slice(0, 10) ?? '' : '',
  );
  const [dueDate, setDueDate] = useState(initial?.due_date?.slice(0, 10) || ''); const [notes, setNotes] = useState(initial?.notes || ''); const [terms, setTerms] = useState(initial?.terms || ''); const [paymentLink, setPaymentLink] = useState(initial?.tax_context?.payment_link || '');
  const [loadingSources, setLoadingSources] = useState(true); const [saving, setSaving] = useState(false); const [error, setError] = useState('');

  const selectingLead = type === 'proforma' && customerMode === 'lead';
  const selectingClient = type === 'invoice' || customerMode === 'client';
  const leadResults = useQuery({
    queryKey: ['billing-editor-leads', leadSearch.trim()],
    queryFn: () => leadsApi.list({ search: leadSearch.trim() || undefined, per_page: 100 }),
    enabled: selectingLead,
  });
  const clientResults = useQuery({
    queryKey: ['billing-editor-clients', clientSearch.trim()],
    queryFn: () => clientsApi.list({ search: clientSearch.trim() || undefined, per_page: 100 }),
    enabled: selectingClient && !linkedConversion,
  });
  const visibleLeads = leadResults.data?.data ?? [];
  const visibleClients = clientResults.data?.data ?? [];

  useEffect(() => {
    let active = true;
    void Promise.all([billingInvoicingApi.catalogue({ active: true, per_page: 100 }), billingInvoicingApi.settings()])
      .then(([catalogueRows, settings]) => {
        if (!active) return;
        setCatalogue(catalogueRows.data);
        if (!initial) {
          setCurrency(settings.default_currency || 'INR');
          setTemplate(settings.default_template || 'classic');
          setNotes(settings.default_notes || '');
          setTerms(settings.default_terms || '');
          setPaymentLink(settings.default_payment_link || '');
        }
      }).catch(() => { if (active) setError('Some billing source data could not be loaded.'); })
      .finally(() => { if (active) setLoadingSources(false); });
    return () => { active = false; };
  }, [initial]);

  useEffect(() => {
    if (!selectingClient || !clientId) return;
    let active = true;
    void clientsApi.get(clientId).then(({ client, deals: rows }) => {
      if (!active) return;
      setSelectedClient(client);
      setDeals(rows);
      if (!initial?.customer_snapshot?.contact_name && !initial?.customer_snapshot?.company_name) setCustomer(customerFrom(client));
    }).catch(() => { if (active && !initial?.customer_snapshot) setError('The selected Client or its Deals could not be loaded.'); });
    return () => { active = false; };
  }, [selectingClient, clientId, initial?.customer_snapshot]);

  useEffect(() => {
    if (!selectingLead || !leadId) return;
    let active = true;
    void leadsApi.get(leadId).then((lead) => {
      if (!active) return;
      setSelectedLead(lead);
      if (!initial?.customer_snapshot?.contact_name && !initial?.customer_snapshot?.company_name) setCustomer(customerFrom(lead));
    }).catch(() => { if (active && !initial?.customer_snapshot) setError('The selected Lead could not be loaded.'); });
    return () => { active = false; };
  }, [selectingLead, leadId, initial?.customer_snapshot]);

  const total = useMemo(() => lines.reduce((sum, line) => { const base = Number(line.quantity) * Number(line.unit_price); const discount = line.discount_type === 'percentage' ? base * Number(line.discount_value || 0) / 100 : line.discount_type === 'fixed' ? Number(line.discount_value || 0) : 0; const taxable = Math.max(0, base - discount); return sum + taxable + taxable * Number(line.gst_rate || 0) / 100; }, 0), [lines]);
  const updateLine = (index: number, patch: Partial<BillingLineItem>) => setLines((value) => value.map((line, i) => i === index ? { ...line, ...patch } : line));
  const updateCustomer = (key: string, value: string) => setCustomer((current) => ({ ...current, [key]: value }));
  const selectItem = (index: number, value: string) => { const item = catalogue.find((entry) => entry.id === Number(value)); if (!item) return updateLine(index, { catalogue_item_id: null }); updateLine(index, { catalogue_item_id: item.id, description: item.description || item.name, unit: item.unit, hsn_sac: item.hsn_sac, unit_price: item.prices[currency] ?? 0, gst_rate: item.default_tax_rate }); };
  const chooseLead = (value: string) => { const id = Number(value) || null; setLeadId(id); const lead = visibleLeads.find((row) => row.id === id); if (lead) { setSelectedLead(lead); setCustomer(customerFrom(lead)); } };
  const chooseClient = (value: string) => { const id = Number(value) || null; setClientId(id); setDealId(null); setDeals([]); const client = visibleClients.find((row) => row.id === id); if (client) { setSelectedClient(client); setCustomer(customerFrom(client)); } };
  async function submit(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setError('');
    const taxContext = { ...initial?.tax_context };
    if (paymentLink) taxContext.payment_link = paymentLink;
    else delete taxContext.payment_link;
    const customerUsesClient = type === 'invoice' || customerMode === 'client';
    const keepCommercialLink = type === 'proforma' && customerMode === 'lead' && leadId === initial?.lead_id;
    const payload = {
      document_type: type,
      lead_id: type === 'invoice' ? initial?.lead_id : customerMode === 'lead' ? leadId : null,
      client_id: customerUsesClient ? clientId : keepCommercialLink ? initial?.client_id : null,
      deal_id: customerUsesClient ? dealId : keepCommercialLink ? initial?.deal_id : null,
      customer_snapshot: customer,
      currency,
      template,
      issue_date: issueDate || null,
      due_date: dueDate || null,
      notes: notes || null,
      terms: terms || null,
      tax_context: taxContext,
      items: lines,
    };
    try {
      const doc = initial
        ? await billingInvoicingApi.updateDocument(initial.id, payload)
        : await billingInvoicingApi.createDocument({ ...payload, idempotency_key: crypto.randomUUID() });
      router.push(`/billing-invoicing/documents/${doc.id}${initial ? '?updated=1' : ''}`);
    } catch (problem) {
      setError(apiMessage(problem, 'The draft could not be saved. Check the customer, deal and line values.'));
    } finally {
      setSaving(false);
    }
  }

  const input = 'mt-1 w-full rounded-xl border border-gray-200 bg-white p-2.5 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100';

  return <form onSubmit={submit} className="space-y-5">
    <section className="rounded-2xl border bg-white p-4 shadow-sm"><h2 className="mb-1 font-semibold">Customer & commercial link</h2><p className="mb-4 text-xs text-gray-500">These values are copied into this draft. Corrections here never change the CRM record.</p>
      {type === 'proforma' && (
        <div className="mb-3 flex flex-wrap gap-2">
          {([['lead', 'Existing Lead'], ['client', 'Existing Client'], ['manual', 'Manual customer']] as const).map(([mode, label]) => (
            <button key={mode} type="button" onClick={() => setCustomerMode(mode)}
              className={`rounded-xl px-4 py-2 text-sm font-medium ${customerMode === mode ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700'}`}>
              {label}
            </button>
          ))}
        </div>
      )}
      {selectingLead && (
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="text-sm">Search Leads<input value={leadSearch} onChange={(e) => setLeadSearch(e.target.value)} placeholder="Name, company, email or phone" className={input} /></label>
          <label className="text-sm">Lead<select required value={leadId || ''} onChange={(e) => chooseLead(e.target.value)} className={input}>
            <option value="">Select Lead…</option>
            {leadId && !visibleLeads.some((lead) => lead.id === leadId) && <option value={leadId}>{selectedLead?.id === leadId ? selectedLead.full_name : initial?.lead?.full_name || `Lead #${leadId}`}</option>}
            {visibleLeads.map((lead) => <option value={lead.id} key={lead.id}>{lead.first_name} {lead.last_name} {lead.company ? `— ${lead.company}` : ''}</option>)}
          </select></label>
        </div>
      )}
      {selectingClient && (
        <div className="grid gap-3 sm:grid-cols-3">
          {!linkedConversion && <label className="text-sm">Search Clients<input value={clientSearch} onChange={(e) => setClientSearch(e.target.value)} placeholder="Name, company, email or phone" className={input} /></label>}
          <label className="text-sm">Client<select required disabled={linkedConversion} value={clientId || ''} onChange={(e) => chooseClient(e.target.value)} className={input}>
            <option value="">Select Client…</option>
            {clientId && !visibleClients.some((client) => client.id === clientId) && <option value={clientId}>{selectedClient?.id === clientId ? selectedClient.full_name : initial?.client?.company || `Client #${clientId}`}</option>}
            {visibleClients.map((client) => <option value={client.id} key={client.id}>{client.first_name} {client.last_name} {client.company ? `— ${client.company}` : ''}</option>)}
          </select></label>
          {(type === 'invoice' || dealId !== null) && <label className="text-sm">Associated Deal<select required={type === 'invoice'} disabled={!clientId || linkedConversion} value={dealId || ''} onChange={(e) => setDealId(Number(e.target.value) || null)} className={input}>
            <option value="">Select Deal…</option>
            {dealId && !deals.some((deal) => deal.id === dealId) && <option value={dealId}>{initial?.deal?.title || `Deal #${dealId}`}</option>}
            {deals.map((deal) => <option value={deal.id} key={deal.id}>{deal.title}</option>)}
          </select></label>}
        </div>
      )}
      {(selectingLead && leadResults.isError || selectingClient && clientResults.isError) && <p role="alert" className="mt-2 text-sm text-red-700">Customer search could not be loaded.</p>}
      <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><label className="text-sm">Customer type<select value={customer.customer_type || 'business'} onChange={(e) => updateCustomer('customer_type', e.target.value)} className={input}><option value="business">Business</option><option value="individual">Individual</option></select></label><label className="text-sm">Contact name<input value={customer.contact_name || ''} onChange={(e) => updateCustomer('contact_name', e.target.value)} className={input} /></label><label className="text-sm">Company<input value={customer.company_name || ''} onChange={(e) => updateCustomer('company_name', e.target.value)} className={input} /></label><label className="text-sm">Email<input type="email" value={customer.email || ''} onChange={(e) => updateCustomer('email', e.target.value)} className={input} /></label><label className="text-sm">Phone with country code<input value={customer.phone || ''} onChange={(e) => updateCustomer('phone', e.target.value)} className={input} /></label><label className="text-sm">Alternative phone<input value={customer.alternative_phone || ''} onChange={(e) => updateCustomer('alternative_phone', e.target.value)} className={input} /></label><label className="text-sm lg:col-span-2">Address line 1<input value={customer.address_line1 || ''} onChange={(e) => updateCustomer('address_line1', e.target.value)} className={input} /></label><label className="text-sm">Address line 2<input value={customer.address_line2 || ''} onChange={(e) => updateCustomer('address_line2', e.target.value)} className={input} /></label><label className="text-sm">City<input value={customer.city || ''} onChange={(e) => updateCustomer('city', e.target.value)} className={input} /></label><label className="text-sm">District (optional)<input value={customer.district || ''} onChange={(e) => updateCustomer('district', e.target.value)} className={input} /></label><label className="text-sm">State / Province<input value={customer.state || ''} onChange={(e) => updateCustomer('state', e.target.value)} className={input} /></label><label className="text-sm">Postal code<input value={customer.postal_code || ''} onChange={(e) => updateCustomer('postal_code', e.target.value)} className={input} /></label><label className="text-sm">Country (searchable)<input list="billing-country-options" value={customer.country || ''} onChange={(e) => updateCustomer('country', e.target.value)} className={input} /><datalist id="billing-country-options">{COUNTRIES.map((country) => <option key={country} value={country} />)}</datalist></label><label className="text-sm">GSTIN<input value={customer.gstin || ''} onChange={(e) => updateCustomer('gstin', e.target.value)} className={input} /></label><label className="text-sm">PAN / Tax ID<input value={customer.pan || ''} onChange={(e) => updateCustomer('pan', e.target.value)} className={input} /></label><label className="text-sm">Website<input type="url" value={customer.website || ''} onChange={(e) => updateCustomer('website', e.target.value)} className={input} /></label><label className="text-sm">Billing contact person<input value={customer.billing_contact_person || ''} onChange={(e) => updateCustomer('billing_contact_person', e.target.value)} className={input} /></label><label className="text-sm sm:col-span-2 lg:col-span-4">Customer notes<textarea value={customer.notes || ''} onChange={(e) => updateCustomer('notes', e.target.value)} rows={2} className={input} /></label></div>
    </section>
    <div className="rounded-2xl border bg-white p-4 shadow-sm"><div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><label className="text-sm">Currency<select value={currency} onChange={(e) => setCurrency(e.target.value)} className={input}>{CURRENCIES.map(([code, name]) => <option key={code} value={code}>{code} — {name}</option>)}</select></label><label className="text-sm">Template<select value={template} onChange={(e) => setTemplate(e.target.value)} className={input}>{['classic','modern','minimal','corporate','executive'].map((value) => <option key={value}>{value}</option>)}</select></label><label className="text-sm">Document date<input type="date" value={issueDate} onChange={(e) => setIssueDate(e.target.value)} className={input} /></label><label className="text-sm">Due date<input type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} className={input} /></label></div></div>
    <div className="space-y-3">{lines.map((line, index) => <section key={index} className="rounded-2xl border bg-white p-4 shadow-sm"><div className="mb-3 flex justify-between"><h2 className="font-semibold">Line {index + 1}</h2>{lines.length > 1 && <button type="button" onClick={() => setLines(lines.filter((_, i) => i !== index))} className="text-sm text-red-600">Remove</button>}</div><div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><label className="text-sm sm:col-span-2">Catalogue item (optional)<select value={line.catalogue_item_id || ''} onChange={(e) => selectItem(index, e.target.value)} className={input}><option value="">Custom line item</option>{line.catalogue_item_id && !catalogue.some((item) => item.id === line.catalogue_item_id) && <option value={line.catalogue_item_id}>{line.catalogue_snapshot?.name || `Catalogue item #${line.catalogue_item_id}`}</option>}{catalogue.map((item) => <option value={item.id} key={item.id}>{item.code} — {item.name}</option>)}</select></label><label className="text-sm sm:col-span-2">Description<input required value={line.description} onChange={(e) => updateLine(index,{description:e.target.value})} className={input} /></label><label className="text-sm">Quantity<input required type="number" min="0.0001" step="0.0001" value={line.quantity} onChange={(e) => updateLine(index,{quantity:e.target.value})} className={input} /></label><label className="text-sm">Unit<input value={line.unit || ''} onChange={(e) => updateLine(index,{unit:e.target.value})} className={input} /></label><label className="text-sm">Unit price<input required type="number" min="0" step="0.01" value={line.unit_price} onChange={(e) => updateLine(index,{unit_price:e.target.value})} className={input} /></label><label className="text-sm">HSN/SAC<input value={line.hsn_sac || ''} onChange={(e) => updateLine(index,{hsn_sac:e.target.value})} className={input} /></label><label className="text-sm">Discount<select value={line.discount_type || ''} onChange={(e) => updateLine(index,{discount_type:(e.target.value || null) as BillingLineItem['discount_type']})} className={input}><option value="">None</option><option value="fixed">Fixed</option><option value="percentage">Percentage</option></select></label><label className="text-sm">Discount value<input type="number" min="0" step="0.01" value={line.discount_value || 0} onChange={(e) => updateLine(index,{discount_value:e.target.value})} className={input} /></label><label className="text-sm">GST %<input type="number" min="0" max="100" step="0.01" value={line.gst_rate || 0} onChange={(e) => updateLine(index,{gst_rate:e.target.value})} className={input} /></label></div></section>)}</div>
    <button type="button" onClick={() => setLines([...lines, emptyLine()])} className="rounded-xl border border-indigo-200 px-4 py-2 text-sm font-medium text-indigo-600">+ Add line</button>
    <div className="grid gap-4 rounded-2xl border bg-white p-4 sm:grid-cols-2"><label className="text-sm">Notes<textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={3} className={input} /></label><label className="text-sm">Terms & conditions<textarea value={terms} onChange={(e) => setTerms(e.target.value)} rows={3} className={input} /></label><label className="text-sm sm:col-span-2">Payment link (optional)<input type="url" value={paymentLink} onChange={(e) => setPaymentLink(e.target.value)} placeholder="https://…" className={input} /></label></div>
    {error && <p className="rounded-xl bg-red-50 p-3 text-sm text-red-700">{error}</p>}<div className="sticky bottom-4 flex flex-col gap-3 rounded-2xl border bg-white/95 p-4 shadow-lg backdrop-blur sm:flex-row sm:items-center sm:justify-between"><div><p className="text-xs text-gray-500">Estimate only; the server recalculates authoritatively</p><p className="text-xl font-bold">{currency} {total.toLocaleString('en-IN',{maximumFractionDigits:2})}</p></div><button disabled={saving || loadingSources} className="rounded-xl bg-indigo-600 px-5 py-2.5 font-semibold text-white disabled:opacity-50">{saving ? 'Saving…' : loadingSources ? 'Loading…' : initial ? 'Update draft' : 'Save draft'}</button></div>
  </form>;
}
