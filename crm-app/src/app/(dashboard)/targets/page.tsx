'use client';

import { useMemo, useState } from 'react';
import Link from 'next/link';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { motion } from 'framer-motion';
import { salesTargetsApi } from '@/lib/api/salesTargets';
import { employeesApi } from '@/lib/api/employees';
import { departmentsApi } from '@/lib/api/departments';
import { useAuthStore } from '@/store/authStore';
import { AccessDenied } from '@/components/ui/AccessDenied';
import { Modal } from '@/components/ui/Modal';
import type { MyTargetProgress, SalesTargetRow, SalesTargetType, SalesTargetUpsertPayload } from '@/types';

function fmtAmount(value: number) {
  if (value >= 1_00_00_000) return `₹${(value / 1_00_00_000).toFixed(2)}Cr`;
  if (value >= 1_00_000) return `₹${(value / 1_00_000).toFixed(2)}L`;
  if (value >= 1_000) return `₹${(value / 1_000).toFixed(1)}K`;
  return `₹${value.toFixed(0)}`;
}

function percentageTone(value: number | null) {
  if (value === null || value < 50) return 'bg-red-50 text-red-700';
  if (value <= 75) return 'bg-orange-50 text-orange-700';
  if (value <= 100) return 'bg-emerald-50 text-emerald-700';
  return 'bg-emerald-100 text-emerald-900';
}

function barTone(value: number | null) {
  if (value === null || value < 50) return 'bg-red-500';
  if (value <= 75) return 'bg-orange-500';
  if (value <= 100) return 'bg-emerald-400';
  return 'bg-emerald-700';
}

function formatMonth(value: string) {
  const [year, month] = value.split('-').map(Number);
  return new Date(year, month - 1, 1).toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
}

