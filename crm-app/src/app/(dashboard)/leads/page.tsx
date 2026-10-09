'use client';

import { useState, useRef, useEffect } from 'react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import { motion, AnimatePresence } from 'framer-motion';
import { leadsApi, type LeadPayload, type LeadTimelineEntry, type ConvertPayload } from '@/lib/api/leads';
import { activitiesApi } from '@/lib/api/activities';
import { pipelinesApi } from '@/lib/api/pipelines';
import { employeesApi } from '@/lib/api/employees';
import { useAuthStore } from '@/store/authStore';
import { getLimits } from '@/lib/planLimits';
import { PlanLimitBar } from '@/components/ui/PlanLimitBar';
import { toWhatsAppNumber } from '@/lib/phone';
import { whatsappTemplatesApi } from '@/lib/api/whatsappTemplates';
import { subscriptionApi } from '@/lib/api/subscription';
import type { Lead, PaginatedResponse } from '@/types';
import { Modal } from '@/components/ui/Modal';
import { Badge } from '@/components/ui/Badge';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { TIMELINE_CONFIG } from '@/lib/timeline';
import { ReminderModal } from '@/components/leads/ReminderModal';
import { ScheduleStatusModal } from '@/components/leads/ScheduleStatusModal';
import { RemarkStatusModal } from '@/components/leads/RemarkStatusModal';
import { ConvertToDealFormFromLead } from '@/components/leads/ConvertToDealForm';
import { LEAD_STATUSES, LEAD_STATUS_LABELS, LEAD_STATUS_MENU, isScheduledLeadStatus, isRemarkLeadStatus, type ScheduledLeadStatus, type RemarkLeadStatus } from '@/lib/leadStatuses';
import { LEAD_TYPES, LEAD_TYPE_LABELS } from '@/lib/leadTypes';
import { clientsApi } from '@/lib/api/clients';
import type { Client } from '@/types';

// ── Constants ─────────────────────────────────────────────────────────────────

const LOST_REASONS = [
  'Price too high',
  'Went with competitor',
  'No budget',
  'No response',
  'Not a fit',
  'Other',
];

// ── Helpers ───────────────────────────────────────────────────────────────────
const AVATAR_GRADIENTS = [
  'from-indigo-400 to-violet-500', 'from-emerald-400 to-teal-500',
  'from-blue-400 to-sky-500',      'from-pink-400 to-rose-500',
  'from-amber-400 to-orange-500',  'from-purple-400 to-fuchsia-500',
];

const inputCls = 'w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-shadow bg-white';

function formatLeadDateDisplay(iso?: string) {
  const d = iso ? new Date(`${iso}T00:00:00`) : new Date();
  return d.toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' });
}

function formatLeadTableDate(lead: Lead) {
  const iso = lead.lead_date ?? lead.created_at;
  if (!iso) return '—';
  const d = lead.lead_date ? new Date(`${iso}T00:00:00`) : new Date(iso);
  return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
}

