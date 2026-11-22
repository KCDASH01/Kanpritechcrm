'use client';

import { useState } from 'react';
import type { ImportDetails, ImportSetup } from '@/lib/api/leadImports';
import { LEAD_STATUS_LABELS, LEAD_STATUS_MENU } from '@/lib/leadStatuses';

export const inputClass = 'min-h-10 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100';
export const buttonClass = 'inline-flex min-h-10 items-center justify-center rounded-xl px-4 py-2 text-sm font-semibold disabled:opacity-50';
export const primary = `${buttonClass} bg-indigo-600 text-white hover:bg-indigo-700`;
export const secondary = `${buttonClass} border border-gray-200 text-gray-700 hover:bg-gray-50`;

const basicDefaults = [
  ['market_type', 'Default market type', { DOMESTIC: 'Domestic', INTERNATIONAL: 'International' }],
  ['client_type', 'Client type', { NEW: 'New client', EXISTING: 'Existing client' }],
  ['business_type', 'Business type', { ONE_TIME: 'One time', RECURRING: 'Recurring' }],
  ['status', 'Lead status', Object.fromEntries(LEAD_STATUS_MENU.filter((s) => !['meeting', 'followup'].includes(s)).map((s) => [s, LEAD_STATUS_LABELS[s]]))],
  ['currency', 'Currency', { INR: 'INR', USD: 'USD' }],
  ['source', 'Source', { csv: 'Spreadsheet Import', manual: 'Manual', web_form: 'Web form', api: 'API', other: 'Other' }],
] as const;

function options(choices: Record<string, string>) {
  return Object.entries(choices).map(([value, label]) => <option key={value} value={value}>{label}</option>);
}

