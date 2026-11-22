'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useAuthStore } from '@/store/authStore';
import { subscriptionApi } from '@/lib/api/subscription';
import { leadImportsApi, type ImportDetails, type ImportSetup, type ImportSummary } from '@/lib/api/leadImports';
import { ImportMapping, inputClass, primary, secondary } from './ImportMapping';

const steps = ['Upload File', 'Map Columns', 'Validate & Preview', 'Confirm Import', 'Import Results'];
const busy = ['validating', 'queued', 'processing'];
const statusLabel: Record<string, string> = {
  uploaded: 'Uploaded', validating: 'Validating', ready: 'Ready', queued: 'Queued',
  processing: 'Processing', completed: 'Completed', completed_errors: 'Completed with errors', failed: 'Failed',
};
const size = (bytes: number) => bytes >= 1048576 ? (bytes / 1048576).toFixed(1)+' MB' : Math.max(1, Math.round(bytes / 1024))+' KB';
const time = (value: string) => new Date(value).toLocaleString();
const duration = (job: ImportSummary) => job.started_at && job.completed_at
  ? Math.max(0, Math.round((Date.parse(job.completed_at)-Date.parse(job.started_at))/1000))+' sec' : '—';

function initialSetup(job: ImportDetails): ImportSetup {
  if (job.mapping) return { ...job.mapping, service_aliases: { ...job.mapping.service_aliases }, defaults: { ...job.mapping.defaults } };
  const sheet = Math.max(0, job.sheets.findIndex((entry) => !entry.error));
  return { sheet, columns: job.sheets[sheet]?.map ?? {}, defaults: job.defaults, service_aliases: {}, date_format: 'd/m', fill_dates: false };
}

function errorText(error: unknown) {
  const data = (error as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }).response?.data;
  return data?.errors ? Object.values(data.errors).flat().join(' ') : data?.message ?? 'The action could not finish. Try again.';
}

function Pager({ page, last, change }: { page: number; last: number; change: (page: number) => void }) {
  if (last <= 1) return null;

  return <nav aria-label="Pagination" className="flex flex-wrap items-center justify-between gap-2 text-sm">
    <button type="button" className={secondary} disabled={page <= 1} onClick={() => change(page - 1)}>Previous</button>
    <span>Page {page} of {last}</span>
    <button type="button" className={secondary} disabled={page >= last} onClick={() => change(page + 1)}>Next</button>
  </nav>;
}

function ValidatedConfiguration({ job }: { job: ImportDetails }) {
  const mapping = job.mapping;
  if (!mapping) return null;

  return <details className="rounded-xl border p-3 text-sm">
    <summary className="cursor-pointer font-semibold">Validated mapping and defaults</summary>
    <ul className="mt-2 space-y-1">
      {Object.entries(mapping.columns).map(([index, field]) => <li key={index}>{job.sheets[mapping.sheet]?.headers?.[Number(index)] ?? index} → {job.fields[field]}</li>)}
    </ul>
    <ul className="mt-3 space-y-1 border-t pt-2">
      {Object.entries(mapping.defaults).map(([field, value]) => value && <li key={field}>{job.fields[field] ?? field} · {job.services[value] ?? value}</li>)}
      <li>Slash date order · {mapping.date_format === 'd/m' ? 'Day / month / year' : 'Month / day / year'}</li>
      {mapping.date_fallback && <li>Default lead date · {mapping.date_fallback}</li>}
    </ul>
  </details>;
}