// ── Lead form (create / edit) ─────────────────────────────────────────────────
function LeadForm({ lead, presetClientId, onSave, onClose, saving, phoneError, onPhoneChange }: {
  lead?: Lead | null;
  presetClientId?: number;
  onSave: (d: LeadPayload) => void;
  onClose: () => void;
  saving?: boolean;
  phoneError?: string | null;
  onPhoneChange?: () => void;
}) {
  const isPaidPlan = useAuthStore((s) => s.isPaidPlan)();
  const isAdmin    = useAuthStore((s) => s.isAdmin)();

  const { data: employees, isLoading: employeesLoading } = useQuery({
    queryKey: ['employees', { role: 'employee' }],
    queryFn:  () => employeesApi.list({ role: 'employee' }),
    enabled:  isPaidPlan && isAdmin,
    staleTime: 5 * 60_000,
    retry:    false,
  });

  const employeeOptions = employees ?? [];

  const [form, setForm] = useState<LeadPayload>({
    client_type: lead?.client_type ?? (presetClientId ? 'EXISTING' : 'NEW'),
    client_id: lead?.client_id ?? presetClientId ?? null,
    business_type: lead?.business_type ?? 'ONE_TIME',
    market_type: lead?.market_type ?? 'DOMESTIC',
    first_name: lead?.first_name ?? '',
    last_name:  lead?.last_name  ?? '',
    email:      lead?.email      ?? '',
    phone:      lead?.phone      ?? '',
    company:    lead?.company    ?? '',
    job_title:  lead?.job_title  ?? '',
    website:    lead?.website    ?? '',
    status:     lead?.status     ?? 'new',
    source:     lead?.source     ?? 'manual',
    types:      lead?.types      ?? '',
    assigned_to: lead?.assigned_to?.id ?? null,
    city:       lead?.city       ?? '',
    state:      lead?.state      ?? '',
    country:    lead?.country    ?? '',
    notes:      lead?.notes      ?? '',
    expected_value: lead?.expected_value ?? null,
    currency: lead?.currency ?? 'INR',
    recurring_frequency: lead?.recurring_frequency ?? 'MONTHLY',
    recurring_amount: lead?.recurring_amount ?? null,
    recurring_start_date: lead?.recurring_start_date ?? '',
    recurring_end_type: lead?.recurring_end_type ?? 'ONGOING',
    recurring_end_date: lead?.recurring_end_date ?? null,
    next_billing_date: lead?.next_billing_date ?? null,
    billing_cycles: lead?.billing_cycles ?? null,
    contract_value: lead?.contract_value ?? null,
  });
  const [clientSearch, setClientSearch] = useState('');

  const duplicateTerm = form.client_type === 'EXISTING'
    ? clientSearch
    : (form.email?.trim() || form.phone?.trim() || form.company?.trim() || '');
  const { data: clientResults, isFetching: clientsLoading } = useQuery({
    queryKey: ['clients', 'lead-picker', duplicateTerm],
    queryFn: () => clientsApi.list({ search: duplicateTerm, per_page: 8 }),
    enabled: duplicateTerm.length >= 2,
    staleTime: 30_000,
  });
  const { data: presetClient } = useQuery({
    queryKey: ['client', presetClientId],
    queryFn: () => clientsApi.get(presetClientId!),
    enabled: !!presetClientId && !lead,
  });

  const applyClient = (client: Client) => {
    setForm((f) => ({
      ...f, client_type: 'EXISTING', client_id: client.id,
      first_name: client.first_name ?? '', last_name: client.last_name ?? '', company: client.company ?? '',
      email: client.email ?? '', phone: client.phone ?? '', job_title: client.job_title ?? '',
      website: client.website ?? '', city: client.city ?? '', state: client.state ?? '', country: client.country ?? '',
    }));
    setClientSearch(client.company || client.full_name);
    onPhoneChange?.();
  };

  useEffect(() => {
    if (presetClient?.client) applyClient(presetClient.client);
  // apply once when the requested client loads
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [presetClient?.client.id]);

  const set = (k: keyof LeadPayload, v: string) => {
    if (k === 'phone') onPhoneChange?.();
    setForm((f) => ({ ...f, [k]: v }));
  };
  const number = (k: keyof LeadPayload, v: string) => setForm((f) => ({ ...f, [k]: v === '' ? null : Number(v) }));

  const addFrequency = (iso: string, frequency?: string | null) => {
    if (!iso) return null;
    const d = new Date(`${iso}T00:00:00`);
    const months = frequency === 'QUARTERLY' ? 3 : frequency === 'HALF_YEARLY' ? 6 : frequency === 'YEARLY' ? 12 : 1;
    d.setMonth(d.getMonth() + months);
    return d.toISOString().slice(0, 10);
  };

  const calculatedContract = form.recurring_amount && form.billing_cycles
    ? Number(form.recurring_amount) * Number(form.billing_cycles) : null;
  const canSubmit = !!form.types && !!form.client_type && !!form.business_type && !!form.market_type
    && (form.client_type === 'EXISTING' ? !!form.client_id : !!form.first_name.trim())
    && (form.business_type !== 'RECURRING' || (!!form.recurring_frequency && !!form.recurring_amount && !!form.recurring_start_date
      && (form.recurring_end_type !== 'FIXED' || !!form.recurring_end_date)));

  const submit = () => onSave({
    ...form,
    contract_value: form.business_type === 'RECURRING' ? (calculatedContract ?? form.contract_value ?? null) : null,
    next_billing_date: form.business_type === 'RECURRING'
      ? (form.next_billing_date || addFrequency(form.recurring_start_date ?? '', form.recurring_frequency)) : null,
  });

  const field = (key: keyof LeadPayload, label: string, type = 'text', required = false, readOnly = false) => (
    <div>
      <label className="block text-xs font-semibold text-gray-600 mb-1">{label}{required ? ' *' : ''}</label>
      <input type={type} value={(form[key] as string | number | null) ?? ''} readOnly={readOnly}
        onChange={(e) => type === 'number' ? number(key, e.target.value) : set(key, e.target.value)}
        className={`${inputCls} ${readOnly ? 'bg-gray-50 text-gray-500' : ''} ${key === 'phone' && phoneError ? 'border-red-300 ring-1 ring-red-200' : ''}`} />
      {key === 'phone' && phoneError && <p className="mt-1 text-xs text-red-600">{phoneError}</p>}
    </div>
  );

  return (
    <div className="space-y-5 max-h-[72vh] overflow-y-auto pr-1">
      <section>
        <h3 className="text-xs font-bold uppercase tracking-wider text-indigo-600 mb-3">Client Information</h3>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
          <div><label className="block text-xs font-semibold text-gray-600 mb-1">Lead Date</label><input value={formatLeadDateDisplay(lead?.lead_date)} readOnly className={`${inputCls} bg-gray-50 text-gray-500`} /></div>
          <div>
            <label className="block text-xs font-semibold text-gray-600 mb-1">Client Type *</label>
            <select value={form.client_type} onChange={(e) => setForm((f) => ({ ...f, client_type: e.target.value as 'NEW' | 'EXISTING', client_id: e.target.value === 'NEW' ? null : f.client_id }))} className={inputCls}>
              <option value="NEW">New Client</option><option value="EXISTING">Existing Client</option>
            </select>
          </div>
          <div>
            <label className="block text-xs font-semibold text-gray-600 mb-1">Market Type *</label>
            <select value={form.market_type} onChange={(e) => setForm((f) => ({ ...f, market_type: e.target.value as 'DOMESTIC' | 'INTERNATIONAL' }))} className={inputCls}>
              <option value="DOMESTIC">Domestic</option><option value="INTERNATIONAL">International</option>
            </select>
          </div>
          {form.client_type === 'EXISTING' && (
            <div className="md:col-span-2 lg:col-span-3 relative">
              <label className="block text-xs font-semibold text-gray-600 mb-1">Select Existing Client *</label>
              <input value={clientSearch} onChange={(e) => { setClientSearch(e.target.value); setForm((f) => ({ ...f, client_id: null })); }}
                placeholder="Search name, company, email or phone…" className={inputCls} />
              {clientSearch.length >= 2 && !form.client_id && (
                <div className="absolute z-20 mt-1 w-full bg-white border border-gray-200 rounded-xl shadow-xl max-h-48 overflow-auto">
                  {clientsLoading ? <p className="p-3 text-sm text-gray-400">Searching…</p> : clientResults?.data.length ? clientResults.data.map((client) => (
                    <button type="button" key={client.id} onClick={() => applyClient(client)} className="w-full text-left px-3 py-2.5 hover:bg-indigo-50 border-b border-gray-50 last:border-0">
                      <p className="text-sm font-semibold text-gray-800">{client.company || client.full_name}</p>
                      <p className="text-xs text-gray-400">{[client.full_name, client.email, client.phone].filter(Boolean).join(' · ')}</p>
                    </button>
                  )) : <p className="p-3 text-sm text-gray-400">No matching clients.</p>}
                </div>
              )}
            </div>
          )}
          {field('first_name', 'First Name', 'text', form.client_type === 'NEW', form.client_type === 'EXISTING')}
          {field('last_name', 'Last Name', 'text', false, form.client_type === 'EXISTING')}
          {field('company', 'Company', 'text', false, form.client_type === 'EXISTING')}
          {field('email', 'Email', 'email', false, form.client_type === 'EXISTING')}
          {field('phone', 'Phone', 'tel', false, form.client_type === 'EXISTING')}
          {field('job_title', 'Job Title', 'text', false, form.client_type === 'EXISTING')}
          {field('city', 'City', 'text', false, form.client_type === 'EXISTING')}
          {field('state', 'State / Province', 'text', false, form.client_type === 'EXISTING')}
          {field('country', 'Country', 'text', false, form.client_type === 'EXISTING')}
          {field('website', 'Website', 'url', false, form.client_type === 'EXISTING')}
        </div>
        {form.client_type === 'NEW' && duplicateTerm.length >= 2 && !!clientResults?.data.length && (
          <div className="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3">
            <p className="text-xs font-semibold text-amber-800">An existing client with similar contact information may already exist.</p>
            <div className="mt-2 flex flex-wrap gap-2">{clientResults.data.slice(0, 3).map((client) => (
              <button type="button" key={client.id} onClick={() => applyClient(client)} className="text-xs bg-white border border-amber-200 rounded-lg px-2.5 py-1.5 text-amber-800 hover:bg-amber-100">Use {client.company || client.full_name}</button>
            ))}</div>
          </div>
        )}
      </section>

      <section className="border-t border-gray-100 pt-4">
        <h3 className="text-xs font-bold uppercase tracking-wider text-indigo-600 mb-3">Opportunity & Business</h3>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1">Service / Product *</label>
          <select
            value={form.types ?? ''}
            onChange={(e) => set('types', e.target.value)}
            className={inputCls}
          >
            <option value="">Select type</option>
            {LEAD_TYPES.map((t) => (
              <option key={t} value={t}>{LEAD_TYPE_LABELS[t]}</option>
            ))}
          </select>
        </div>
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1">Business Type *</label>
          <select value={form.business_type} onChange={(e) => setForm((f) => ({ ...f, business_type: e.target.value as 'ONE_TIME' | 'RECURRING' }))} className={inputCls}>
            <option value="ONE_TIME">One Time</option><option value="RECURRING">Recurring</option>
          </select>
        </div>
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1">Source</label>
          <select value={form.source ?? 'manual'} onChange={(e) => set('source', e.target.value)} className={inputCls}>
            <option value="manual">Manual</option>
            <option value="web_form">Web Form</option>
            <option value="csv">CSV Import</option>
            <option value="api">API</option>
            <option value="sso_import">SSO Import</option>
            <option value="meta_ad">Meta Ad</option>
            <option value="email_campaign">Email Campaign</option>
            <option value="google_ads">Google Ads</option>
            <option value="other">Other</option>
          </select>
        </div>
        {form.business_type === 'ONE_TIME' && field('expected_value', 'Expected Value', 'number')}
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1">Currency</label>
          <select value={form.currency ?? 'INR'} onChange={(e) => setForm((f) => ({ ...f, currency: e.target.value as 'INR' | 'USD' }))} className={inputCls}>
            <option value="INR">INR</option><option value="USD">USD</option>
          </select>
        </div>
        {isAdmin && (
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1">Assign to</label>
          <select
            value={form.assigned_to ?? ''}
            onChange={(e) => setForm((f) => ({
              ...f,
              assigned_to: e.target.value ? Number(e.target.value) : null,
            }))}
            className={inputCls}
          >
            <option value="">— Unassigned —</option>
            {employeesLoading && <option value="" disabled>Loading team…</option>}
            {employeeOptions.map((emp) => (
              <option key={emp.id} value={emp.id}>{emp.name}</option>
            ))}
          </select>
        </div>
        )}
      </div>
      </section>

      {form.business_type === 'RECURRING' && (
        <section className="border-t border-gray-100 pt-4 rounded-xl bg-indigo-50/40 p-4">
          <h3 className="text-xs font-bold uppercase tracking-wider text-indigo-600 mb-3">Recurring Business</h3>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            <div><label className="block text-xs font-semibold text-gray-600 mb-1">Frequency *</label>
              <select value={form.recurring_frequency ?? 'MONTHLY'} onChange={(e) => setForm((f) => ({ ...f, recurring_frequency: e.target.value as LeadPayload['recurring_frequency'], next_billing_date: addFrequency(f.recurring_start_date ?? '', e.target.value) }))} className={inputCls}>
                <option value="MONTHLY">Monthly</option><option value="QUARTERLY">Quarterly</option><option value="HALF_YEARLY">Half-Yearly</option><option value="YEARLY">Yearly</option>
              </select></div>
            {field('recurring_amount', 'Recurring Amount', 'number', true)}
            <div><label className="block text-xs font-semibold text-gray-600 mb-1">Start Date *</label><input type="date" value={form.recurring_start_date ?? ''} onChange={(e) => setForm((f) => ({ ...f, recurring_start_date: e.target.value, next_billing_date: addFrequency(e.target.value, f.recurring_frequency) }))} className={inputCls}/></div>
            <div><label className="block text-xs font-semibold text-gray-600 mb-1">End Type *</label><select value={form.recurring_end_type ?? 'ONGOING'} onChange={(e) => setForm((f) => ({ ...f, recurring_end_type: e.target.value as 'ONGOING' | 'FIXED', recurring_end_date: e.target.value === 'ONGOING' ? null : f.recurring_end_date }))} className={inputCls}><option value="ONGOING">Ongoing / No End Date</option><option value="FIXED">Fixed End Date</option></select></div>
            {form.recurring_end_type === 'FIXED' && field('recurring_end_date', 'End Date', 'date', true)}
            {field('next_billing_date', 'Next Billing Date', 'date')}
            {field('billing_cycles', 'Number of Billing Cycles', 'number')}
            <div><label className="block text-xs font-semibold text-gray-600 mb-1">Estimated Contract Value</label><input readOnly value={calculatedContract ?? form.contract_value ?? ''} className={`${inputCls} bg-gray-50 text-gray-600`} /></div>
          </div>
          <p className="text-[11px] text-gray-500 mt-2">Contract value is projected only. Revenue is recognized from recorded payments.</p>
        </section>
      )}

      <div>
        <label className="block text-xs font-semibold text-gray-600 mb-1">Notes</label>
        <textarea rows={3} value={form.notes ?? ''} onChange={(e) => set('notes', e.target.value)}
          className={`${inputCls} resize-none`} />
      </div>
      <div className="flex gap-3 pt-1">
        <button
          onClick={submit}
          disabled={saving || !canSubmit}
          className="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white font-semibold
                     py-2.5 rounded-xl text-sm flex items-center justify-center gap-2 transition-colors"
        >
          {saving && <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>}
          {lead ? 'Save Changes' : 'Create Lead'}
        </button>
        <button onClick={onClose} className="px-5 py-2.5 text-sm text-gray-500 hover:text-gray-700 border border-gray-200 rounded-xl transition-colors">
          Cancel
        </button>
      </div>
    </div>
  );
}

// ── Lead detail side panel ────────────────────────────────────────────────────
function LeadPanel({ lead, onClose, onEdit }: { lead: Lead; onClose: () => void; onEdit: () => void }) {
  const [tab, setTab] = useState<'info' | 'timeline'>('timeline');

  const { data: timeline, isLoading: loadingTimeline } = useQuery({
    queryKey: ['lead-timeline', lead.id],
    queryFn: () => leadsApi.timeline(lead.id),
    enabled: tab === 'timeline',
  });

  const INFO_ROWS: [string, string | undefined | null][] = [
    ['Email',    lead.email],
    ['Phone',    lead.phone],
    ['Company',  lead.company],
    ['Job Title',lead.job_title],
    ['Website',  lead.website],
    ['Status',   lead.status],
    ['Type',     lead.types ? LEAD_TYPE_LABELS[lead.types] : null],
    ['Source',   lead.source],
    ['City',     lead.city],
    ['Country',  lead.country],
    ['Score',    lead.score != null ? String(lead.score) : null],
  ];

  return (
    <motion.div
      initial={{ x: '100%' }}
      animate={{ x: 0 }}
      exit={{ x: '100%' }}
      transition={{ type: 'spring', damping: 30, stiffness: 350 }}
      className="fixed inset-y-0 right-0 z-50 flex w-full max-w-[420px] flex-col border-l border-gray-100 bg-white shadow-2xl shadow-black/20"
    >
      {/* Header */}
      <div className="px-5 py-4 border-b border-gray-100 flex items-center gap-3 bg-gray-50/80">
        <div className="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 flex items-center justify-center text-white font-bold text-sm shadow-sm">
          {lead.full_name[0]?.toUpperCase()}
        </div>
        <div className="flex-1 min-w-0">
          <p className="font-bold text-gray-900 text-sm truncate">{lead.full_name}</p>
          <p className="text-xs text-gray-400 truncate">{lead.company ?? lead.email ?? '—'}</p>
        </div>
        <div className="flex items-center gap-1 shrink-0">
          <button onClick={onEdit} className="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors" title="Edit">
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
          </button>
          <button onClick={onClose} className="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors">
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>
      </div>

      {/* Status badge row */}
      <div className="px-5 py-2.5 border-b border-gray-50 flex items-center gap-2">
        <Badge value={lead.status} />
        {lead.source && <span className="text-xs text-gray-400 bg-gray-100 px-2 py-0.5 rounded-full capitalize">{lead.source.replace('_', ' ')}</span>}
        {lead.score != null && <span className="text-xs font-semibold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded-full">Score: {lead.score}</span>}
      </div>

      {/* Tabs */}
      <div className="flex border-b border-gray-100">
        {(['timeline', 'info'] as const).map((t) => (
          <button
            key={t}
            onClick={() => setTab(t)}
            className={`flex-1 py-2.5 text-xs font-semibold transition-colors ${
              tab === t ? 'text-indigo-600 border-b-2 border-indigo-500' : 'text-gray-500 hover:text-gray-700'
            }`}
          >
            {t === 'timeline' ? '📋 Timeline' : '📋 Info'}
          </button>
        ))}
      </div>

      {/* Tab content */}
      <div className="flex-1 overflow-y-auto">
        {tab === 'info' && (
          <div className="p-5 space-y-3">
            {INFO_ROWS.filter(([, v]) => v).map(([label, value]) => (
              <div key={label} className="flex items-start gap-3">
                <span className="text-xs text-gray-400 w-20 shrink-0 pt-0.5">{label}</span>
                <span className="text-sm text-gray-800 font-medium flex-1 break-all">
                  {label === 'Status' ? (
                    <Badge value={value!} />
                  ) : label === 'Website' ? (
                    <a href={value!.startsWith('http') ? value! : `https://${value}`} target="_blank" rel="noopener noreferrer" className="text-indigo-600 hover:underline">
                      {value!.replace(/^https?:\/\//, '')}
                    </a>
                  ) : value}
                </span>
              </div>
            ))}
            {lead.notes && (
              <div className="mt-3 pt-3 border-t border-gray-100">
                <p className="text-xs text-gray-400 mb-1.5">Notes</p>
                <p className="text-sm text-gray-700 leading-relaxed whitespace-pre-line">{lead.notes}</p>
              </div>
            )}
          </div>
        )}

        {tab === 'timeline' && (
          <div className="p-4">
            {loadingTimeline ? (
              <div className="space-y-3">
                {Array.from({ length: 4 }).map((_, i) => (
                  <div key={i} className="flex gap-3">
                    <div className="skeleton w-8 h-8 rounded-full shrink-0" />
                    <div className="flex-1 space-y-1.5 pt-1">
                      <div className="skeleton h-3.5 w-3/4 rounded" />
                      <div className="skeleton h-3 w-1/3 rounded" />
                    </div>
                  </div>
                ))}
              </div>
            ) : !timeline?.length ? (
              <div className="text-center py-10 text-gray-400">
                <p className="text-2xl mb-2">📋</p>
                <p className="text-sm">No activity logged yet.</p>
              </div>
            ) : (
              <div className="relative">
                {/* Vertical line */}
                <div className="absolute left-4 top-0 bottom-0 w-px bg-gray-100" />
                <div className="space-y-4">
                  {timeline.map((entry) => {
                    const cfg = TIMELINE_CONFIG[entry.action] ?? { icon: '•', color: 'text-gray-500', bg: 'bg-gray-100' };
                    const date = new Date(entry.created_at);
                    return (
                      <div key={entry.id} className="flex gap-3 relative">
                        <div className={`w-8 h-8 rounded-full ${cfg.bg} flex items-center justify-center shrink-0 z-10 text-sm border-2 border-white shadow-sm`}>
                          <span className={`text-xs ${cfg.color}`}>{cfg.icon}</span>
                        </div>
                        <div className="flex-1 min-w-0 pb-1">
                          <p className="text-xs font-medium text-gray-800 leading-snug">{entry.description}</p>
                          <div className="flex items-center gap-2 mt-0.5">
                            {entry.user && <span className="text-[10px] text-gray-400">{entry.user.name}</span>}
                            <span className="text-[10px] text-gray-300">·</span>
                            <span className="text-[10px] text-gray-400">
                              {date.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })}
                              {' '}
                              {date.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' })}
                            </span>
                          </div>
                          {/* Show meta diff for updates */}
                          {entry.action === 'updated' && entry.meta && (
                            <div className="mt-1.5 bg-gray-50 rounded-lg px-2 py-1.5 space-y-0.5">
                              {Object.entries(entry.meta).map(([field, diff]: [string, unknown]) => {
                                const d = diff as { from: string; to: string };
                                return (
                                  <p key={field} className="text-[10px] text-gray-500">
                                    <span className="font-medium capitalize">{field.replace('_', ' ')}</span>:
                                    {' '}<span className="line-through text-red-400">{d.from || '—'}</span>
                                    {' → '}
                                    <span className="text-green-600">{d.to || '—'}</span>
                                  </p>
                                );
                              })}
                            </div>
                          )}
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            )}
          </div>
        )}
      </div>
    </motion.div>
  );
}

// ── Lost Reason Modal ─────────────────────────────────────────────────────────
function LostReasonModal({ lead, onClose, onConfirm, saving }: {
  lead: Lead;
  onClose: () => void;
  onConfirm: (reason: string | undefined) => void;
  saving?: boolean;
}) {
  const [reason, setReason] = useState('');
  const inputCls = 'w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-shadow bg-white';

  return (
    <Modal open onClose={onClose} title={`Mark "${lead.full_name}" as Lost`} maxWidth="max-w-sm">
      <div className="space-y-4">
        <p className="text-sm text-gray-500">
          Select a reason for losing this lead. You can skip this if you prefer.
        </p>
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Lost Reason</label>
          <select value={reason} onChange={(e) => setReason(e.target.value)} className={inputCls}>
            <option value="">No reason specified</option>
            {LOST_REASONS.map((r) => <option key={r} value={r}>{r}</option>)}
          </select>
        </div>
        <div className="flex gap-3 pt-1">
          <button
            onClick={onClose}
            className="flex-1 border border-gray-200 text-gray-700 font-semibold py-2.5 rounded-xl text-sm hover:bg-gray-50 transition-colors"
          >
            Cancel
          </button>
          <button
            onClick={() => onConfirm(reason || undefined)}
            disabled={saving}
            className="flex-1 bg-red-600 hover:bg-red-700 disabled:opacity-60 text-white font-semibold py-2.5 rounded-xl text-sm flex items-center justify-center gap-2 transition-colors"
          >
            {saving && (
              <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
            )}
            Mark as Lost
          </button>
        </div>
      </div>
    </Modal>
  );
}

// ── WhatsApp Template Modal ───────────────────────────────────────────────────
function WhatsAppModal({ lead, userId, onClose, onSent }: {
  lead: Lead;
  userId: number;
  onClose: () => void;
  onSent: () => void;
}) {
  const [sending, setSending] = useState(false);

  const { data: templates, isLoading: tplLoading } = useQuery({
    queryKey: ['whatsapp-templates'],
    queryFn:  whatsappTemplatesApi.list,
    staleTime: 10 * 60_000,
  });

  const renderMsg = (msg: string) =>
    msg.replace(/\{\{name\}\}/g, lead.full_name)
       .replace(/\{\{company\}\}/g, lead.company ?? '');

  const handleSend = async (rawMsg: string) => {
    if (!lead.phone) return;
    const msg = renderMsg(rawMsg);
    const num = toWhatsAppNumber(lead.phone);
    window.open(`https://wa.me/${num}?text=${encodeURIComponent(msg)}`, '_blank');

    setSending(true);
    try {
      await activitiesApi.create({
        subject_type: 'lead',
        subject_id:   lead.id,
        type:         'whatsapp',
        title:        `WhatsApp sent to ${lead.full_name}`,
        description:  msg,
        due_at:       new Date().toISOString(),
        assigned_to:  lead.assigned_to?.id ?? userId,
        priority:     'low',
        is_done:      true,
      });
      onSent();
    } finally {
      setSending(false);
    }
  };

  return (
    <Modal open onClose={onClose} title={`WhatsApp — ${lead.full_name}`} maxWidth="max-w-md">
      <div className="space-y-3">
        {!lead.phone ? (
          <div className="bg-amber-50 border border-amber-200 text-amber-700 rounded-xl px-4 py-3 text-sm">
            No phone number on record for this lead.
          </div>
        ) : tplLoading ? (
          <div className="space-y-2">
            {[...Array(4)].map((_, i) => (
              <div key={i} className="skeleton h-12 rounded-xl" />
            ))}
          </div>
        ) : !templates || templates.length === 0 ? (
          <div className="bg-gray-50 border border-gray-200 text-gray-500 rounded-xl px-4 py-3 text-sm text-center">
            No WhatsApp templates configured.<br/>
            <span className="text-xs text-gray-400">Ask your admin to add templates in the Admin Dashboard.</span>
          </div>
        ) : (
          <>
            <p className="text-xs text-gray-400">Select a message template to open WhatsApp and log the activity:</p>
            <div className="space-y-2">
              {templates.map((tpl) => (
                <button
                  key={tpl.id}
                  disabled={sending}
                  onClick={() => handleSend(tpl.message)}
                  className="w-full text-left p-3 border border-gray-200 rounded-xl hover:border-green-400 hover:bg-green-50 transition-all text-sm disabled:opacity-50 group"
                >
                  <div className="flex items-start gap-2">
                    <span className="text-green-600 shrink-0 mt-0.5">💬</span>
                    <div className="min-w-0">
                      <p className="text-[10px] font-semibold text-green-700 uppercase tracking-wide mb-0.5">{tpl.name}</p>
                      <p className="text-gray-700 leading-relaxed">{renderMsg(tpl.message)}</p>
                    </div>
                  </div>
                </button>
              ))}
            </div>
          </>
        )}
        <button
          onClick={onClose}
          className="w-full border border-gray-200 text-gray-600 py-2 rounded-xl text-sm hover:bg-gray-50 transition-colors"
        >
          Cancel
        </button>
      </div>
    </Modal>
  );
}

// ── Actions dropdown per row ──────────────────────────────────────────────────
function ActionsMenu({ lead, onEdit, onDelete, onView, onConvert, onChangeStatus, onReminder, onWhatsApp, onProposal, canEdit, canDelete }: {
  lead: Lead;
  onEdit: () => void;
  onDelete: () => void;
  onView: () => void;
  onConvert: () => void;
  onChangeStatus: (s: string) => void;
  onReminder: () => void;
  onWhatsApp: () => void;
  onProposal: () => void;
  canEdit: boolean;
  canDelete: boolean;
}) {
  const [open, setOpen] = useState(false);
  const [statusMenuOpen, setStatusMenuOpen] = useState(false);
  const [pos, setPos] = useState<{ top?: number; bottom?: number; right: number }>({ top: 0, right: 0 });
  const [statusPos, setStatusPos] = useState<{ top?: number; bottom?: number; right: number } | null>(null);
  const btnRef = useRef<HTMLButtonElement>(null);
  const statusBtnRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!open) {
      setStatusMenuOpen(false);
      setStatusPos(null);
      return;
    }
    const handler = (e: MouseEvent) => {
      if (btnRef.current && !btnRef.current.closest('[data-menu]')?.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open]);

  const toggle = () => {
    if (!open && btnRef.current) {
      const r = btnRef.current.getBoundingClientRect();
      const MENU_HEIGHT = 340; // approximate max height of the dropdown
      const spaceBelow = window.innerHeight - r.bottom;
      if (spaceBelow < MENU_HEIGHT) {
        // not enough room below — open upward
        setPos({ bottom: window.innerHeight - r.top + 4, right: window.innerWidth - r.right });
      } else {
        setPos({ top: r.bottom + 4, right: window.innerWidth - r.right });
      }
    }
    if (open) setStatusMenuOpen(false);
    setOpen((v) => !v);
  };

  const toggleStatusMenu = () => {
    if (!statusMenuOpen && statusBtnRef.current) {
      const r = statusBtnRef.current.getBoundingClientRect();
      const submenuHeight = LEAD_STATUS_MENU.length * 34 + 12;
      const spaceBelow = window.innerHeight - r.bottom;
      const right = window.innerWidth - r.left + 6;

      if (spaceBelow < submenuHeight) {
        setStatusPos({ bottom: window.innerHeight - r.bottom, right });
      } else {
        setStatusPos({ top: r.top, right });
      }
    } else {
      setStatusPos(null);
    }
    setStatusMenuOpen((v) => !v);
  };

  const menuItemCls = 'w-full text-left px-3 py-2 text-xs text-gray-700 hover:bg-gray-50 flex items-center gap-2.5 transition-colors cursor-pointer';

  return (
    <div data-menu="true">
      <button
        ref={btnRef}
        onClick={toggle}
        className="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors"
        title="More actions"
      >
        <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
          <circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>
        </svg>
      </button>

      <AnimatePresence>
        {open && (
          <motion.div
            data-menu="true"
            initial={{ opacity: 0, scale: 0.95, y: pos.bottom ? 4 : -4 }}
            animate={{ opacity: 1, scale: 1, y: 0 }}
            exit={{ opacity: 0, scale: 0.95, y: pos.bottom ? 4 : -4 }}
            transition={{ type: 'spring', damping: 30, stiffness: 400 }}
            style={{ position: 'fixed', top: pos.top, bottom: pos.bottom, right: pos.right, zIndex: 9999 }}
            className="w-48 bg-white border border-gray-100 rounded-xl shadow-xl shadow-black/10 py-1"
          >
            <button onClick={() => { onView(); setOpen(false); }} className={menuItemCls}>
              <svg className="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
              View Timeline
            </button>
            {canEdit && (
              <button onClick={() => { onEdit(); setOpen(false); }} className={menuItemCls}>
                <svg className="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                Edit Lead
              </button>
            )}
            <button onClick={() => { onReminder(); setOpen(false); }} className={`${menuItemCls} text-amber-700`}>
              <svg className="w-3.5 h-3.5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              Set Reminder
            </button>
            <button onClick={() => { onWhatsApp(); setOpen(false); }} className={`${menuItemCls} text-green-700`}>
              <svg className="w-3.5 h-3.5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
              WhatsApp
            </button>
            <button onClick={() => { onProposal(); setOpen(false); }} className={`${menuItemCls} text-indigo-700`}>
              {(lead.proposals_count ?? 0) > 0 ? (
                <>
                  <svg className="w-3.5 h-3.5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                  View Proposal
                </>
              ) : (
                <>
                  <svg className="w-3.5 h-3.5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                  Create Proposal
                </>
              )}
            </button>

            <div className="mx-2 my-1 border-t border-gray-100" />

            {lead.status !== 'converted' && (
              <button onClick={() => { onConvert(); setOpen(false); }} className={`${menuItemCls} text-emerald-700`}>
                <svg className="w-3.5 h-3.5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Convert to Deal
              </button>
            )}

            {lead.status === 'converted' ? (
              <div className={`${menuItemCls} opacity-40 cursor-not-allowed`}>
                <svg className="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                Status Locked (Converted)
              </div>
            ) : (
              <div className="relative">
                <button
                  ref={statusBtnRef}
                  type="button"
                  onClick={toggleStatusMenu}
                  className={`${menuItemCls} justify-between`}
                  aria-expanded={statusMenuOpen}
                  aria-haspopup="menu"
                >
                  <span className="flex items-center gap-2.5">
                    <svg className="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 16V4m0 0L3 8m4-4l4 4M17 8v12m0 0l4-4m-4 4l-4-4"/></svg>
                    Change Status
                  </span>
                  <svg className="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7"/></svg>
                </button>
              </div>
            )}

            {statusMenuOpen && statusPos && (
              <motion.div
                data-menu="true"
                initial={{ opacity: 0, x: 6 }}
                animate={{ opacity: 1, x: 0 }}
                exit={{ opacity: 0, x: 6 }}
                style={{
                  position: 'fixed',
                  top: statusPos.top,
                  bottom: statusPos.bottom,
                  right: statusPos.right,
                  zIndex: 10000,
                }}
                className="w-44 bg-white border border-gray-100 rounded-xl shadow-xl shadow-black/10 py-1 max-h-[min(18rem,calc(100vh-1rem))] overflow-y-auto"
              >
                {LEAD_STATUS_MENU.map((s) => (
                  <button
                    key={s}
                    onClick={() => { onChangeStatus(s); setOpen(false); }}
                    className={`${menuItemCls} ${lead.status === s ? 'bg-indigo-50 text-indigo-700' : ''}`}
                  >
                    <span className={`w-1.5 h-1.5 rounded-full shrink-0 ${lead.status === s ? 'bg-indigo-500' : 'bg-gray-300'}`} />
                    {LEAD_STATUS_LABELS[s]}
                  </button>
                ))}
              </motion.div>
            )}

            <div className="mx-2 my-1 border-t border-gray-100" />

            {canDelete && (
            <button onClick={() => { onDelete(); setOpen(false); }} className={`${menuItemCls} text-red-600`}>
              <svg className="w-3.5 h-3.5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
              Delete Lead
            </button>
            )}
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}

// ── Empty state ───────────────────────────────────────────────────────────────
function EmptyLeads({ onAdd, canAdd }: { onAdd: () => void; canAdd: boolean }) {
  return (
    <div className="flex flex-col items-center justify-center py-16 px-4">
      <div className="w-16 h-16 bg-indigo-50 rounded-2xl flex items-center justify-center mb-4">
        <svg className="w-8 h-8 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
            d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
      </div>
      <p className="text-gray-800 font-semibold text-base mb-1">No leads assigned to you</p>
      <p className="text-gray-400 text-sm mb-5">
        {canAdd ? 'Add your first lead to start tracking your pipeline.' : 'Contact your manager to get leads assigned to you.'}
      </p>
      {canAdd && (
        <button onClick={onAdd}
          className="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-5 py-2.5 rounded-xl text-sm transition-colors">
          + Add First Lead
        </button>
      )}
    </div>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────
export default function LeadsPage() {
  const qc     = useQueryClient();
  const router = useRouter();
  const { user, isOwner, isAdmin, isEmployee, isPaidPlan } = useAuthStore();

  // Non-owners default to "My Leads"; owners default to "All Leads"
  const isManager     = isOwner() || isAdmin();
  const canCreateLead = isManager || isEmployee();
  const canEditLead   = (lead: Lead) =>
    isManager || (isEmployee() && lead.assigned_to?.id === user?.id);

  const searchParams = useSearchParams();

  const search           = searchParams.get('search') ?? '';
  const statusFilter     = searchParams.get('status') ?? '';
  const typeFilter       = searchParams.get('types') ?? '';
  const clientTypeFilter = searchParams.get('client_type') ?? '';
  const businessTypeFilter = searchParams.get('business_type') ?? '';
  const marketTypeFilter = searchParams.get('market_type') ?? '';
  const dateFromFilter   = searchParams.get('date_from') ?? '';
  const dateToFilter     = searchParams.get('date_to') ?? '';
  const departmentFilter = searchParams.get('department') ?? '';
  const page             = Math.max(1, parseInt(searchParams.get('page') ?? '1', 10) || 1);
  const viewMine         = !isManager ? true : (searchParams.get('view') ?? 'all') === 'mine';
  const teamMemberFilter = searchParams.get('member') ?? '';
  const showDepartmentFilters = isOwner();

  const updateParams = (patch: Record<string, string | null | undefined>) => {
    const params = new URLSearchParams(searchParams.toString());
    for (const [key, val] of Object.entries(patch)) {
      if (val === null || val === undefined || val === '') params.delete(key);
      else params.set(key, val);
    }
    const qs = params.toString();
    router.replace(qs ? `/leads?${qs}` : '/leads', { scroll: false });
  };

  // modal states
  const [modalLead, setModalLead]           = useState<Lead | null | undefined>(undefined); // undefined=closed
  const presetClientId = Number(searchParams.get('client_id')) || undefined;
  const [panelLead, setPanelLead]           = useState<Lead | null>(null);
  const [convertLead, setConvertLead]       = useState<Lead | null>(null);
  const [reminderLead, setReminderLead]     = useState<Lead | null>(null);
  const [lostReasonLead, setLostReasonLead] = useState<Lead | null>(null);
  const [scheduleStatusLead, setScheduleStatusLead] = useState<{ lead: Lead; status: ScheduledLeadStatus } | null>(null);
  const [remarkStatusLead, setRemarkStatusLead] = useState<{ lead: Lead; status: RemarkLeadStatus } | null>(null);
  const [waLead, setWaLead]                 = useState<Lead | null>(null); // WhatsApp template picker
  const [leadPhoneError, setLeadPhoneError] = useState<string | null>(null);

  useEffect(() => {
    if (searchParams.get('new') === '1') setModalLead(null);
  }, [searchParams]);

  // Fetch pipelines at page level so they're ready before the modal opens
  const { data: pipelines } = useQuery({
    queryKey: ['pipelines'],
    queryFn:  pipelinesApi.list,
    staleTime: 10 * 60_000,
  });

  const { data: teamMembers } = useQuery({
    queryKey: ['employees', { role: 'employee' }],
    queryFn:  () => employeesApi.list({ role: 'employee' }),
    enabled:  isManager,
    staleTime: 5 * 60_000,
    retry:    false,
  });

  const unassignedFilter = isManager && !viewMine && teamMemberFilter === 'unassigned';
  const assignedToFilter = !isManager
    ? user?.id
    : viewMine
      ? user?.id
      : teamMemberFilter && teamMemberFilter !== 'unassigned'
        ? Number(teamMemberFilter)
        : undefined;

  const listFilters = {
    search:        search || undefined,
    status:        statusFilter || undefined,
    types:         typeFilter || undefined,
    client_type:   clientTypeFilter || undefined,
    business_type: businessTypeFilter || undefined,
    market_type:   marketTypeFilter || undefined,
    date_from:     dateFromFilter || undefined,
    date_to:       dateToFilter || undefined,
    department_id: showDepartmentFilters && departmentFilter ? Number(departmentFilter) : undefined,
    assigned_to:   assignedToFilter,
    unassigned:    unassignedFilter || undefined,
    page,
  };

  const hasActiveFilters = !!(
    search || statusFilter || typeFilter || clientTypeFilter || businessTypeFilter || marketTypeFilter || teamMemberFilter || dateFromFilter || dateToFilter || departmentFilter
  );

  const clearAllFilters = () =>
    updateParams({
      search: null, status: null, types: null, client_type: null, business_type: null, market_type: null, member: null,
      date_from: null, date_to: null, department: null, page: null,
    });

  const { data: liveSub } = useQuery({
    queryKey: ['subscription'],
    queryFn:  subscriptionApi.get,
    staleTime: 5 * 60_000,
  });

  const { data: departmentCounts, isLoading: loadingDeptCounts } = useQuery({
    queryKey: ['lead-department-counts'],
    queryFn:  () => leadsApi.departmentCounts(),
    enabled:  showDepartmentFilters,
    staleTime: 60_000,
  });

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['leads', listFilters],
    queryFn:  () => leadsApi.list(listFilters),
    placeholderData: keepPreviousData,
  });

  // Create / Update
  const createMutation = useMutation({
    mutationFn: (payload: LeadPayload) => leadsApi.create(payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['leads'] });
      qc.invalidateQueries({ queryKey: ['lead-department-counts'] });
      setLeadPhoneError(null);
      setModalLead(undefined);
    },
    onError: (err: unknown) => {
      const phoneMsg = (err as { response?: { data?: { errors?: { phone?: string[] } } } })?.response?.data?.errors?.phone?.[0];
      if (phoneMsg) {
        setLeadPhoneError(phoneMsg);
        return;
      }
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      alert(msg ?? 'Failed to create lead.');
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<LeadPayload> }) => leadsApi.update(id, payload),
    onSuccess: (updated) => {
      qc.invalidateQueries({ queryKey: ['leads'] });
      qc.invalidateQueries({ queryKey: ['lead-department-counts'] });
      qc.invalidateQueries({ queryKey: ['lead-timeline', updated.id] });
      setLeadPhoneError(null);
      setModalLead(undefined);
      // Refresh panel if same lead
      if (panelLead?.id === updated.id) setPanelLead(updated);
    },
    onError: (err: unknown) => {
      const phoneMsg = (err as { response?: { data?: { errors?: { phone?: string[] } } } })?.response?.data?.errors?.phone?.[0];
      if (phoneMsg) {
        setLeadPhoneError(phoneMsg);
        return;
      }
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      alert(msg ?? 'Failed to update lead.');
    },
  });

  // Quick status change (from ⋮ menu) — also accepts optional lost_reason
  const statusMutation = useMutation({
    mutationFn: ({ id, status, lost_reason, schedule_at, remark }: { id: number; status: string; lost_reason?: string; schedule_at?: string; remark?: string }) =>
      leadsApi.update(id, {
        status,
        ...(lost_reason !== undefined ? { lost_reason } : {}),
        ...(schedule_at ? { schedule_at } : {}),
        ...(remark ? { remark } : {}),
      }),
    onSuccess: (updated) => {
      qc.invalidateQueries({ queryKey: ['leads'] });
      qc.invalidateQueries({ queryKey: ['lead-department-counts'] });
      qc.invalidateQueries({ queryKey: ['lead-timeline', updated.id] });
      qc.invalidateQueries({ queryKey: ['activities'] });
      qc.invalidateQueries({ queryKey: ['lead-activities', updated.id] });
      qc.invalidateQueries({ queryKey: ['follow-ups'] });
      qc.invalidateQueries({ queryKey: ['meetings'] });
      qc.invalidateQueries({ queryKey: ['important'] });
      qc.invalidateQueries({ queryKey: ['calendar-events'] });
      qc.invalidateQueries({ queryKey: ['schedule-alerts'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      // Cascade to pipeline: deals linked to this lead may have changed status
      qc.invalidateQueries({ queryKey: ['deals'] });
      qc.invalidateQueries({ queryKey: ['pipelines'] });
      if (panelLead?.id === updated.id) setPanelLead(updated);
      setLostReasonLead(null);
      setScheduleStatusLead(null);
      setRemarkStatusLead(null);
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      alert(msg ?? 'Failed to update lead status.');
    },
  });

  // Optimistic delete
  const deleteMutation = useMutation({
    mutationFn: (id: number) => leadsApi.delete(id),
    onMutate: async (id) => {
      await qc.cancelQueries({ queryKey: ['leads'] });
      const prev = qc.getQueryData<PaginatedResponse<Lead>>(['leads', listFilters]);
      qc.setQueryData<PaginatedResponse<Lead>>(
        ['leads', listFilters],
        (old) => old ? { ...old, data: old.data.filter((l) => l.id !== id) } : old
      );
      if (panelLead?.id === id) setPanelLead(null);
      return { prev };
    },
    onError: (_err, _id, ctx) => {
      if (ctx?.prev) qc.setQueryData(['leads', listFilters], ctx.prev);
    },
    onSettled: () => {
      qc.invalidateQueries({ queryKey: ['leads'] });
      qc.invalidateQueries({ queryKey: ['lead-department-counts'] });
    },
  });

  // Convert to deal
  const convertMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: ConvertPayload }) => leadsApi.convertToDeal(id, payload),
    onSuccess: (result) => {
      qc.invalidateQueries({ queryKey: ['leads'] });
      qc.invalidateQueries({ queryKey: ['lead-department-counts'] });
      qc.invalidateQueries({ queryKey: ['deals'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      setConvertLead(null);
      if (convertLead) qc.invalidateQueries({ queryKey: ['lead-timeline', convertLead.id] });
      if (result.deal_marked_won) {
        alert(result.message);
      }
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      alert(msg ?? 'Failed to convert lead.');
    },
  });

  const handleSave = (payload: LeadPayload) => {
    setLeadPhoneError(null);
    const data: LeadPayload = {
      ...payload,
      assigned_to: payload.assigned_to ?? null,
    };
    if (modalLead?.id) {
      const { status: _status, ...editPayload } = data;
      updateMutation.mutate({ id: modalLead.id, payload: editPayload });
    } else {
      createMutation.mutate(data);
    }
  };

  const isSaving = createMutation.isPending || updateMutation.isPending;

  const leads = data?.data ?? [];
  const meta  = data?.meta;
  const selectedDepartment = departmentCounts?.departments.find(
    (d) => String(d.id) === departmentFilter,
  );

  // ── Plan usage limits (free: 100, business: 5,000, enterprise: unlimited) ───
  const activePlan  = liveSub?.plan ?? user?.subscription?.plan;
  const planLimits  = getLimits(activePlan);
  const totalLeads  = meta?.total ?? 0;
  const atLeadLimit = planLimits.leads !== Infinity && totalLeads >= planLimits.leads;

  return (
    <>
      <div className="space-y-5">
        {/* Header */}
        <div className="flex items-center justify-between gap-4 flex-wrap">
          <div>
            <h1 className="text-xl font-bold text-gray-900">Leads{meta ? ` (${meta.total})` : ''}</h1>
            <p className="text-xs text-gray-400 mt-0.5">
              {!isManager
                ? 'Showing leads assigned to you'
                : viewMine
                  ? 'Showing leads assigned to you'
                  : selectedDepartment
                    ? `Showing leads in ${selectedDepartment.name}`
                    : 'Showing all leads in your organization'}
            </p>
            {planLimits.leads !== Infinity && meta && (
              <div className="mt-2">
                <PlanLimitBar used={totalLeads} limit={planLimits.leads} label="Leads" />
              </div>
            )}
          </div>
          <div className="flex items-center gap-2">
            {/* My Leads / All Leads toggle — managers only */}
            {isManager && (
              <div className="flex items-center gap-1 bg-gray-100 rounded-xl p-1">
                <button
                  onClick={() => updateParams({ view: 'mine', member: null, page: null })}
                  className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all ${
                    viewMine ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'
                  }`}
                >
                  My Leads
                </button>
                <button
                  onClick={() => updateParams({ view: 'all', member: null, page: null })}
                  className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all ${
                    !viewMine ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'
                  }`}
                >
                  All Leads
                </button>
              </div>
            )}
            {/* Add Lead — managers and employees */}
            {canCreateLead && (
              <button
                onClick={() => !atLeadLimit && setModalLead(null)}
                disabled={atLeadLimit}
                title={atLeadLimit ? `You've reached the ${planLimits.leads.toLocaleString()}-lead limit on the ${activePlan === 'business' ? 'Business' : 'Free'} plan.` : undefined}
                className={`flex items-center gap-1.5 text-white text-sm font-semibold
                           px-4 py-2.5 rounded-xl transition-colors shadow-sm
                           ${atLeadLimit
                             ? 'bg-gray-300 cursor-not-allowed shadow-none'
                             : 'bg-indigo-600 hover:bg-indigo-700 shadow-indigo-500/30'}`}
              >
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4"/></svg>
                Add Lead
              </button>
            )}
          </div>
        </div>

        {/* Department filter cards — Owner only */}
        {showDepartmentFilters && (
          <div className="space-y-2">
            <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide">Departments</p>
            <div className="flex gap-3 overflow-x-auto pb-1 -mx-1 px-1 scrollbar-thin">
              <button
                type="button"
                onClick={() => updateParams({ department: null, page: null })}
                className={[
                  'shrink-0 min-w-[140px] rounded-2xl border px-4 py-3 text-left transition-all',
                  !departmentFilter
                    ? 'border-indigo-500 bg-indigo-50 shadow-sm shadow-indigo-500/10'
                    : 'border-gray-100 bg-white hover:border-indigo-200 hover:bg-indigo-50/40',
                ].join(' ')}
              >
                <p className={`text-xs font-semibold ${!departmentFilter ? 'text-indigo-700' : 'text-gray-500'}`}>
                  All Departments
                </p>
                <p className={`text-xl font-bold mt-0.5 ${!departmentFilter ? 'text-indigo-700' : 'text-gray-900'}`}>
                  {loadingDeptCounts ? '…' : (departmentCounts?.total ?? 0).toLocaleString()}
                </p>
              </button>

              {(departmentCounts?.departments ?? []).map((dept) => {
                const active = departmentFilter === String(dept.id);
                return (
                  <button
                    key={dept.id}
                    type="button"
                    onClick={() => updateParams({ department: String(dept.id), page: null })}
                    className={[
                      'shrink-0 min-w-[140px] rounded-2xl border px-4 py-3 text-left transition-all',
                      active
                        ? 'border-indigo-500 bg-indigo-50 shadow-sm shadow-indigo-500/10'
                        : 'border-gray-100 bg-white hover:border-indigo-200 hover:bg-indigo-50/40',
                    ].join(' ')}
                  >
                    <p className={`text-xs font-semibold truncate max-w-[160px] ${active ? 'text-indigo-700' : 'text-gray-500'}`}>
                      {dept.name}
                    </p>
                    <p className={`text-xl font-bold mt-0.5 ${active ? 'text-indigo-700' : 'text-gray-900'}`}>
                      {dept.leads_count.toLocaleString()}
                    </p>
                  </button>
                );
              })}
            </div>
          </div>
        )}

        {/* Filters */}
        <div className="flex gap-3 flex-wrap items-center">
          <div className="relative">
            <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input type="search" placeholder="Search leads…" value={search}
              onChange={(e) => updateParams({ search: e.target.value || null, page: null })}
              className="border border-gray-200 rounded-xl pl-9 pr-4 py-2.5 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-shadow bg-white" />
          </div>
          <select
            value={statusFilter}
            onChange={(e) => updateParams({ status: e.target.value || null, page: null })}
            className="border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-gray-600"
          >
            <option value="">All statuses</option>
            {LEAD_STATUSES.map((s) => (
              <option key={s} value={s}>{LEAD_STATUS_LABELS[s]}</option>
            ))}
          </select>
          <select
            value={typeFilter}
            onChange={(e) => updateParams({ types: e.target.value || null, page: null })}
            className="border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-gray-600"
          >
            <option value="">All types</option>
            {LEAD_TYPES.map((t) => (
              <option key={t} value={t}>{LEAD_TYPE_LABELS[t]}</option>
            ))}
          </select>
          <select value={clientTypeFilter} onChange={(e) => updateParams({ client_type: e.target.value || null, page: null })} className="border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white text-gray-600">
            <option value="">All clients</option><option value="NEW">New client</option><option value="EXISTING">Existing client</option>
          </select>
          <select value={businessTypeFilter} onChange={(e) => updateParams({ business_type: e.target.value || null, page: null })} className="border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white text-gray-600">
            <option value="">All business</option><option value="ONE_TIME">One time</option><option value="RECURRING">Recurring</option>
          </select>
          <select value={marketTypeFilter} onChange={(e) => updateParams({ market_type: e.target.value || null, page: null })} className="border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white text-gray-600">
            <option value="">All markets</option><option value="DOMESTIC">Domestic</option><option value="INTERNATIONAL">International</option>
          </select>
          {isManager && !viewMine && (
            <select
              value={teamMemberFilter}
              onChange={(e) => updateParams({ member: e.target.value || null, page: null })}
              className="border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-gray-600"
            >
              <option value="">All team members</option>
              <option value="unassigned">Unassigned</option>
              {(teamMembers ?? []).map((emp) => (
                <option key={emp.id} value={String(emp.id)}>{emp.name}</option>
              ))}
            </select>
          )}
          <div className="flex items-center gap-3 shrink-0">
            <input
              type="date"
              value={dateFromFilter}
              onChange={(e) => updateParams({ date_from: e.target.value || null, page: null })}
              className="border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-gray-600"
              title="From date"
            />
            <input
              type="date"
              value={dateToFilter}
              onChange={(e) => updateParams({ date_to: e.target.value || null, page: null })}
              className="border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-gray-600"
              title="To date"
            />
            {hasActiveFilters && (
              <button
                type="button"
                onClick={clearAllFilters}
                className="flex items-center gap-1.5 px-3 py-2.5 text-sm font-medium text-gray-600
                           border border-gray-200 rounded-xl bg-white hover:bg-gray-50 hover:text-gray-900
                           transition-colors whitespace-nowrap"
              >
                <svg className="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12"/>
                </svg>
                Clear filters
              </button>
            )}
          </div>
          {isFetching && !isLoading && (
            <span className="text-xs text-gray-400 flex items-center gap-1.5">
              <svg className="w-3.5 h-3.5 animate-spin text-indigo-400" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
              Updating…
            </span>
          )}
        </div>

        {/* Table */}
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm">
          {isLoading ? (
            <SkeletonTable rows={6} cols={7} />
          ) : leads.length === 0 && !hasActiveFilters ? (
            <EmptyLeads onAdd={() => setModalLead(null)} canAdd={canCreateLead} />
          ) : (
            <div className="overflow-x-auto rounded-2xl">
          <table className="min-w-[1100px] w-full">
                <thead>
                  <tr className="bg-gray-50/80 border-b border-gray-100">
                    {['Name', 'Requirement', 'Company', 'Contact', 'Status', 'Date', 'Actions'].map((h, hi, arr) => (
                      <th key={h} className={`px-5 py-3.5 text-left text-[11px] font-semibold text-gray-500 uppercase tracking-wider ${hi === 0 ? 'rounded-tl-2xl' : ''} ${hi === arr.length - 1 ? 'rounded-tr-2xl' : ''}`}>
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  <AnimatePresence>
                    {leads.map((lead, i) => (
                      <motion.tr
                        key={lead.id}
                        initial={{ opacity: 0, y: 4 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0, x: -20 }}
                        transition={{ delay: i * 0.03, duration: 0.2 }}
                        className="hover:bg-indigo-50/20 transition-colors"
                      >
                        {/* Name */}
                        <td className="px-5 py-3.5">
                          <Link
                            href={`/leads/${lead.id}`}
                            onClick={() => {
                              const qs = searchParams.toString();
                              sessionStorage.setItem('leads-return-url', qs ? `/leads?${qs}` : '/leads');
                            }}
                            className="flex items-center gap-3 text-left group/name"
                          >
                            <div className={`w-8 h-8 rounded-full bg-gradient-to-br ${AVATAR_GRADIENTS[i % AVATAR_GRADIENTS.length]} flex items-center justify-center shrink-0 shadow-sm`}>
                              <span className="text-white font-bold text-xs">{lead.full_name[0]?.toUpperCase()}</span>
                            </div>
                            <div>
                              <p className="text-sm font-semibold text-gray-900 group-hover/name:text-indigo-600 transition-colors">{lead.full_name}</p>
                              {lead.job_title && <p className="text-xs text-gray-400">{lead.job_title}</p>}
                            </div>
                          </Link>
                        </td>

                        {/* Requirement */}
                        <td className="px-5 py-3.5 text-sm text-gray-600">
                          <p>{lead.types ? LEAD_TYPE_LABELS[lead.types] : '—'}</p>
                          <div className="flex gap-1 mt-1">
                            {lead.business_type && <span className="text-[10px] px-1.5 py-0.5 rounded bg-violet-50 text-violet-700">{lead.business_type === 'RECURRING' ? 'Recurring' : 'One time'}</span>}
                            {lead.market_type && <span className="text-[10px] px-1.5 py-0.5 rounded bg-sky-50 text-sky-700 capitalize">{lead.market_type.toLowerCase()}</span>}
                          </div>
                        </td>

                        {/* Company */}
                        <td className="px-5 py-3.5 text-sm text-gray-600">{lead.company ?? '—'}</td>

                        {/* Contact */}
                        <td className="px-5 py-3.5 text-sm text-gray-600">{lead.phone ?? '—'}</td>

                        {/* Status */}
                        <td className="px-5 py-3.5"><Badge value={lead.status} /></td>

                        {/* Date */}
                        <td className="px-5 py-3.5 text-sm text-gray-600 whitespace-nowrap">
                          {formatLeadTableDate(lead)}
                        </td>

                        {/* Actions */}
                        <td className="px-5 py-3.5">
                          <div className="flex items-center gap-1">
                            <ActionsMenu
                              lead={lead}
                              canEdit={canEditLead(lead)}
                              canDelete={isManager}
                              onEdit={() => setModalLead(lead)}
                              onDelete={() => { if (confirm('Delete this lead?')) deleteMutation.mutate(lead.id); }}
                              onView={() => setPanelLead(lead)}
                              onConvert={() => setConvertLead(lead)}
                              onReminder={() => setReminderLead(lead)}
                              onWhatsApp={() => setWaLead(lead)}
                              onProposal={() => {
                                if (!isPaidPlan()) { router.push('/plans'); return; }
                                router.push(`/leads/${lead.id}/proposals/new`);
                              }}
                              onChangeStatus={(s) => {
                                if (s === 'converted') { setConvertLead(lead); }
                                else if (s === 'lost') { setLostReasonLead(lead); }
                                else if (isScheduledLeadStatus(s)) { setScheduleStatusLead({ lead, status: s }); }
                                else if (isRemarkLeadStatus(s)) { setRemarkStatusLead({ lead, status: s }); }
                                else statusMutation.mutate({ id: lead.id, status: s });
                              }}
                            />
                          </div>
                        </td>
                      </motion.tr>
                    ))}
                  </AnimatePresence>
                  {leads.length === 0 && hasActiveFilters && (
                    <tr>
                      <td colSpan={7} className="px-5 py-10 text-center text-gray-400 text-sm">
                        No leads match your filters.
                        <button onClick={clearAllFilters} className="ml-2 text-indigo-600 hover:underline">Clear filters</button>
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          )}

          {/* Pagination */}
          {meta && meta.last_page > 1 && (
            <div className="px-5 py-4 border-t border-gray-100 flex items-center justify-between">
              <span className="text-xs text-gray-400">Page {meta.current_page} of {meta.last_page} · {meta.total} leads</span>
              <div className="flex gap-2">
                <button onClick={() => updateParams({ page: String(Math.max(1, page - 1)) })} disabled={page === 1}
                  className="px-3 py-1.5 border border-gray-200 rounded-lg text-xs font-medium disabled:opacity-40 hover:bg-gray-50 transition-colors">← Prev</button>
                <button onClick={() => updateParams({ page: String(Math.min(meta.last_page, page + 1)) })} disabled={page === meta.last_page}
                  className="px-3 py-1.5 border border-gray-200 rounded-lg text-xs font-medium disabled:opacity-40 hover:bg-gray-50 transition-colors">Next →</button>
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Edit / Create modal */}
      <Modal
        open={modalLead !== undefined}
        onClose={() => {
          setLeadPhoneError(null);
          setModalLead(undefined);
        }}
        title={modalLead ? 'Edit Lead' : 'Add Lead'}
        maxWidth="max-w-6xl"
      >
        <LeadForm
          lead={modalLead}
          presetClientId={modalLead ? undefined : presetClientId}
          onSave={handleSave}
          onClose={() => {
            setLeadPhoneError(null);
            setModalLead(undefined);
          }}
          saving={isSaving}
          phoneError={leadPhoneError}
          onPhoneChange={() => setLeadPhoneError(null)}
        />
      </Modal>

      {/* Convert to deal modal */}
      <Modal
        open={convertLead !== null}
        onClose={() => setConvertLead(null)}
        title="Convert Lead to Deal"
        maxWidth="max-w-md"
      >
        {convertLead && (
          <ConvertToDealFormFromLead
            lead={convertLead}
            pipelines={pipelines}
            onSave={(p) => convertMutation.mutate({ id: convertLead.id, payload: p })}
            onClose={() => setConvertLead(null)}
            saving={convertMutation.isPending}
          />
        )}
      </Modal>

      {/* Reminder modal */}
      {reminderLead && (
        <ReminderModal
          lead={reminderLead}
          currentUserId={user!.id}
          onClose={() => setReminderLead(null)}
          onCreated={() => {
            qc.invalidateQueries({ queryKey: ['activities'] });
            qc.invalidateQueries({ queryKey: ['lead-activities', reminderLead.id] });
            setReminderLead(null);
          }}
        />
      )}

      {/* Lost reason modal */}
      {lostReasonLead && (
        <LostReasonModal
          lead={lostReasonLead}
          onClose={() => setLostReasonLead(null)}
          onConfirm={(reason) => statusMutation.mutate({ id: lostReasonLead.id, status: 'lost', lost_reason: reason })}
          saving={statusMutation.isPending}
        />
      )}

      {/* Follow-up / Meeting schedule modal */}
      {scheduleStatusLead && (
        <ScheduleStatusModal
          lead={scheduleStatusLead.lead}
          status={scheduleStatusLead.status}
          onClose={() => setScheduleStatusLead(null)}
          onConfirm={({ scheduleAt, remark }) =>
            statusMutation.mutate({
              id: scheduleStatusLead.lead.id,
              status: scheduleStatusLead.status,
              schedule_at: scheduleAt,
              remark,
            })
          }
          saving={statusMutation.isPending}
        />
      )}

      {/* Ringing / Important remark modal */}
      {remarkStatusLead && (
        <RemarkStatusModal
          lead={remarkStatusLead.lead}
          status={remarkStatusLead.status}
          onClose={() => setRemarkStatusLead(null)}
          onConfirm={(remark) =>
            statusMutation.mutate({
              id: remarkStatusLead.lead.id,
              status: remarkStatusLead.status,
              remark: remark || undefined,
            })
          }
          saving={statusMutation.isPending}
        />
      )}

      {/* WhatsApp template modal */}
      {waLead && (
        <WhatsAppModal
          lead={waLead}
          userId={user!.id}
          onClose={() => setWaLead(null)}
          onSent={() => {
            qc.invalidateQueries({ queryKey: ['lead-activities', waLead.id] });
            qc.invalidateQueries({ queryKey: ['activities'] });
            setWaLead(null);
          }}
        />
      )}

      {/* Lead detail side panel + backdrop */}
      <AnimatePresence>
        {panelLead && (
          <>
            <motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              exit={{ opacity: 0 }}
              className="fixed inset-0 bg-black/20 z-40"
              onClick={() => setPanelLead(null)}
            />
            <LeadPanel
              lead={panelLead}
              onClose={() => setPanelLead(null)}
              onEdit={() => { setModalLead(panelLead); setPanelLead(null); }}
            />
          </>
        )}
      </AnimatePresence>
    </>
  );
}