function shiftMonth(value: string, amount: number) {
  const [year, month] = value.split('-').map(Number);
  const date = new Date(year, month - 1 + amount, 1);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

function displayDate(value: string) {
  return new Date(`${value}T00:00:00`).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
}

function dateRange(start: string, end: string) {
  return `${displayDate(start)} – ${displayDate(end)}`;
}

function daysInclusive(start: string, end: string) {
  const from = new Date(`${start}T00:00:00`).getTime();
  const to = new Date(`${end}T00:00:00`).getTime();
  return Math.floor((to - from) / 86_400_000) + 1;
}

function ProgressBar({ value }: { value: number | null }) {
  return (
    <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
      <div className={`h-full rounded-full ${barTone(value)}`} style={{ width: `${Math.min(100, Math.max(0, value ?? 0))}%` }} />
    </div>
  );
}

function StatusBadge({ status }: { status: SalesTargetRow['period_status'] }) {
  const tone = status === 'active'
    ? 'bg-emerald-50 text-emerald-700'
    : status === 'upcoming'
      ? 'bg-blue-50 text-blue-700'
      : 'bg-gray-100 text-gray-600';
  return <span className={`rounded-full px-2 py-1 text-[10px] font-bold uppercase ${tone}`}>{status}</span>;
}

function TargetDetailsModal({ targetId, onClose }: { targetId: number | null; onClose: () => void }) {
  const { data, isLoading } = useQuery({
    queryKey: ['sales-target-details', targetId],
    queryFn: () => salesTargetsApi.details(targetId!),
    enabled: targetId !== null,
  });

  return (
    <Modal open={targetId !== null} onClose={onClose} title="Target Performance Details" maxWidth="max-w-5xl">
      {isLoading || !data ? (
        <div className="py-16 text-center text-sm text-gray-400">Loading target details…</div>
      ) : (
        <div className="space-y-6">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 className="text-lg font-bold text-gray-900">{data.user?.name}</h3>
              <p className="text-sm text-gray-500">{data.department || 'No department assigned'}</p>
              <p className="mt-1 text-xs font-medium text-indigo-600">{data.period_label}</p>
            </div>
            <div className="flex items-center gap-2">
              <span className="rounded-full bg-violet-50 px-2.5 py-1 text-xs font-semibold capitalize text-violet-700">{data.target_type.replace('_', ' ')}</span>
              <StatusBadge status={data.period_status} />
            </div>
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {[
              ['Sales Progress', data.achieved_amount, data.target_amount, data.sales_percentage, data.remaining_sales_amount],
              ['Collection Progress', data.received_amount ?? 0, data.receivable_amount, data.collection_percentage, data.remaining_collection_amount],
            ].map(([label, actual, target, percentage, remaining]) => (
              <div key={String(label)} className="rounded-2xl border border-gray-100 bg-gray-50 p-4">
                <div className="flex items-center justify-between gap-3">
                  <p className="text-sm font-semibold text-gray-800">{label}</p>
                  <span className={`rounded-full px-2 py-0.5 text-xs font-bold ${percentageTone(percentage as number | null)}`}>{percentage == null ? 'Not ranked' : `${percentage}%`}</span>
                </div>
                <p className="mt-2 text-lg font-bold text-gray-900">{fmtAmount(Number(actual))} <span className="text-xs font-normal text-gray-400">of {fmtAmount(Number(target))}</span></p>
                <ProgressBar value={percentage as number | null} />
                <p className="mt-2 text-xs text-gray-500">Remaining: {fmtAmount(Number(remaining))}</p>
              </div>
            ))}
          </div>

          <div className="grid grid-cols-2 gap-3 text-center sm:grid-cols-4">
            {[
              ['Duration', `${data.duration_days} days`],
              ['Days elapsed', data.days_elapsed],
              ['Days remaining', data.days_remaining],
              ['Target type', data.target_type === 'monthly' ? 'Monthly' : 'Custom range'],
            ].map(([label, value]) => <div key={String(label)} className="rounded-xl border p-3"><p className="text-[10px] uppercase text-gray-400">{label}</p><p className="mt-1 text-sm font-bold text-gray-800">{value}</p></div>)}
          </div>

          <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
            <div className="overflow-hidden rounded-2xl border">
              <h4 className="border-b bg-gray-50 px-4 py-3 text-sm font-semibold text-gray-800">Contributing deals</h4>
              <div className="max-h-64 divide-y overflow-y-auto">
                {data.deals.length ? data.deals.map((raw) => {
                  const deal = raw as { id: number; title?: string; client?: string; amount?: number; date?: string };
                  return <Link key={deal.id} href={`/deals?search=${encodeURIComponent(deal.title ?? '')}`} className="flex items-center justify-between gap-3 px-4 py-3 hover:bg-gray-50"><div className="min-w-0"><p className="truncate text-sm font-medium text-gray-800">{deal.title}</p><p className="text-xs text-gray-400">{deal.client || '—'} · {deal.date ? displayDate(deal.date) : '—'}</p></div><span className="shrink-0 text-sm font-semibold">{fmtAmount(Number(deal.amount ?? 0))}</span></Link>;
                }) : <p className="p-5 text-sm text-gray-400">No qualifying won deals in this period.</p>}
              </div>
            </div>
            <div className="overflow-hidden rounded-2xl border">
              <h4 className="border-b bg-gray-50 px-4 py-3 text-sm font-semibold text-gray-800">Contributing payments</h4>
              <div className="max-h-64 divide-y overflow-y-auto">
                {data.payments.length ? data.payments.map((raw) => {
                  const payment = raw as { id: number; deal?: string; amount?: number; date?: string; method?: string };
                  return <Link key={payment.id} href={`/deals?search=${encodeURIComponent(payment.deal ?? '')}`} className="flex items-center justify-between gap-3 px-4 py-3 hover:bg-gray-50"><div className="min-w-0"><p className="truncate text-sm font-medium text-gray-800">{payment.deal}</p><p className="text-xs capitalize text-gray-400">{payment.date ? displayDate(payment.date) : '—'} · {(payment.method ?? '').replaceAll('_', ' ')}</p></div><span className="shrink-0 text-sm font-semibold text-emerald-700">{fmtAmount(Number(payment.amount ?? 0))}</span></Link>;
                }) : <p className="p-5 text-sm text-gray-400">No qualifying payments in this period.</p>}
              </div>
            </div>
          </div>
        </div>
      )}
    </Modal>
  );
}

type TargetForm = {
  targetId?: number;
  userId: number | '';
  targetType: SalesTargetType;
  month: string;
  startDate: string;
  endDate: string;
  targetAmount: string;
  collectionAmount: string;
};

function TargetFormModal({ form, employees, onChange, onClose, onSave, error, saving }: {
  form: TargetForm | null;
  employees: { id: number; name: string }[];
  onChange: (form: TargetForm) => void;
  onClose: () => void;
  onSave: () => void;
  error: string;
  saving: boolean;
}) {
  if (!form) return null;
  const monthStart = `${form.month}-01`;
  const [year, month] = form.month.split('-').map(Number);
  const monthEnd = `${form.month}-${String(new Date(year, month, 0).getDate()).padStart(2, '0')}`;
  const start = form.targetType === 'monthly' ? monthStart : form.startDate;
  const end = form.targetType === 'monthly' ? monthEnd : form.endDate;
  const validRange = !!start && !!end && end >= start;

  return (
    <Modal open onClose={onClose} title={form.targetId ? 'Edit Target' : 'Set Target'} maxWidth="max-w-md">
      <div className="space-y-4">
        {error && <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">{error}</div>}
        <div>
          <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-600">Team Member *</label>
          <select value={form.userId} disabled={!!form.targetId} onChange={(event) => onChange({ ...form, userId: event.target.value ? Number(event.target.value) : '' })} className="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm disabled:bg-gray-50">
            <option value="">Select team member…</option>
            {employees.map((employee) => <option key={employee.id} value={employee.id}>{employee.name}</option>)}
          </select>
        </div>
        <div>
          <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-600">Target Type *</label>
          <select value={form.targetType} onChange={(event) => onChange({ ...form, targetType: event.target.value as TargetForm['targetType'] })} className="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
            <option value="monthly">Monthly</option>
            <option value="custom">Custom Date Range</option>
          </select>
        </div>
        {form.targetType === 'monthly' ? (
          <div><label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-600">Month and Year *</label><input type="month" value={form.month} onChange={(event) => onChange({ ...form, month: event.target.value })} className="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm" /></div>
        ) : (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div><label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-600">Start Date *</label><input type="date" value={form.startDate} onChange={(event) => onChange({ ...form, startDate: event.target.value })} className="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm" /></div>
            <div><label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-600">End Date *</label><input type="date" min={form.startDate} value={form.endDate} onChange={(event) => onChange({ ...form, endDate: event.target.value })} className="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm" /></div>
          </div>
        )}
        {validRange && <div className="rounded-xl border border-indigo-100 bg-indigo-50 px-4 py-3"><p className="text-xs font-semibold text-indigo-800">Target Period: {dateRange(start, end)}</p><p className="mt-1 text-xs text-indigo-600">Duration: {daysInclusive(start, end)} days</p></div>}
        <div><label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-600">Sales Target (₹) *</label><input type="number" min={0} step={1000} value={form.targetAmount} onChange={(event) => onChange({ ...form, targetAmount: event.target.value })} className="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm" /></div>
        <div><label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-600">Collection Target (₹) *</label><input type="number" min={0} step={1000} value={form.collectionAmount} onChange={(event) => onChange({ ...form, collectionAmount: event.target.value })} className="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm" /></div>
        <div className="flex gap-3 pt-1"><button onClick={onClose} className="flex-1 rounded-xl border border-gray-200 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50">Cancel</button><button onClick={onSave} disabled={saving || !form.userId || !validRange || form.targetAmount === '' || form.collectionAmount === ''} className="flex-1 rounded-xl bg-indigo-600 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">{saving ? 'Saving…' : 'Save Target'}</button></div>
      </div>
    </Modal>
  );
}

function ManagerView() {
  const now = new Date();
  const currentMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
  const qc = useQueryClient();
  const [month, setMonth] = useState(currentMonth);
  const [employee, setEmployee] = useState('');
  const [department, setDepartment] = useState('');
  const [type, setType] = useState('');
  const [status, setStatus] = useState('');
  const [form, setForm] = useState<TargetForm | null>(null);
  const [detailsId, setDetailsId] = useState<number | null>(null);
  const [saveError, setSaveError] = useState('');

  const { data: employees = [] } = useQuery({ queryKey: ['employees'], queryFn: () => employeesApi.list(), retry: false });
  const { data: departments = [] } = useQuery({ queryKey: ['departments'], queryFn: departmentsApi.list, retry: false });
  const filters = { month, user_id: employee ? Number(employee) : undefined, department_id: department ? Number(department) : undefined, target_type: type || undefined, status: status || undefined };
  const { data: rows = [], isLoading } = useQuery({ queryKey: ['sales-targets', filters], queryFn: () => salesTargetsApi.list(filters) });
  const save = useMutation({
    mutationFn: salesTargetsApi.upsert,
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['sales-targets'] }); qc.invalidateQueries({ queryKey: ['dashboard'] }); setForm(null); setSaveError(''); },
    onError: (error: unknown) => {
      const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
      setSaveError(response?.errors ? Object.values(response.errors).flat()[0] : response?.message ?? 'Unable to save target.');
    },
  });

  const newForm = (row?: SalesTargetRow): TargetForm => ({
    targetId: row?.id,
    userId: row?.user?.id ?? '',
    targetType: row?.target_type ?? 'monthly',
    month: row?.period_start.slice(0, 7) ?? month,
    startDate: row?.period_start ?? `${month}-01`,
    endDate: row?.period_end ?? '',
    targetAmount: row ? String(row.target_amount) : '',
    collectionAmount: row ? String(row.receivable_amount) : '',
  });

  const submit = () => {
    if (!form || !form.userId) return;
    if (form.targetType === 'monthly' && !form.month) return;
    if (form.targetType === 'custom' && (!form.startDate || !form.endDate || form.endDate < form.startDate)) return;
    const [year, monthNumber] = form.month.split('-').map(Number);
    const periodStart = form.targetType === 'monthly' ? `${form.month}-01` : form.startDate;
    const periodEnd = form.targetType === 'monthly' ? `${form.month}-${String(new Date(year, monthNumber, 0).getDate()).padStart(2, '0')}` : form.endDate;
    const payload: SalesTargetUpsertPayload = { target_id: form.targetId, user_id: Number(form.userId), target_type: form.targetType, period_start: periodStart, period_end: periodEnd, target_amount: Number(form.targetAmount), receivable_amount: Number(form.collectionAmount) };
    save.mutate(payload);
  };

  return <div className="space-y-5">
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div className="flex items-center gap-2"><button onClick={() => setMonth(shiftMonth(month, -1))} className="h-9 w-9 rounded-lg text-gray-400 hover:bg-indigo-50 hover:text-indigo-600">‹</button><span className="min-w-[140px] text-center text-sm font-semibold text-gray-700">{formatMonth(month)}</span><button onClick={() => setMonth(shiftMonth(month, 1))} className="h-9 w-9 rounded-lg text-gray-400 hover:bg-indigo-50 hover:text-indigo-600">›</button></div>
      <button onClick={() => { setSaveError(''); setForm(newForm()); }} className="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">+ Set Target</button>
    </div>
    <div className="grid grid-cols-1 gap-3 rounded-2xl border bg-white p-4 sm:grid-cols-2 lg:grid-cols-4">
      <select value={employee} onChange={(event) => setEmployee(event.target.value)} className="rounded-xl border border-gray-200 px-3 py-2.5 text-sm"><option value="">All employees</option>{employees.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>
      <select value={department} onChange={(event) => setDepartment(event.target.value)} className="rounded-xl border border-gray-200 px-3 py-2.5 text-sm"><option value="">All departments</option>{departments.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>
      <select value={type} onChange={(event) => setType(event.target.value)} className="rounded-xl border border-gray-200 px-3 py-2.5 text-sm"><option value="">All target types</option><option value="monthly">Monthly</option><option value="custom">Custom range</option></select>
      <select value={status} onChange={(event) => setStatus(event.target.value)} className="rounded-xl border border-gray-200 px-3 py-2.5 text-sm"><option value="">All statuses</option><option value="active">Active</option><option value="upcoming">Upcoming</option><option value="completed">Completed</option></select>
    </div>
    <div className="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b px-5 py-4"><div><h2 className="text-sm font-semibold text-gray-900">Targets overlapping {formatMonth(month)}</h2><p className="text-[11px] text-gray-400">Monthly and custom periods are both included</p></div><span className="text-xs text-gray-400">{rows.length} target{rows.length !== 1 ? 's' : ''}</span></div>
      {isLoading ? <div className="p-10 text-center text-sm text-gray-400">Loading targets…</div> : rows.length === 0 ? <div className="p-12 text-center"><p className="font-semibold text-gray-600">No targets overlap this period</p><p className="mt-1 text-sm text-gray-400">Set a monthly or custom target for a team member.</p></div> : <div className="touch-scroll overflow-x-auto"><table className="min-w-[1280px] w-full text-sm"><thead><tr className="border-b bg-gray-50 text-[10px] uppercase tracking-wide text-gray-500">{['Employee','Department / Team','Type','Target Period','Sales Target','Achieved','Sales %','Collection Target','Collected','Collection %','Status','Actions'].map((heading) => <th key={heading} className="px-4 py-3 text-left font-semibold">{heading}</th>)}</tr></thead><tbody className="divide-y divide-gray-50">{rows.map((row) => <tr key={row.id} className="hover:bg-gray-50/70"><td className="px-4 py-3.5 font-semibold text-gray-800">{row.user?.name}</td><td className="px-4 py-3.5 text-xs text-gray-500">{row.department || '—'}</td><td className="px-4 py-3.5"><span className="rounded-full bg-violet-50 px-2 py-1 text-[10px] font-semibold capitalize text-violet-700">{row.target_type === 'custom' ? 'Custom range' : 'Monthly'}</span></td><td className="px-4 py-3.5 text-xs text-gray-600">{row.period_label}</td><td className="px-4 py-3.5 font-medium">{fmtAmount(row.target_amount)}</td><td className="px-4 py-3.5">{fmtAmount(row.achieved_amount)}</td><td className="px-4 py-3.5"><span className={`rounded-full px-2 py-0.5 text-xs font-bold ${percentageTone(row.sales_percentage)}`}>{row.sales_percentage == null ? '—' : `${row.sales_percentage}%`}</span></td><td className="px-4 py-3.5 font-medium">{fmtAmount(row.receivable_amount)}</td><td className="px-4 py-3.5">{fmtAmount(row.received_amount ?? 0)}</td><td className="px-4 py-3.5"><span className={`rounded-full px-2 py-0.5 text-xs font-bold ${percentageTone(row.collection_percentage)}`}>{row.collection_percentage == null ? '—' : `${row.collection_percentage}%`}</span></td><td className="px-4 py-3.5"><StatusBadge status={row.period_status} /></td><td className="px-4 py-3.5"><div className="flex gap-3"><button onClick={() => { setSaveError(''); setForm(newForm(row)); }} className="text-xs font-medium text-indigo-600">Edit</button><button onClick={() => setDetailsId(row.id)} className="text-xs font-medium text-gray-600">Details →</button></div></td></tr>)}</tbody></table></div>}
    </div>
    <TargetFormModal form={form} employees={employees} onChange={setForm} onClose={() => setForm(null)} onSave={submit} error={saveError} saving={save.isPending} />
    <TargetDetailsModal targetId={detailsId} onClose={() => setDetailsId(null)} />
  </div>;
}

function TeamMemberView() {
  const { data: history = [], isLoading } = useQuery({ queryKey: ['my-targets'], queryFn: salesTargetsApi.myProgress });
  const [detailsId, setDetailsId] = useState<number | null>(null);
  const active = useMemo(() => history.find((item) => item.period_status === 'active'), [history]);
  if (isLoading) return <div className="py-16 text-center text-sm text-gray-400">Loading your targets…</div>;

  return <div className="space-y-6">
    {active ? <div className="space-y-4"><div className="flex flex-wrap items-center justify-between gap-2"><div><h2 className="text-sm font-semibold text-gray-900">Current Target</h2><p className="text-xs text-indigo-600">{active.period_label}</p></div><StatusBadge status={active.period_status} /></div><div className="grid grid-cols-1 gap-4 sm:grid-cols-2">{[
      ['Sales Progress', active.achieved_amount, active.target_amount, active.sales_percentage, active.remaining_sales_amount],
      ['Collection Progress', active.received_amount ?? 0, active.receivable_amount, active.collection_percentage, active.remaining_collection_amount],
    ].map(([label, actual, target, percentage, remaining]) => <div key={String(label)} className="rounded-2xl border bg-white p-5 shadow-sm"><div className="flex justify-between gap-3"><p className="text-sm font-semibold text-gray-800">{label}</p><span className={`rounded-full px-2 py-0.5 text-xs font-bold ${percentageTone(percentage as number | null)}`}>{percentage == null ? '—' : `${percentage}%`}</span></div><p className="mt-3 text-2xl font-bold">{fmtAmount(Number(actual))}</p><p className="text-xs text-gray-400">of {fmtAmount(Number(target))}</p><ProgressBar value={percentage as number | null} /><p className="mt-3 text-xs text-gray-500">Remaining {fmtAmount(Number(remaining))} · {active.days_remaining} days left</p></div>)}</div></div> : <div className="rounded-2xl border bg-white p-10 text-center"><p className="font-semibold text-gray-700">No active target</p><p className="mt-1 text-sm text-gray-400">Your manager has not assigned a target for today.</p></div>}
    <div className="overflow-hidden rounded-2xl border bg-white"><div className="border-b px-5 py-4"><h2 className="text-sm font-semibold text-gray-900">Target History</h2><p className="text-xs text-gray-400">Current, upcoming and completed target periods</p></div><div className="touch-scroll overflow-x-auto"><table className="min-w-[900px] w-full text-sm"><thead><tr className="border-b bg-gray-50 text-[10px] uppercase text-gray-500">{['Type','Period','Sales','Achieved','Sales %','Collection','Collected','Collection %','Status',''].map((heading) => <th key={heading} className="px-4 py-3 text-left">{heading}</th>)}</tr></thead><tbody className="divide-y">{history.map((row: MyTargetProgress) => <tr key={row.target_id ?? row.period_start}><td className="px-4 py-3 capitalize">{row.target_type === 'custom' ? 'Custom range' : 'Monthly'}</td><td className="px-4 py-3 text-xs">{row.period_label}</td><td className="px-4 py-3">{fmtAmount(row.target_amount)}</td><td className="px-4 py-3">{fmtAmount(row.achieved_amount)}</td><td className="px-4 py-3">{row.sales_percentage == null ? '—' : `${row.sales_percentage}%`}</td><td className="px-4 py-3">{fmtAmount(row.receivable_amount)}</td><td className="px-4 py-3">{fmtAmount(row.received_amount ?? 0)}</td><td className="px-4 py-3">{row.collection_percentage == null ? '—' : `${row.collection_percentage}%`}</td><td className="px-4 py-3"><StatusBadge status={row.period_status} /></td><td className="px-4 py-3"><button onClick={() => setDetailsId(row.target_id)} className="text-xs font-medium text-indigo-600">Details →</button></td></tr>)}</tbody></table></div></div>
    <TargetDetailsModal targetId={detailsId} onClose={() => setDetailsId(null)} />
  </div>;
}

export default function TargetsPage() {
  const { isOwner, isAdmin, isPaidPlan } = useAuthStore();
  const canManage = isOwner() || isAdmin();
  if (!isPaidPlan()) return <AccessDenied reason="Sales targets are available on paid plans." upgradeHref="/plans" />;
  return <div className="space-y-6"><motion.div initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }}><h1 className="text-xl font-bold text-gray-900">{canManage ? 'Sales Targets' : 'My Targets'}</h1><p className="mt-0.5 text-xs text-gray-400">{canManage ? 'Set and track monthly or custom-date-range targets for your team' : 'Your sales and collection progress for each assigned period'}</p></motion.div>{canManage ? <ManagerView /> : <TeamMemberView />}</div>;
}