export function ImportMapping({ details, setup, change }: {
  details: ImportDetails;
  setup: ImportSetup;
  change: (next: ImportSetup) => void;
}) {
  const [alias, setAlias] = useState('');
  const [service, setService] = useState('');
  const sheet = details.sheets[setup.sheet];
  const defaultValue = (field: string, value: string) => change({ ...setup, defaults: { ...setup.defaults, [field]: value || null } });
  const updateColumn = (index: number, field: string) => {
    const columns = { ...setup.columns };
    if (field) columns[index] = field;
    else delete columns[index];
    change({ ...setup, columns });
  };

  return <div className="space-y-5">
    <label className="block max-w-sm text-sm font-medium">Worksheet
      <select className={inputClass} value={setup.sheet} onChange={(event) => {
        const sheetIndex = Number(event.target.value);
        change({ ...setup, sheet: sheetIndex, columns: details.sheets[sheetIndex].map ?? {} });
      }}>
        {details.sheets.map((item, index) => <option key={item.name} value={index} disabled={!!item.error}>{item.name}{item.error ? ' · unavailable' : ''}</option>)}
      </select>
    </label>
    {details.sheets.some((item) => item.error) && <ul className="text-sm text-amber-800">{details.sheets.filter((item) => item.error).map((item) => <li key={item.name}>{item.name}: {item.error}</li>)}</ul>}
    {!!sheet?.conflicts?.length && <p className="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">Some spreadsheet headers match the same field. Choose one source column for each destination.</p>}
    <div className="divide-y rounded-2xl border border-gray-200">
      <div className="hidden grid-cols-[1fr_1fr_1fr] gap-3 bg-gray-50 px-4 py-3 text-sm font-semibold sm:grid"><span>Spreadsheet column</span><span>Sample value</span><span>CRM field</span></div>
      {sheet?.headers?.map((header, index) => <label key={index} className="grid gap-2 px-4 py-2 text-sm sm:grid-cols-[1fr_1fr_1fr] sm:items-center">
        <span className="break-words font-medium">{header || `Column ${index + 1}`}</span>
        <span className="break-all text-gray-500">{sheet.sample?.[index] || '—'}</span>
        <select className={inputClass} value={setup.columns[index] ?? ''} onChange={(event) => updateColumn(index, event.target.value)} aria-label={`CRM field for ${header || 'column '+(index+1)}`}>
          <option value="">Ignore Column</option>
          {Object.entries(details.fields).map(([key, label]) => (
            <option key={key} value={key} disabled={setup.columns[index] !== key && Object.values(setup.columns).includes(key)}>{label}</option>
          ))}
        </select>
      </label>)}
    </div>
    <div className="rounded-2xl border p-4">
      <h2 className="mb-3 font-semibold">Defaults for blank fields</h2>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <label className="text-sm">Default service or product<select className={inputClass} value={setup.defaults.service ?? ''} onChange={(event) => defaultValue('service', event.target.value)}>
          <option value="">Choose if needed</option>{options(details.services)}
        </select></label>
        {basicDefaults.map(([field, title, choices]) => <label key={field} className="text-sm">{title}
          <select className={inputClass} value={setup.defaults[field] ?? ''} onChange={(event) => defaultValue(field, event.target.value)}>
            <option value="">{field === 'market_type' ? 'Derive from country' : 'Use CRM default'}</option>{options(choices)}
          </select>
        </label>)}
        <label className="text-sm">Slash date order<select className={inputClass} value={setup.date_format} onChange={(event) => change({ ...setup, date_format: event.target.value as ImportSetup['date_format'] })}>
          <option value="d/m">Day / month / year</option><option value="m/d">Month / day / year</option>
        </select></label>
        {details.format === 'csv' && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={setup.fill_dates} onChange={(event) => change({ ...setup, fill_dates: event.target.checked })}/> Fill down blank Lead Dates within a section</label>}
      </div>
      <details className="mt-4">
        <summary className="cursor-pointer text-sm font-semibold text-indigo-700">Recurring lead defaults</summary>
        <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <label className="text-sm">Frequency<select className={inputClass} value={setup.defaults.recurring_frequency ?? ''} onChange={(e) => defaultValue('recurring_frequency', e.target.value)}><option value="">Choose frequency</option>{options({ MONTHLY: 'Monthly', QUARTERLY: 'Quarterly', HALF_YEARLY: 'Half yearly', YEARLY: 'Yearly' })}</select></label>
          <label className="text-sm">Recurring amount<input className={inputClass} type="number" min="0.01" step="0.01" value={setup.defaults.recurring_amount ?? ''} onChange={(e) => defaultValue('recurring_amount', e.target.value)} /></label>
          <label className="text-sm">Start date<input className={inputClass} type="date" value={setup.defaults.recurring_start_date ?? ''} onChange={(e) => defaultValue('recurring_start_date', e.target.value)} /></label>
          <label className="text-sm">End type<select className={inputClass} value={setup.defaults.recurring_end_type ?? ''} onChange={(e) => defaultValue('recurring_end_type', e.target.value)}><option value="">Choose end type</option>{options({ ONGOING: 'Ongoing', FIXED: 'Fixed date' })}</select></label>
          <label className="text-sm">End date<input className={inputClass} type="date" value={setup.defaults.recurring_end_date ?? ''} onChange={(e) => defaultValue('recurring_end_date', e.target.value)} /></label>
        </div>
      </details>
    </div>
    <div className="rounded-2xl border p-4">
      <h2 className="font-semibold">Service name mappings</h2>
      <p className="mb-3 text-sm text-gray-600">Map spreadsheet descriptions to existing catalogue choices.</p>
      <div className="grid gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
        <label className="text-sm">Spreadsheet description<input className={inputClass} value={alias} maxLength={150} onChange={(event) => setAlias(event.target.value)} /></label>
        <label className="text-sm">CRM service<select className={inputClass} value={service} onChange={(event) => setService(event.target.value)}><option value="">Choose a service</option>{options(details.services)}</select></label>
        <button type="button" className={secondary} disabled={!alias.trim() || !service} onClick={() => { change({ ...setup, service_aliases: { ...setup.service_aliases, [alias.trim()]: service } }); setAlias(''); setService(''); }}>Add mapping</button>
      </div>
      {Object.entries(setup.service_aliases).map(([label, value]) => <div key={label} className="mt-2 flex flex-wrap items-center justify-between gap-2 text-sm"><span>{label} → {details.services[value] ?? value}</span><button type="button" className="text-red-700" onClick={() => change({ ...setup, service_aliases: Object.fromEntries(Object.entries(setup.service_aliases).filter(([name]) => name !== label)) })}>Remove {label}</button></div>)}
    </div>
  </div>;
}