export default function LeadImportWorkspace() {
  const user = useAuthStore((state) => state.user);
  const hasRole = useAuthStore((state) => state.isAdmin() || state.isEmployee());
  const queryClient = useQueryClient();
  const viewer = [user?.organization?.id, user?.id];
  const subscription = useQuery({ queryKey: ['subscription'], queryFn: subscriptionApi.get, staleTime: 300000 });
  const activeSub = subscription.data ?? user?.subscription;
  const allowed = hasRole && user?.is_active !== false && ['business', 'enterprise'].includes(activeSub?.plan ?? '') && activeSub?.is_active !== false && !activeSub?.is_expired && !activeSub?.is_read_only;
  const [jobId, setJobId] = useState<number | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [setup, setSetup] = useState<ImportSetup | null>(null);
  const [history, setHistory] = useState(false);
  const [historyPage, setHistoryPage] = useState(1);
  const [position, setPosition] = useState<number | null>(null);
  const [rowPage, setRowPage] = useState(1);
  const [filter, setFilter] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const [error, setError] = useState('');
  const [dragging, setDragging] = useState(false);

  const details = useQuery({
    queryKey: ['lead-import', ...viewer, jobId], queryFn: () => leadImportsApi.show(jobId!),
    enabled: allowed && jobId !== null,
    refetchInterval: (query) => {
      const status = query.state.data?.status;
      return !status || busy.includes(status) ? 2500 : false;
    },
  });
  const job = details.data;
  const computed = !job ? 0 : job.status === 'uploaded' ? 1
    : ['validating', 'ready'].includes(job.status) ? 2 : 4;
  const step = position ?? computed;
  const preview = useQuery({
    queryKey: ['lead-import-preview', ...viewer, jobId, job?.status, job?.created_rows, job?.failed_rows, job?.duplicate_rows, rowPage, filter],
    queryFn: () => leadImportsApi.preview(jobId!, rowPage, filter),
    enabled: allowed && !history && (step === 2 || step === 4) && !!job && !['uploaded', 'validating', 'queued'].includes(job.status),
    retry: false,
  });
  const list = useQuery({
    queryKey: ['lead-import-history', ...viewer, historyPage], queryFn: () => leadImportsApi.list(historyPage), enabled: allowed && history,
    refetchInterval: (query) => query.state.data?.data.some((item) => busy.includes(item.status)) ? 5000 : false,
  });

  useEffect(() => {
    if (job && ['completed', 'completed_errors', 'failed'].includes(job.status) && job.created_rows > 0) {
      void queryClient.invalidateQueries({ queryKey: ['leads'] });
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    }
  }, [job, queryClient]);

  function open(details: ImportDetails) {
    setJobId(details.id);
    setSetup(initialSetup(details));
    setHistory(false);
    setPosition(null);
    setFilter('');
    setRowPage(1);
    setConfirmed(false);
    setError('');
    queryClient.setQueryData(['lead-import', ...viewer, details.id], details);
  }

  const upload = useMutation({ mutationFn: leadImportsApi.upload, onSuccess: open, onError: (e) => setError(errorText(e)) });
  const historyItem = useMutation({ mutationFn: leadImportsApi.show, onSuccess: open, onError: (e) => setError(errorText(e)) });
  const validate = useMutation({ mutationFn: (mapping: ImportSetup) => leadImportsApi.validate(jobId!, mapping), onSuccess: async () => {
    setRowPage(1);
    setFilter('');
    setPosition(null);
    await queryClient.invalidateQueries({ queryKey: ['lead-import-preview', ...viewer, jobId] });
    await queryClient.invalidateQueries({ queryKey: ['lead-import', ...viewer, jobId] });
  }, onError: (e) => setError(errorText(e)) });
  const confirm = useMutation({ mutationFn: (revision: string) => leadImportsApi.confirm(jobId!, revision), onSuccess: async () => {
    setPosition(null);
    await queryClient.invalidateQueries({ queryKey: ['lead-import', ...viewer, jobId] });
  }, onError: (e) => setError(errorText(e)) });

  function choose(candidate?: File) {
    if (!candidate) return;
    setError('');
    if (!/.(csv|xlsx|xls)$/i.test(candidate.name) || candidate.size < 1) {
      setError('Choose a nonempty CSV, XLSX, or XLS spreadsheet.');
      return;
    }
    setFile(candidate);
  }

  function reset() {
    setFile(null); setJobId(null); setSetup(null); setPosition(null); setError(''); setConfirmed(false); setHistory(false);
  }

  async function download(load: () => Promise<Blob>, name: string) {
    try {
      const url = URL.createObjectURL(await load());
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = name;
      anchor.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
      setError(errorText(e));
    }
  }

  const uploading = upload.isPending || validate.isPending || confirm.isPending;
  const processed = job ? job.created_rows + job.failed_rows + job.invalid_rows + job.duplicate_rows : 0;
  const percent = job?.total_rows ? Math.min(100, Math.round(processed * 100 / job.total_rows)) : 0;

  return <main className="mx-auto max-w-7xl space-y-5 p-3 sm:p-6">
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div><Link href="/leads" className="text-sm font-semibold text-indigo-700">← Leads</Link>
        <h1 className="mt-1 text-2xl font-bold text-gray-900">Import Leads</h1></div>
      {allowed && <div className="flex flex-wrap gap-2">
        <button type="button" className={secondary} onClick={() => { setHistory(!history); setError(''); }}>{history ? 'Import wizard' : 'Import history'}</button>
        <button type="button" className={secondary} onClick={() => void download(leadImportsApi.template, 'growneq-lead-import.xlsx')}>Download Lead Import Template</button>
      </div>}
    </div>
    {!allowed && <p className="rounded-xl border bg-white p-4 text-sm text-gray-700">Lead import requires an active Business or Enterprise membership.</p>}
    {allowed && <>
      {(error || details.error || list.error) && <p role="alert" className="rounded-xl border border-red-100 bg-red-50 p-3 text-sm text-red-800">{error || errorText(details.error || list.error)}</p>}
      {history ? <section className="space-y-3 rounded-2xl border bg-white p-4">
        <h2 className="font-semibold">Import history</h2>
        {list.isLoading ? <p role="status">Loading imports…</p> : list.data?.data.length === 0 ? <p className="text-sm text-gray-600">No imports yet.</p> : <div className="overflow-x-auto">
          <table className="w-full min-w-[850px] text-left text-sm">
            <thead className="border-b bg-gray-50 text-gray-600"><tr>{['Import', 'Uploader', 'Uploaded', 'Rows', 'Created', 'Duplicates', 'Invalid', 'Failed', 'Status', 'Duration', 'Actions'].map((label) => <th className="p-2" key={label}>{label}</th>)}</tr></thead>
            <tbody>{list.data?.data.map((item) => <tr key={item.id} className="border-b">
              <td className="p-2"><strong>#{item.id}</strong> {item.filename}</td><td className="p-2">{item.uploader_name}</td><td className="p-2">{time(item.created_at)}</td>
              <td className="p-2">{item.total_rows}</td><td className="p-2">{item.created_rows}</td><td className="p-2">{item.duplicate_rows}</td><td className="p-2">{item.invalid_rows}</td><td className="p-2">{item.failed_rows}</td>
              <td className="p-2">{statusLabel[item.status]}</td><td className="p-2">{duration(item)}</td>
              <td className="space-y-2 p-2"><button type="button" className="block text-indigo-700" disabled={historyItem.isPending} onClick={() => historyItem.mutate(item.id)}>View details</button>
                {!['uploaded', 'validating'].includes(item.status) && <button type="button" className="block text-indigo-700" onClick={() => void download(() => leadImportsApi.errors(item.id), 'lead-import-'+item.id+'-report.csv')}>Download report</button>}</td>
            </tr>)}</tbody>
          </table>
        </div>}
        <Pager page={historyPage} last={list.data?.last_page ?? 1} change={setHistoryPage} />
      </section> : <>
        <ol aria-label="Import steps" className="grid gap-2 rounded-2xl border bg-white p-3 sm:grid-cols-5">
          {steps.map((label, index) => <li key={label} aria-current={step === index ? 'step' : undefined} className={`flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold ${step === index ? 'bg-indigo-50 text-indigo-800' : 'text-gray-600'}`}>
            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs">{index + 1}</span>{label}</li>)}
        </ol>
        <section className="space-y-4 rounded-2xl border bg-white p-4 sm:p-6">
          <h2 className="text-lg font-bold">{steps[step]}</h2>
          {job?.error && <p role="alert" className="rounded-xl bg-red-50 p-3 text-sm text-red-800">{job.error}</p>}
          {step === 0 && <>
            <div onDragOver={(event) => { event.preventDefault(); setDragging(true); }} onDragLeave={() => setDragging(false)}
              onDrop={(event) => { event.preventDefault(); setDragging(false); choose(event.dataTransfer.files[0]); }}
              className={`rounded-2xl border-2 border-dashed p-6 text-center ${dragging ? 'border-indigo-400 bg-indigo-50' : 'border-gray-200 bg-gray-50'}`}>
              <p className="font-medium text-gray-900">Drop a CSV, XLSX, or XLS spreadsheet here</p>
              <label className={`mt-3 cursor-pointer focus-within:ring-2 focus-within:ring-indigo-500 ${secondary}`}>Browse files
                <input className="sr-only" type="file" accept=".csv,.xlsx,.xls" onChange={(event) => { choose(event.target.files?.[0]); event.target.value = ''; }} /></label>
              {file && <p className="mt-3 break-all text-sm">{file.name} · {size(file.size)} <button type="button" className="ml-2 font-semibold text-red-700" onClick={() => setFile(null)}>Remove</button></p>}
            </div>
            <button type="button" className={primary} disabled={!file || uploading} onClick={() => { if (file) { setError(''); upload.mutate(file); } }}>{uploading ? 'Uploading…' : 'Upload and map columns'}</button>
            {job?.can_edit && <button type="button" className={secondary} onClick={() => setPosition(1)}>Back to current mapping</button>}
          </>}
          {step === 1 && job && setup && <>
            <p className="text-sm text-gray-600">{job.filename} · {size(job.file_size)} · Assigned to {job.uploader_name}</p>
            <ImportMapping details={job} setup={setup} change={setSetup} />
            <div className="flex flex-wrap gap-2"><button type="button" className={secondary} onClick={() => setPosition(0)}>Replace file</button>
              <button type="button" className={primary} disabled={uploading || !job.can_edit} onClick={() => { setError(''); validate.mutate(setup); }}>{uploading ? 'Submitting…' : 'Validate spreadsheet'}</button></div>
          </>}
          {job && (step === 2 || step === 4) && <>
            <p className="text-sm text-gray-600">{job.filename} · {statusLabel[job.status]} · Assigned to {job.uploader_name}</p>
            {job.status === 'validating' ? <p role="status">Validating the worksheet. You can return from Import history.</p> : <>
              <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
                {Object.entries({ Total: job.total_rows, Ready: Math.max(0, job.total_rows - processed), Created: job.created_rows, Invalid: job.invalid_rows,
                  Duplicates: job.duplicate_rows, Warnings: job.warning_rows, Failed: job.failed_rows }).map(([label, count]) => <div key={label} className="rounded-xl bg-gray-50 p-3">
                    <dt className="text-xs text-gray-600">{label}</dt><dd className="text-xl font-semibold">{count}</dd></div>)}
              </dl>
              {['queued', 'processing'].includes(job.status) && <div role="status" className="space-y-2"><p className="text-sm">{processed} of {job.total_rows} rows processed · {percent}%</p>
                <progress aria-label="Import progress" className="h-3 w-full accent-indigo-600" value={percent} max={100} /></div>}
              {!['uploaded', 'validating', 'queued'].includes(job.status) && <>
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <label className="text-sm">Preview filter<select className={inputClass} value={filter} onChange={(event) => { setFilter(event.target.value); setRowPage(1); }}>
                    <option value="">All rows</option><option value="ready">Valid</option><option value="invalid">Invalid</option><option value="duplicate">Duplicates</option><option value="warnings">Warnings</option><option value="created">Created</option><option value="failed">Failed</option>
                  </select></label>
                  <button type="button" className={secondary} onClick={() => void download(() => leadImportsApi.errors(job.id), 'lead-import-'+job.id+'-report.csv')}>Download {step === 2 ? 'validation' : 'error'} report</button>
                </div>
                {preview.error && <p role="alert" className="text-sm text-red-700">{errorText(preview.error)}</p>}
                {preview.isLoading ? <p role="status">Loading rows…</p> : <div className="overflow-x-auto rounded-xl border">
                  <table className="w-full min-w-[880px] text-left text-sm"><thead className="bg-gray-50"><tr>{['Row', 'Name', 'Email', 'Phone', 'Country', 'Service', 'Lead status', 'Result', 'Error or warning'].map((value) => <th key={value} className="p-2">{value}</th>)}</tr></thead>
                    <tbody>{preview.data?.data.map((row) => <tr key={row.source_row} className="border-t">
                      <td className="p-2">{row.source_row}</td><td className="p-2">{row.values.name || '—'}</td><td className="p-2">{row.values.email || '—'}</td><td className="p-2">{row.values.phone || '—'}</td>
                      <td className="p-2">{row.values.country || '—'}</td><td className="p-2">{row.values.service || row.values.types || '—'}</td><td className="p-2">{row.values.status || '—'}</td>
                      <td className="p-2 capitalize">{row.status}{row.warning ? ' · warning' : ''}</td><td className="p-2">{Object.values(row.issues ?? {}).join(' ') || '—'}</td>
                    </tr>)}
                    {preview.data?.data.length === 0 && <tr><td colSpan={9} className="p-4 text-center text-gray-600">No rows match this filter.</td></tr>}</tbody>
                  </table>
                </div>}
                <Pager page={rowPage} last={preview.data?.last_page ?? 1} change={setRowPage} />
              </>}
            </>}
            {step === 2 && job.status === 'ready' && <ValidatedConfiguration job={job} />}
            {step === 2 && job.status === 'ready' && <div className="flex flex-wrap gap-2">
              {job.can_edit && <button type="button" className={secondary} onClick={() => setPosition(1)}>Back to mapping</button>}
              {job.can_edit && <button type="button" className={primary} disabled={job.valid_rows === 0} onClick={() => { setConfirmed(false); setPosition(3); }}>Review confirmation</button>}
            </div>}
            {step === 4 && !busy.includes(job.status) && <div className="flex flex-wrap gap-2">
              {job.created_rows > 0 && <Link href={'/leads?import_id='+job.id} className={primary}>View Imported Leads</Link>}
              {job.status === 'failed' && job.can_edit && <button type="button" className={secondary} onClick={() => setPosition(1)}>Return to mapping</button>}
              <button type="button" className={secondary} onClick={reset}>Import another file</button>
            </div>}
          </>}
          {step === 3 && job && <>
            <p>You are about to import <strong>{job.valid_rows} new leads</strong>. All successful leads will be assigned to your account. Invalid rows and duplicates will be skipped.</p>
            <dl className="grid gap-3 text-sm sm:grid-cols-2">
              {Object.entries({ Organization: job.organization, Uploader: job.uploader_name, File: job.filename, 'Total rows': job.total_rows,
                Duplicates: job.duplicate_rows, Invalid: job.invalid_rows }).map(([label, value]) => <div key={label}><dt className="font-semibold">{label}</dt><dd>{value}</dd></div>)}
            </dl>
            <ValidatedConfiguration job={job} />
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} /> I confirm this import and assignment.</label>
            <div className="flex flex-wrap gap-2"><button type="button" className={secondary} disabled={uploading} onClick={() => setPosition(2)}>Back to preview</button>
              <button type="button" className={primary} disabled={!confirmed || uploading || !job.mapping?.revision || job.status !== 'ready'} onClick={() => {
                if (job.mapping?.revision) { setError(''); confirm.mutate(job.mapping.revision); }
              }}>{uploading ? 'Confirming…' : 'Confirm Import'}</button></div>
          </>}
        </section>
      </>}
    </>}
  </main>;
}
