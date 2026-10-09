'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import { motion, AnimatePresence } from 'framer-motion';
import { dealsApi, type DealPayload } from '@/lib/api/deals';
import { pipelinesApi } from '@/lib/api/pipelines';
import { organizationApi } from '@/lib/api/organization';
import { useAuthStore } from '@/store/authStore';
import { getLimits } from '@/lib/planLimits';
import { PlanLimitBar } from '@/components/ui/PlanLimitBar';
import { Modal } from '@/components/ui/Modal';
import { Badge } from '@/components/ui/Badge';
import { SkeletonTable } from '@/components/ui/Skeleton';
import MoneyReceipt from '@/components/deals/MoneyReceipt';
import type { Deal, DealPayment, PaginatedResponse } from '@/types';
import { PAYMENT_MODES, paymentModeLabel } from '@/lib/paymentModes';
import { currencyLabel, currencySymbol, formatDealMoney, normalizeDealCurrency } from '@/lib/currency';

const LOST_REASONS = [
  'Price too high',
  'Went with competitor',
  'No budget',
  'No response',
  'Not a fit',
  'Other',
];

function fmtDate(d: string) {
  return new Date(d).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: '2-digit' });
}

function dealRemaining(deal: Deal): number | null {
  if (deal.value == null) return null;
  const received = Number(deal.total_received ?? 0);
  return Math.max(0, Number(deal.value) - received);
}

function DealForm({ deal, onClose, onSave, saving }: {
  deal?: Deal | null;
  onClose: () => void;
  onSave: (d: DealPayload) => void;
  saving?: boolean;
}) {
  const { data: pipelines } = useQuery({ queryKey: ['pipelines'], queryFn: pipelinesApi.list });
  const [selectedPipelineId, setSelectedPipelineId] = useState<number>(deal?.pipeline_id ?? 0);

  // Derive the active pipeline — fall back to first pipeline when nothing selected yet
  const pipelineId = selectedPipelineId || pipelines?.[0]?.id || 0;
  const stages     = pipelines?.find((p) => p.id === pipelineId)?.stages ?? [];

  const [form, setForm] = useState<DealPayload>({
    title:               deal?.title ?? '',
    pipeline_id:         deal?.pipeline_id ?? 0,
    stage_id:            deal?.stage_id ?? 0,
    value:               deal?.value,
    currency:            deal?.currency ?? 'INR',
    status:              deal?.status ?? 'open',
    probability:         deal?.probability,
    expected_close_date: deal?.expected_close_date ?? '',
    description:         deal?.description ?? '',
    lost_reason:         deal?.lost_reason ?? '',
    original_value:      deal?.original_value,
    counter_offer_value: deal?.counter_offer_value,
    negotiation_notes:   deal?.negotiation_notes ?? '',
  });

  const set = (k: keyof DealPayload, v: unknown) => setForm((f) => ({ ...f, [k]: v }));

  const inputCls = 'w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-shadow';
  const symbol = currencySymbol(form.currency);

  return (
    <div className="space-y-4">
      <div>
        <label className="block text-xs font-semibold text-gray-600 mb-1.5">Deal Title *</label>
        <input
          value={form.title}
          onChange={(e) => set('title', e.target.value)}
          placeholder="e.g. Enterprise contract Q3"
          className={inputCls}
        />
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Pipeline</label>
          <select
            value={pipelineId}
            onChange={(e) => {
              const id = Number(e.target.value);
              setSelectedPipelineId(id);
              set('pipeline_id', id);
              set('stage_id', 0);
            }}
            className={inputCls}
          >
            {pipelines?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        </div>
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Stage *</label>
          <select value={form.stage_id} onChange={(e) => set('stage_id', Number(e.target.value))} className={inputCls}>
            <option value={0}>Select stage…</option>
            {stages.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Type</label>
          <select
            value={form.currency ?? 'INR'}
            onChange={(e) => set('currency', e.target.value)}
            className={inputCls}
          >
            <option value="INR">INR (₹)</option>
            <option value="USD">Dollar ($)</option>
          </select>
        </div>
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Value ({symbol})</label>
          <input
            type="number"
            min={0}
            value={form.value ?? ''}
            onChange={(e) => set('value', e.target.value ? Number(e.target.value) : undefined)}
            placeholder="0"
            className={inputCls}
          />
        </div>
      </div>

      <div>
        <label className="block text-xs font-semibold text-gray-600 mb-1.5">Probability (%)</label>
        <input
          type="number"
          min={0}
          max={100}
          value={form.probability ?? ''}
          onChange={(e) => set('probability', e.target.value ? Number(e.target.value) : undefined)}
          placeholder="50"
          className={inputCls}
        />
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Status</label>
          <select value={form.status} onChange={(e) => set('status', e.target.value)} className={inputCls}>
            {['open', 'won', 'lost'].map((s) => (
              <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>
            ))}
          </select>
        </div>
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Expected Close Date</label>
          <input
            type="date"
            value={form.expected_close_date ?? ''}
            onChange={(e) => set('expected_close_date', e.target.value)}
            className={inputCls}
          />
        </div>
      </div>

      {form.status === 'lost' && (
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Lost Reason</label>
          <select
            value={form.lost_reason ?? ''}
            onChange={(e) => set('lost_reason', e.target.value)}
            className={inputCls}
          >
            <option value="">No reason specified</option>
            {LOST_REASONS.map((r) => <option key={r} value={r}>{r}</option>)}
          </select>
        </div>
      )}

      <div>
        <label className="block text-xs font-semibold text-gray-600 mb-1.5">Description</label>
        <textarea
          rows={2}
          value={form.description ?? ''}
          onChange={(e) => set('description', e.target.value)}
          placeholder="Optional notes…"
          className={`${inputCls} resize-none`}
        />
      </div>

      {/* ── Negotiation Section ──────────────────────────────────────────── */}
      <div className="border border-indigo-100 rounded-2xl overflow-hidden">
        <div className="bg-indigo-50/60 px-4 py-2.5 flex items-center gap-2">
          <svg className="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
          </svg>
          <span className="text-xs font-bold text-indigo-700 uppercase tracking-wide">Negotiation</span>
        </div>

        <div className="p-4 space-y-4">
          {/* Counter-offer tracker */}
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label className="block text-xs font-semibold text-gray-600 mb-1.5">
                Original Value ({symbol})
                <span className="ml-1 font-normal text-gray-400">— your quote</span>
              </label>
              <input
                type="number"
                min={0}
                value={form.original_value ?? ''}
                onChange={(e) => set('original_value', e.target.value ? Number(e.target.value) : undefined)}
                placeholder="e.g. 60000"
                className={inputCls}
              />
            </div>
            <div>
              <label className="block text-xs font-semibold text-gray-600 mb-1.5">
                Counter Offer ({symbol})
                <span className="ml-1 font-normal text-gray-400">— client asks</span>
              </label>
              <input
                type="number"
                min={0}
                value={form.counter_offer_value ?? ''}
                onChange={(e) => set('counter_offer_value', e.target.value ? Number(e.target.value) : undefined)}
                placeholder="e.g. 45000"
                className={inputCls}
              />
            </div>
          </div>

          {/* Live difference indicator */}
          {form.original_value != null && form.counter_offer_value != null && (
            (() => {
              const diff   = Number(form.counter_offer_value) - Number(form.original_value);
              const pct    = ((diff / Number(form.original_value)) * 100).toFixed(1);
              const isDown = diff < 0;
              return (
                <div className={`flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-semibold ${
                  isDown ? 'bg-red-50 text-red-600' : 'bg-emerald-50 text-emerald-700'
                }`}>
                  <svg className="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                      d={isDown ? 'M13 17H8m0 0l4-4m-4 4l4 4M21 12a9 9 0 11-18 0 9 9 0 0118 0z'
                               : 'M13 7h8m0 0l-4 4m4-4l-4-4M5 12a9 9 0 1018 0 9 9 0 01-18 0z'} />
                  </svg>
                  {isDown ? 'Client asking' : 'Client offering'}
                  {' '}{formatDealMoney(Math.abs(diff), form.currency)} {isDown ? 'less' : 'more'}
                  {' '}({isDown ? '' : '+'}{pct}%) —{' '}
                  gap between {formatDealMoney(form.original_value, form.currency)} and {formatDealMoney(form.counter_offer_value, form.currency)}
                </div>
              );
            })()
          )}

          {/* Negotiation notes */}
          <div>
            <label className="block text-xs font-semibold text-gray-600 mb-1.5">Negotiation Notes</label>
            <textarea
              rows={3}
              value={form.negotiation_notes ?? ''}
              onChange={(e) => set('negotiation_notes', e.target.value)}
              placeholder="e.g. Client wants 20% discount + 6-month payment plan. Legal reviewing contract. Decision by Friday."
              className={`${inputCls} resize-none`}
            />
          </div>
        </div>
      </div>

      <div className="flex gap-3 pt-2">
        <button
          onClick={() => onSave({ ...form, pipeline_id: pipelineId, stage_id: form.stage_id })}
          disabled={saving || !form.title.trim() || !form.stage_id}
          className="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white font-semibold
                     py-2.5 rounded-xl text-sm flex items-center justify-center gap-2 transition-colors"
        >
          {saving && (
            <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
          )}
          {deal ? 'Save Changes' : 'Create Deal'}
        </button>
        <button
          onClick={onClose}
          className="px-5 text-sm text-gray-500 hover:text-gray-700 border border-gray-200 rounded-xl transition-colors"
        >
          Cancel
        </button>
      </div>
    </div>
  );
}

const STATUS_PILLS = [
  { value: '',     label: 'All' },
  { value: 'open', label: 'Open' },
  { value: 'won',  label: 'Won' },
  { value: 'lost', label: 'Lost' },
];

// ── Add Payment Modal ─────────────────────────────────────────────────────────
function AddPaymentModal({ deal, onClose, onSave, saving }: {
  deal: Deal;
  onClose: () => void;
  onSave: (payload: { amount: number; payment_date: string; payment_mode: string; txn_or_utr_number?: string; notes?: string }) => void;
  saving?: boolean;
}) {
  const [amount, setAmount] = useState('');
  const [date, setDate]     = useState(new Date().toISOString().slice(0, 10));
  const [mode, setMode]     = useState('bank_transfer');
  const [utr, setUtr]       = useState('');
  const [notes, setNotes]   = useState('');

  const inputCls = 'w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 transition-shadow';

  const handleSave = () => {
    onSave({
      amount: Number(amount),
      payment_date: date,
      payment_mode: mode,
      ...(utr.trim()   ? { txn_or_utr_number: utr.trim() }   : {}),
      ...(notes.trim() ? { notes: notes.trim() }              : {}),
    });
  };

  return (
    <div className="space-y-4">
      <p className="text-sm text-gray-500">
        Add a new payment for <span className="font-semibold text-gray-800">{deal.title}</span>.
      </p>

      {deal.total_received != null && deal.total_received > 0 && (
        <div className="bg-emerald-50 border border-emerald-100 rounded-xl px-4 py-2 text-sm text-emerald-700 flex items-center gap-2">
          <svg className="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          Total received so far: <strong>{formatDealMoney(deal.total_received, deal.currency)}</strong>
          {deal.payments_count != null && deal.payments_count > 0 && (
            <span className="text-emerald-500">({deal.payments_count} payment{deal.payments_count !== 1 ? 's' : ''})</span>
          )}
        </div>
      )}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Amount ({currencySymbol(deal.currency)}) *</label>
          <input
            type="number"
            min={0.01}
            step="0.01"
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
            placeholder="e.g. 50000"
            className={inputCls}
            autoFocus
          />
        </div>
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Payment Date *</label>
          <input
            type="date"
            value={date}
            onChange={(e) => setDate(e.target.value)}
            className={inputCls}
          />
        </div>
      </div>

      <div>
        <label className="block text-xs font-semibold text-gray-600 mb-1.5">Payment Mode *</label>
        <select value={mode} onChange={(e) => setMode(e.target.value)} className={inputCls}>
          {PAYMENT_MODES.map((m) => (
            <option key={m.value} value={m.value}>{m.label}</option>
          ))}
        </select>
      </div>

      <div>
        <label className="block text-xs font-semibold text-gray-600 mb-1.5">
          UTR / Transaction #
          <span className="ml-1 font-normal text-gray-400">— optional</span>
        </label>
        <input
          value={utr}
          onChange={(e) => setUtr(e.target.value)}
          placeholder="e.g. UTR123456789 or TXN-001"
          className={inputCls}
        />
      </div>

      <div>
        <label className="block text-xs font-semibold text-gray-600 mb-1.5">
          Notes
          <span className="ml-1 font-normal text-gray-400">— optional</span>
        </label>
        <textarea
          rows={2}
          value={notes}
          onChange={(e) => setNotes(e.target.value)}
          placeholder="e.g. First instalment, partial payment…"
          className={`${inputCls} resize-none`}
        />
      </div>

      <div className="flex gap-3 pt-1">
        <button
          onClick={handleSave}
          disabled={saving || !amount || Number(amount) <= 0 || !date || !mode}
          className="flex-1 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-60 text-white font-semibold
                     py-2.5 rounded-xl text-sm flex items-center justify-center gap-2 transition-colors"
        >
          {saving && (
            <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
          )}
          Record Payment
        </button>
        <button
          onClick={onClose}
          className="px-5 text-sm text-gray-500 hover:text-gray-700 border border-gray-200 rounded-xl transition-colors"
        >
          Cancel
        </button>
      </div>
    </div>
  );
}

// ── Transactions History Modal ────────────────────────────────────────────────
function TransactionsModal({ deal, onAddPayment, onViewReceipt }: {
  deal: Deal;
  onAddPayment: () => void;
  onViewReceipt: (payment: DealPayment) => void;
}) {
  const qc = useQueryClient();
  const [editingId, setEditingId] = useState<number | null>(null);
  const [editAmount, setEditAmount] = useState('');
  const [editDate, setEditDate] = useState('');
  const [editMode, setEditMode] = useState('bank_transfer');
  const [editUtr, setEditUtr] = useState('');
  const [editNotes, setEditNotes] = useState('');
  const [editError, setEditError] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['deal-payments', deal.id],
    queryFn: () => dealsApi.listPayments(deal.id),
    staleTime: 30_000,
  });

  const payments = data?.data ?? [];
  const total    = data?.total ?? 0;

  const startEdit = (p: DealPayment) => {
    setEditingId(p.id);
    setEditAmount(String(p.amount));
    setEditDate(p.payment_date.slice(0, 10));
    setEditMode(p.payment_mode);
    setEditUtr(p.txn_or_utr_number ?? '');
    setEditNotes(p.notes ?? '');
    setEditError('');
  };

  const cancelEdit = () => {
    setEditingId(null);
    setEditAmount('');
    setEditDate('');
    setEditMode('bank_transfer');
    setEditUtr('');
    setEditNotes('');
    setEditError('');
  };

  const updatePaymentMutation = useMutation({
    mutationFn: ({
      paymentId, amount, payment_date, payment_mode, txn_or_utr_number, notes,
    }: {
      paymentId: number;
      amount: number;
      payment_date: string;
      payment_mode: string;
      txn_or_utr_number: string | null;
      notes: string | null;
    }) =>
      dealsApi.updatePayment(deal.id, paymentId, {
        amount,
        payment_date,
        payment_mode,
        txn_or_utr_number,
        notes,
      }),
    onSuccess: (result) => {
      qc.invalidateQueries({ queryKey: ['deal-payments', deal.id] });
      qc.invalidateQueries({ queryKey: ['deals'] });
      qc.invalidateQueries({ queryKey: ['sales-targets'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      qc.invalidateQueries({ queryKey: ['reports'] });
      qc.invalidateQueries({ queryKey: ['pipelines'] });
      cancelEdit();
      if (result.deal_marked_won || result.deal_reopened) {
        alert(result.message);
      }
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      setEditError(msg || 'Failed to update payment.');
    },
  });

  const saveEdit = () => {
    const amount = Number(editAmount);
    if (!editDate || Number.isNaN(amount) || amount <= 0) {
      setEditError('Enter a valid amount and date.');
      return;
    }
    if (!editMode) {
      setEditError('Select a payment mode.');
      return;
    }
    if (editingId == null) return;
    updatePaymentMutation.mutate({
      paymentId: editingId,
      amount,
      payment_date: editDate,
      payment_mode: editMode,
      txn_or_utr_number: editUtr.trim() || null,
      notes: editNotes.trim() || null,
    });
  };

  const inputCls = 'border border-gray-200 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white';

  return (
    <div className="space-y-4">
      {/* Header row */}
      <div className="flex items-center justify-between">
        <p className="text-sm text-gray-500">
          <span className="font-semibold text-gray-800">{deal.title}</span>
          {' '}· {payments.length} payment{payments.length !== 1 ? 's' : ''}
        </p>
        {total > 0 && (
          <span className="text-sm font-bold text-emerald-700">
            Total: {formatDealMoney(total, deal.currency)}
          </span>
        )}
      </div>

      {isLoading ? (
        <div className="space-y-2">
          {[1,2,3].map((i) => (
            <div key={i} className="h-10 skeleton rounded-xl" />
          ))}
        </div>
      ) : payments.length === 0 ? (
        <div className="text-center py-8">
          <div className="w-12 h-12 bg-gray-50 rounded-2xl flex items-center justify-center mx-auto mb-3">
            <svg className="w-6 h-6 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <p className="text-sm text-gray-500">No payments recorded yet.</p>
        </div>
      ) : (
        <div className="overflow-x-auto">
              <table className="min-w-[760px] w-full text-sm">
            <thead>
              <tr className="border-b border-gray-100">
                <th className="pb-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date</th>
                <th className="pb-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Amount</th>
                <th className="pb-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider pl-4">Mode</th>
                <th className="pb-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider pl-4">UTR / Txn#</th>
                <th className="pb-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider pl-4">Notes</th>
                <th className="pb-2 pl-4" />
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {payments.map((p: DealPayment) => {
                const isEditing = editingId === p.id;
                return (
                  <tr key={p.id} className="hover:bg-gray-50/60 group">
                    <td className="py-2.5 text-gray-700 font-medium whitespace-nowrap">
                      {isEditing ? (
                        <input
                          type="date"
                          value={editDate}
                          onChange={(e) => { setEditDate(e.target.value); setEditError(''); }}
                          className={inputCls}
                        />
                      ) : (
                        fmtDate(p.payment_date)
                      )}
                    </td>
                    <td className="py-2.5 text-right font-semibold text-emerald-700 whitespace-nowrap">
                      {isEditing ? (
                        <input
                          type="number"
                          min={0.01}
                          step="0.01"
                          value={editAmount}
                          onChange={(e) => { setEditAmount(e.target.value); setEditError(''); }}
                          className={`${inputCls} w-28 text-right`}
                        />
                      ) : (
                        <>{formatDealMoney(p.amount, deal.currency)}</>
                      )}
                    </td>
                    <td className="py-2.5 pl-4 capitalize text-gray-600 whitespace-nowrap">
                      {isEditing ? (
                        <select
                          value={editMode}
                          onChange={(e) => { setEditMode(e.target.value); setEditError(''); }}
                          className={inputCls}
                        >
                          {PAYMENT_MODES.map((m) => (
                            <option key={m.value} value={m.value}>{m.label}</option>
                          ))}
                        </select>
                      ) : (
                        paymentModeLabel(p.payment_mode)
                      )}
                    </td>
                    <td className="py-2.5 pl-4 text-gray-400 font-mono text-xs">
                      {isEditing ? (
                        <input
                          value={editUtr}
                          onChange={(e) => { setEditUtr(e.target.value); setEditError(''); }}
                          placeholder="UTR / Txn#"
                          className={`${inputCls} w-36 font-mono`}
                        />
                      ) : (
                        p.txn_or_utr_number ?? '—'
                      )}
                    </td>
                    <td className="py-2.5 pl-4 text-gray-400 max-w-[160px]">
                      {isEditing ? (
                        <input
                          value={editNotes}
                          onChange={(e) => { setEditNotes(e.target.value); setEditError(''); }}
                          placeholder="Notes"
                          className={`${inputCls} w-40`}
                        />
                      ) : (
                        <span className="truncate block max-w-[160px]">{p.notes ?? '—'}</span>
                      )}
                    </td>
                    <td className="py-2.5 pl-4 whitespace-nowrap">
                      <div className="flex items-center justify-end gap-2">
                        {isEditing ? (
                          <>
                            <button
                              onClick={saveEdit}
                              disabled={updatePaymentMutation.isPending}
                              className="text-xs font-semibold px-2.5 py-1 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-60 transition-colors"
                            >
                              {updatePaymentMutation.isPending ? 'Saving…' : 'Save'}
                            </button>
                            <button
                              onClick={cancelEdit}
                              disabled={updatePaymentMutation.isPending}
                              className="text-xs font-medium px-2.5 py-1 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 transition-colors"
                            >
                              Cancel
                            </button>
                          </>
                        ) : (
                          <>
                            <button
                              onClick={() => startEdit(p)}
                              className="flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-indigo-600 transition-colors"
                              title="Edit payment"
                            >
                              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                  d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                              </svg>
                              Edit
                            </button>
                            <button
                              onClick={() => onViewReceipt(p)}
                              className="flex items-center gap-1 text-xs font-medium text-indigo-500 hover:text-indigo-700 transition-colors"
                              title="View receipt"
                            >
                              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                  d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                              </svg>
                              Receipt
                            </button>
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
            {payments.length > 1 && (
              <tfoot>
                <tr className="border-t-2 border-gray-200">
                  <td className="pt-2.5 text-xs font-semibold text-gray-500 uppercase">Total</td>
                  <td className="pt-2.5 text-right font-bold text-emerald-700">
                    {formatDealMoney(total, deal.currency)}
                  </td>
                  <td colSpan={4} />
                </tr>
              </tfoot>
            )}
          </table>
        </div>
      )}

      {editError && (
        <p className="text-xs text-red-600">{editError}</p>
      )}

      {/* Add payment CTA — open deals only */}
      {deal.status === 'open' && (
        <div className="pt-2 border-t border-gray-100">
          <button
            onClick={onAddPayment}
            className="flex items-center gap-2 text-sm font-semibold text-emerald-600 hover:text-emerald-700 transition-colors"
          >
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
            </svg>
            Add Another Payment
          </button>
        </div>
      )}
    </div>
  );
}

export default function DealsPage() {
  const qc = useQueryClient();
  const searchParams = useSearchParams();
  const { user, isOwner, isAdmin, isPaidPlan } = useAuthStore();

  const isManager = isOwner() || isAdmin();
  const [viewMine, setViewMine]         = useState(!isManager);
  const [page, setPage]                 = useState(1);
  const linkedDealSearch = searchParams.get('search')?.trim() ?? '';
  const [search, setSearch]             = useState(linkedDealSearch);
  const [statusFilter, setStatusFilter] = useState('');
  const [modal, setModal]               = useState<Deal | null | undefined>(undefined);
  const [addPaymentModal, setAddPaymentModal] = useState<Deal | null>(null);
  const [txnsModal, setTxnsModal]             = useState<Deal | null>(null);
  const [receiptModal, setReceiptModal]       = useState<{ payment: DealPayment; deal: Deal } | null>(null);
  const [saveError, setSaveError]       = useState('');
  const [openedDeepLinkId, setOpenedDeepLinkId] = useState<number | null>(null);

  // Receipt settings — fetched once, used when opening a receipt
  const { data: receiptSettings } = useQuery({
    queryKey: ['receipt-settings'],
    queryFn: organizationApi.getReceiptSettings,
    staleTime: 5 * 60_000,
  });

  const assignedToFilter = viewMine ? user?.id : undefined;

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['deals', { status: statusFilter, page, assigned_to: assignedToFilter, search }],
    queryFn: () => dealsApi.list({
      status: statusFilter || undefined,
      assigned_to: assignedToFilter,
      search: search.trim() || undefined,
      page,
    }),
    placeholderData: keepPreviousData,
  });

  useEffect(() => {
    const linkedDeal = linkedDealSearch
      ? data?.data.find((deal) => deal.title.toLocaleLowerCase() === linkedDealSearch.toLocaleLowerCase())
      : undefined;
    if (linkedDeal && openedDeepLinkId !== linkedDeal.id) {
      setSaveError('');
      setModal(linkedDeal);
      setOpenedDeepLinkId(linkedDeal.id);
    }
  }, [data?.data, linkedDealSearch, openedDeepLinkId]);

  const createMutation = useMutation({
    mutationFn: (p: DealPayload) => dealsApi.create(p),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['deals'] }); setSaveError(''); setModal(undefined); },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      setSaveError(msg || 'Failed to create deal. Please try again.');
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, p }: { id: number; p: Partial<DealPayload> }) => dealsApi.update(id, p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['deals'] });
      qc.invalidateQueries({ queryKey: ['leads'] });
      qc.invalidateQueries({ queryKey: ['pipelines'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      qc.invalidateQueries({ queryKey: ['reports'] });
      setSaveError('');
      setModal(undefined);
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      setSaveError(msg || 'Failed to save deal. Please try again.');
    },
  });

  // Optimistic delete
  const deleteMutation = useMutation({
    mutationFn: (id: number) => dealsApi.delete(id),
    onMutate: async (id) => {
      await qc.cancelQueries({ queryKey: ['deals'] });
      const snapshot = qc.getQueriesData<PaginatedResponse<Deal>>({ queryKey: ['deals'] });
      qc.setQueriesData<PaginatedResponse<Deal>>({ queryKey: ['deals'] }, (old) => {
        if (!old) return old;
        return { ...old, data: old.data.filter((d) => d.id !== id) };
      });
      return { snapshot };
    },
    onError: (_err, _id, ctx) => {
      if (ctx?.snapshot) {
        ctx.snapshot.forEach(([key, data]) => qc.setQueryData(key, data));
      }
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ['deals'] }),
  });

  const addPaymentMutation = useMutation({
    mutationFn: ({ dealId, payload }: {
      dealId: number;
      payload: Parameters<typeof dealsApi.addPayment>[1];
    }) => dealsApi.addPayment(dealId, payload),
    onSuccess: (result, { dealId }) => {
      qc.invalidateQueries({ queryKey: ['deals'] });
      qc.invalidateQueries({ queryKey: ['deal-payments', dealId] });
      qc.invalidateQueries({ queryKey: ['sales-targets'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      setAddPaymentModal(null);
      if (result.deal_marked_won) {
        alert(result.message);
      }
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      alert(msg || 'Failed to record payment. Please try again.');
    },
  });

  const deals = data?.data ?? [];
  const meta  = data?.meta;
  const isSaving = createMutation.isPending || updateMutation.isPending;

  const inrTotal = deals
    .filter((d) => normalizeDealCurrency(d.currency) === 'INR')
    .reduce((sum, d) => sum + (Number(d.value) || 0), 0);
  const usdTotal = deals
    .filter((d) => normalizeDealCurrency(d.currency) === 'USD')
    .reduce((sum, d) => sum + (Number(d.value) || 0), 0);
  const totalValueParts = [
    inrTotal > 0 ? formatDealMoney(inrTotal, 'INR') : null,
    usdTotal > 0 ? formatDealMoney(usdTotal, 'USD') : null,
  ].filter(Boolean);

  // ── Free plan usage limits ────────────────────────────────────────────────
  const planLimits  = getLimits(user?.subscription?.plan);
  const totalDeals  = meta?.total ?? 0;
  const atDealLimit = !isPaidPlan() && totalDeals >= planLimits.deals;

  return (
    <div className="space-y-4 sm:space-y-5">
      {/* Header */}
      <div className="flex flex-col items-stretch justify-between gap-4 sm:flex-row sm:items-center">
        <div className="min-w-0">
          <h1 className="text-xl font-bold text-gray-900">Deals</h1>
          {meta && (
            <p className="text-xs text-gray-400 mt-0.5">
              {meta.total} deals{totalValueParts.length > 0 && ` · ${totalValueParts.join(' · ')} total value`}
              {viewMine ? ' · assigned to you' : ''}
            </p>
          )}
          {!isPaidPlan() && meta && (
            <div className="mt-2">
              <PlanLimitBar used={totalDeals} limit={planLimits.deals} label="Deals" />
            </div>
          )}
        </div>
        <div className="flex w-full items-center gap-2 sm:w-auto">
          {/* My Deals / All Deals toggle — managers only */}
          {isManager && (
            <div className="flex flex-1 items-center gap-1 rounded-xl bg-gray-100 p-1 sm:flex-none">
              <button
                onClick={() => { setViewMine(true); setPage(1); }}
                className={`flex-1 rounded-lg px-3 py-2 text-xs font-semibold transition-all sm:flex-none sm:py-1.5 ${
                  viewMine ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'
                }`}
              >
                My Deals
              </button>
              <button
                onClick={() => { setViewMine(false); setPage(1); }}
                className={`flex-1 rounded-lg px-3 py-2 text-xs font-semibold transition-all sm:flex-none sm:py-1.5 ${
                  !viewMine ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'
                }`}
              >
                All Deals
              </button>
            </div>
          )}
          {isManager && (
            <button
              onClick={() => { if (!atDealLimit) { setSaveError(''); setModal(null); } }}
              disabled={atDealLimit}
              title={atDealLimit ? `You've reached the ${planLimits.deals}-deal limit on the Free plan. Upgrade to add more.` : undefined}
              className={`flex min-h-10 flex-1 items-center justify-center gap-1.5 text-white text-sm font-semibold sm:flex-none
                         px-4 py-2 rounded-xl transition-colors shadow-sm
                         ${atDealLimit
                           ? 'bg-gray-300 cursor-not-allowed shadow-none'
                           : 'bg-indigo-600 hover:bg-indigo-700 shadow-indigo-500/20'}`}
            >
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
              </svg>
              Add Deal
            </button>
          )}
        </div>
      </div>

      {/* Status filters + search */}
      <div className="flex flex-col items-stretch gap-3 sm:flex-row sm:flex-wrap sm:items-center">
        <div className="relative w-full sm:w-auto">
          <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
          </svg>
          <input
            type="search"
            placeholder="Search deals…"
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
            className="w-full rounded-xl border border-gray-200 bg-white py-2.5 pl-9 pr-4 text-sm transition-shadow focus:outline-none focus:ring-2 focus:ring-indigo-500 sm:w-64"
          />
        </div>
        <div className="flex max-w-full gap-1 overflow-x-auto rounded-xl bg-gray-100 p-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
          {STATUS_PILLS.map((s) => (
            <button
              key={s.value}
              onClick={() => { setStatusFilter(s.value); setPage(1); }}
              className={`min-h-9 shrink-0 rounded-lg px-3 py-1.5 text-xs font-semibold transition-all duration-150 ${
                statusFilter === s.value
                  ? 'bg-white text-gray-900 shadow-sm'
                  : 'text-gray-500 hover:text-gray-700'
              }`}
            >
              {s.label}
            </button>
          ))}
        </div>

        {isFetching && !isLoading && (
          <span className="text-xs text-gray-400 flex items-center gap-1.5">
            <svg className="w-3 h-3 animate-spin text-indigo-400" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            Updating…
          </span>
        )}
      </div>

      {/* Responsive deal list */}
      <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        {isLoading ? (
          <>
            <div className="divide-y divide-gray-100 md:hidden">
              {Array.from({ length: 4 }).map((_, index) => (
                <div key={index} className="space-y-4 p-4 animate-pulse">
                  <div className="flex items-start justify-between gap-3">
                    <div className="space-y-2">
                      <div className="h-4 w-44 rounded bg-gray-100" />
                      <div className="h-3 w-28 rounded bg-gray-100" />
                    </div>
                    <div className="h-6 w-14 rounded-full bg-gray-100" />
                  </div>
                  <div className="grid grid-cols-2 gap-3">
                    <div className="h-12 rounded-xl bg-gray-50" />
                    <div className="h-12 rounded-xl bg-gray-50" />
                  </div>
                  <div className="h-10 rounded-xl bg-gray-100" />
                </div>
              ))}
            </div>
            <div className="hidden md:block">
              <SkeletonTable rows={6} cols={8} />
            </div>
          </>
        ) : deals.length === 0 ? (
          <div className="px-6 py-16 text-center">
            <div className="w-14 h-14 bg-emerald-50 rounded-2xl flex items-center justify-center mx-auto mb-4">
              <svg className="w-7 h-7 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                  d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            {search || statusFilter ? (
              <>
                <p className="text-gray-900 font-semibold text-sm mb-1">No deals match your filters</p>
                <p className="text-xs text-gray-400 mb-4">Try a different search or status filter</p>
                <button
                  onClick={() => { setSearch(''); setStatusFilter(''); setPage(1); }}
                  className="text-sm text-indigo-600 hover:underline"
                >
                  Clear filters
                </button>
              </>
            ) : (
              <>
                <p className="text-gray-900 font-semibold text-sm mb-1">No deals yet</p>
                <p className="text-xs text-gray-400 mb-4">Start tracking your sales opportunities</p>
                <button
                  onClick={() => setModal(null)}
                  className="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2 rounded-xl transition-colors"
                >
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                  </svg>
                  Create First Deal
                </button>
              </>
            )}
          </div>
        ) : (
          <>
            {/* Mobile cards keep every deal field and action reachable without horizontal scrolling. */}
            <div className="divide-y divide-gray-100 md:hidden">
              <AnimatePresence initial={false}>
                {deals.map((deal, i) => {
                  const pastDue = Boolean(
                    deal.expected_close_date
                    && deal.status === 'open'
                    && new Date(deal.expected_close_date) < new Date(),
                  );
                  const remaining = dealRemaining(deal);
                  const displayDate = deal.status !== 'open' && deal.closed_at
                    ? deal.closed_at
                    : deal.expected_close_date;

                  return (
                    <motion.article
                      key={deal.id}
                      initial={{ opacity: 0, y: 6 }}
                      animate={{ opacity: 1, y: 0 }}
                      exit={{ opacity: 0, x: -20 }}
                      transition={{ delay: i * 0.03, type: 'spring', damping: 30, stiffness: 400 }}
                      className="p-4"
                    >
                      <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                          <h2 className="break-words text-sm font-semibold leading-5 text-gray-900">{deal.title}</h2>
                          {deal.lead && (
                            <p className="mt-1 truncate text-xs text-gray-400">{deal.lead.full_name}</p>
                          )}
                        </div>
                        <div className="shrink-0"><Badge value={deal.status} /></div>
                      </div>

                      <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-3">
                        <div className="min-w-0">
                          <dt className="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Value</dt>
                          <dd className="mt-1 break-words text-sm font-semibold text-gray-800">
                            {deal.value ? formatDealMoney(deal.value, deal.currency) : '—'}
                          </dd>
                          <dd className="mt-0.5 text-[11px] text-gray-400">
                            {currencyLabel(deal.currency)}
                            {deal.probability != null ? ` · ${deal.probability}% prob.` : ''}
                          </dd>
                        </div>
                        <div className="min-w-0">
                          <dt className="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Stage</dt>
                          <dd className="mt-1 break-words text-sm text-gray-700">{deal.stage?.name ?? '—'}</dd>
                        </div>
                        <div className="min-w-0">
                          <dt className="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Close date</dt>
                          <dd className={`mt-1 text-sm ${pastDue ? 'font-medium text-red-500' : displayDate ? 'text-gray-700' : 'text-gray-400'}`}>
                            {displayDate ? fmtDate(displayDate) : '—'}
                          </dd>
                        </div>
                        <div className="min-w-0">
                          <dt className="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Remaining</dt>
                          <dd className={`mt-1 break-words text-sm font-semibold ${
                            remaining === 0 ? 'text-emerald-600' : remaining == null ? 'text-gray-400' : 'text-amber-700'
                          }`}>
                            {remaining == null ? '—' : formatDealMoney(remaining, deal.currency)}
                          </dd>
                        </div>
                      </dl>

                      <div className="mt-4 flex items-center justify-between gap-3 rounded-xl bg-gray-50 px-3 py-2.5">
                        <div className="min-w-0">
                          <p className="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Received</p>
                          <p className={`mt-0.5 break-words text-sm font-semibold ${
                            deal.total_received != null && deal.total_received > 0 ? 'text-emerald-700' : 'text-gray-400'
                          }`}>
                            {deal.total_received != null && deal.total_received > 0
                              ? formatDealMoney(deal.total_received, deal.currency)
                              : deal.status === 'open' ? 'Not entered' : '—'}
                          </p>
                        </div>
                        {(deal.payments_count ?? 0) > 0 || deal.status === 'open' ? (
                          <button
                            onClick={() => setTxnsModal(deal)}
                            className="min-h-10 shrink-0 rounded-lg px-2 text-xs font-semibold text-indigo-600 transition-colors hover:bg-indigo-50"
                          >
                            Transactions ({deal.payments_count ?? 0})
                          </button>
                        ) : null}
                      </div>

                      <div className="mt-4 grid grid-cols-2 gap-2">
                        {deal.client_id && (
                          <Link
                            href={`/leads?new=1&client_id=${deal.client_id}`}
                            className="flex min-h-10 items-center justify-center rounded-xl border border-gray-200 px-3 text-xs font-semibold text-violet-600 transition-colors hover:bg-violet-50"
                          >
                            + Opportunity
                          </Link>
                        )}
                        {deal.status === 'open' && (
                          <button
                            onClick={() => setAddPaymentModal(deal)}
                            className="min-h-10 rounded-xl border border-emerald-200 px-3 text-xs font-semibold text-emerald-700 transition-colors hover:bg-emerald-50"
                          >
                            Add payment
                          </button>
                        )}
                        <button
                          onClick={() => { setSaveError(''); setModal(deal); }}
                          className="min-h-10 rounded-xl border border-indigo-200 px-3 text-xs font-semibold text-indigo-600 transition-colors hover:bg-indigo-50"
                        >
                          Edit deal
                        </button>
                        {isManager && (
                          <button
                            onClick={() => { if (confirm('Delete this deal?')) deleteMutation.mutate(deal.id); }}
                            className="min-h-10 rounded-xl border border-red-100 px-3 text-xs font-semibold text-red-500 transition-colors hover:bg-red-50"
                          >
                            Delete
                          </button>
                        )}
                      </div>
                    </motion.article>
                  );
                })}
              </AnimatePresence>
            </div>

            {/* Existing desktop/tablet presentation, now scroll-safe at intermediate widths. */}
            <div className="hidden overflow-x-auto md:block">
              <table className="min-w-[1100px] w-full divide-y divide-gray-100">
            <thead className="bg-gray-50/80">
              <tr>
                {['Deal', 'Value', 'Stage', 'Status', 'Close Date', 'Received', 'Remaining', ''].map((h) => (
                  <th key={h} className="px-5 py-3 text-left text-[11px] font-semibold text-gray-500 uppercase tracking-wider">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              <AnimatePresence initial={false}>
                {deals.map((deal, i) => {
                  const pastDue = deal.expected_close_date && deal.status === 'open' && new Date(deal.expected_close_date) < new Date();
                  return (
                    <motion.tr
                      key={deal.id}
                      initial={{ opacity: 0, y: 6 }}
                      animate={{ opacity: 1, y: 0 }}
                      exit={{ opacity: 0, x: -20 }}
                      transition={{ delay: i * 0.03, type: 'spring', damping: 30, stiffness: 400 }}
                      className="group hover:bg-gray-50/60 transition-colors"
                    >
                      <td className="px-5 py-3.5">
                        <p className="text-sm font-semibold text-gray-900">{deal.title}</p>
                        {deal.lead && <p className="text-xs text-gray-400 mt-0.5">{deal.lead.full_name}</p>}
                      </td>
                      <td className="px-5 py-3.5">
                        <span className="text-sm font-semibold text-gray-800">
                          {deal.value ? formatDealMoney(deal.value, deal.currency) : '—'}
                        </span>
                        <p className="text-[11px] text-gray-400 mt-0.5">{currencyLabel(deal.currency)}</p>
                        {deal.probability != null && (
                          <p className="text-xs text-gray-400 mt-0.5">{deal.probability}% prob.</p>
                        )}
                      </td>
                      <td className="px-5 py-3.5">
                        <span className="text-sm text-gray-600">{deal.stage?.name ?? '—'}</span>
                      </td>
                      <td className="px-5 py-3.5">
                        <Badge value={deal.status} />
                      </td>
                      <td className="px-5 py-3.5">
                        <span className={`text-sm ${
                          pastDue ? 'text-red-500 font-medium'
                            : deal.closed_at ? 'text-gray-700 font-medium'
                            : 'text-gray-400'
                        }`}>
                          {deal.status !== 'open' && deal.closed_at
                            ? fmtDate(deal.closed_at)
                            : deal.expected_close_date
                              ? fmtDate(deal.expected_close_date)
                              : '—'}
                        </span>
                      </td>
                      {/* ── Received column ── */}
                      <td className="px-5 py-3.5">
                        <div className="space-y-0.5">
                          {deal.total_received != null && deal.total_received > 0 ? (
                            <span className="text-sm font-semibold text-emerald-700">
                              {formatDealMoney(deal.total_received, deal.currency)}
                            </span>
                          ) : (
                            <span className="text-xs text-gray-400">
                              {deal.status === 'open' ? 'Not entered' : '—'}
                            </span>
                          )}
                          {(deal.payments_count ?? 0) > 0 || deal.status === 'open' ? (
                            <button
                              onClick={() => setTxnsModal(deal)}
                              className="block text-xs text-indigo-500 hover:text-indigo-700 hover:underline transition-colors"
                            >
                              View Transactions ({deal.payments_count ?? 0})
                            </button>
                          ) : null}
                        </div>
                      </td>
                      {/* ── Remaining column ── */}
                      <td className="px-5 py-3.5">
                        {(() => {
                          const remaining = dealRemaining(deal);
                          if (remaining == null) {
                            return <span className="text-xs text-gray-400">—</span>;
                          }
                          return (
                            <span className={`text-sm font-semibold ${
                              remaining === 0 ? 'text-emerald-600' : 'text-amber-700'
                            }`}>
                              {formatDealMoney(remaining, deal.currency)}
                            </span>
                          );
                        })()}
                      </td>
                      <td className="px-5 py-3.5">
                        <div className="flex items-center justify-end gap-1">
                          {deal.client_id && <Link href={`/leads?new=1&client_id=${deal.client_id}`} title="New opportunity for this client" className="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-violet-600 hover:bg-violet-50 transition-colors text-lg">+</Link>}
                          {/* Add Payment — open deals only */}
                          {deal.status === 'open' && (
                            <button
                              onClick={() => setAddPaymentModal(deal)}
                              className="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-emerald-600 hover:bg-emerald-50 transition-colors"
                              title="Add payment"
                            >
                              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                  d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                              </svg>
                            </button>
                          )}
                          {/* Edit — all users can update deal status/stage */}
                          <button
                            onClick={() => { setSaveError(''); setModal(deal); }}
                            className="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors"
                            title="Edit"
                          >
                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                          </button>
                          {/* Delete — managers only */}
                          {isManager && (
                            <button
                              onClick={() => { if (confirm('Delete this deal?')) deleteMutation.mutate(deal.id); }}
                              className="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-red-500 hover:bg-red-50 transition-colors"
                              title="Delete"
                            >
                              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                  d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                              </svg>
                            </button>
                          )}
                        </div>
                      </td>
                    </motion.tr>
                  );
                })}
              </AnimatePresence>
            </tbody>
              </table>
            </div>
          </>
        )}

        {/* Pagination */}
        {meta && meta.last_page > 1 && (
          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 px-4 py-4 text-sm text-gray-500 sm:px-5">
            <span className="text-xs">
              Showing {(meta.current_page - 1) * (meta.per_page ?? 15) + 1}–
              {Math.min(meta.current_page * (meta.per_page ?? 15), meta.total)} of {meta.total}
            </span>
            <div className="flex gap-2">
              <button
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page === 1}
                className="min-h-10 px-3 py-1.5 border border-gray-200 rounded-xl text-xs disabled:opacity-40 hover:bg-gray-50 transition-colors"
              >
                ← Prev
              </button>
              <button
                onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                disabled={page === meta.last_page}
                className="min-h-10 px-3 py-1.5 border border-gray-200 rounded-xl text-xs disabled:opacity-40 hover:bg-gray-50 transition-colors"
              >
                Next →
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Edit/Create Deal Modal */}
      <Modal
        open={modal !== undefined}
        onClose={() => { setModal(undefined); setSaveError(''); }}
        title={modal?.id ? 'Edit Deal' : 'New Deal'}
        maxWidth="max-w-lg"
      >
        {modal !== undefined && (
          <>
            {saveError && (
              <div className="mb-4 px-4 py-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-600">
                {saveError}
              </div>
            )}
            <DealForm
              deal={modal}
              onClose={() => { setModal(undefined); setSaveError(''); }}
              onSave={(p) => modal?.id ? updateMutation.mutate({ id: modal.id, p }) : createMutation.mutate(p)}
              saving={isSaving}
            />
          </>
        )}
      </Modal>

      {/* Add Payment Modal */}
      <Modal
        open={addPaymentModal !== null}
        onClose={() => setAddPaymentModal(null)}
        title="Add Payment"
        maxWidth="max-w-lg"
      >
        {addPaymentModal && (
          <AddPaymentModal
            deal={addPaymentModal}
            onClose={() => setAddPaymentModal(null)}
            onSave={(payload) => addPaymentMutation.mutate({ dealId: addPaymentModal.id, payload })}
            saving={addPaymentMutation.isPending}
          />
        )}
      </Modal>

      {/* Transaction History Modal */}
      <Modal
        open={txnsModal !== null}
        onClose={() => setTxnsModal(null)}
        title="Payment Transactions"
        maxWidth="max-w-2xl"
      >
        {txnsModal && (
          <TransactionsModal
            deal={txnsModal}
            onAddPayment={() => {
              setTxnsModal(null);
              setAddPaymentModal(txnsModal);
            }}
            onViewReceipt={(payment) => {
              setReceiptModal({ payment, deal: txnsModal });
              setTxnsModal(null);
            }}
          />
        )}
      </Modal>

      {/* Money Receipt Modal */}
      <Modal
        open={receiptModal !== null}
        onClose={() => setReceiptModal(null)}
        title="Money Receipt"
        maxWidth="max-w-3xl"
      >
        {receiptModal && (
          <MoneyReceipt
            payment={receiptModal.payment}
            deal={receiptModal.deal}
            settings={receiptSettings ?? {}}
            onClose={() => setReceiptModal(null)}
          />
        )}
      </Modal>
    </div>
  );
}
