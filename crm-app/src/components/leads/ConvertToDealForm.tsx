'use client';

import { useState } from 'react';
import type { ConvertPayload } from '@/lib/api/leads';
import type { Lead, Pipeline } from '@/types';

const inputCls =
  'w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-shadow bg-white';

function todayIso(): string {
  return new Date().toISOString().slice(0, 10);
}

interface Props {
  leadName: string;
  pipelines: Pipeline[] | undefined;
  onSave: (payload: ConvertPayload) => void;
  onClose: () => void;
  saving?: boolean;
}

export function ConvertToDealForm({ leadName, pipelines, onSave, onClose, saving }: Props) {
  const [selectedPipelineId, setSelectedPipelineId] = useState<number>(0);
  const [form, setForm] = useState<ConvertPayload>({
    pipeline_id: 0,
    stage_id: 0,
    title: `Deal — ${leadName}`,
    value: undefined,
  });
  const [paymentAmount, setPaymentAmount] = useState<number | ''>('');
  const [paymentDate, setPaymentDate] = useState(todayIso);
  const [paymentMode, setPaymentMode] = useState<NonNullable<ConvertPayload['payment']>['payment_mode']>('upi');

  const pipelineId = selectedPipelineId || pipelines?.[0]?.id || 0;
  const stages = pipelines?.find((p) => p.id === pipelineId)?.stages ?? [];

  const handleSubmit = () => {
    const payload: ConvertPayload = {
      ...form,
      pipeline_id: pipelineId,
      stage_id: form.stage_id,
    };

    if (paymentAmount && Number(paymentAmount) > 0) {
      payload.payment = {
        amount: Number(paymentAmount),
        payment_date: paymentDate,
        payment_mode: paymentMode,
      };
    }

    onSave(payload);
  };

  const dealValue = Number(form.value ?? 0);
  const received = paymentAmount ? Number(paymentAmount) : 0;
  const willAutoWin = dealValue > 0 && received >= dealValue;

  return (
    <div className="space-y-4">
      <div className="bg-indigo-50 rounded-xl p-3 text-sm text-indigo-700">
        Converting <strong>{leadName}</strong> to a deal.
        {willAutoWin && (
          <p className="mt-1 text-emerald-700 font-medium">
            Full payment recorded — deal will be marked as Won.
          </p>
        )}
      </div>

      <div>
        <label className="block text-xs font-semibold text-gray-600 mb-1">Deal Title</label>
        <input
          value={form.title ?? ''}
          onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))}
          className={inputCls}
        />
      </div>

      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1">Pipeline</label>
          <select
            value={pipelineId}
            onChange={(e) => {
              const id = Number(e.target.value);
              setSelectedPipelineId(id);
              setForm((f) => ({ ...f, pipeline_id: id, stage_id: 0 }));
            }}
            className={inputCls}
          >
            {pipelines?.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1">Stage *</label>
          <select
            value={form.stage_id}
            onChange={(e) => setForm((f) => ({ ...f, stage_id: Number(e.target.value) }))}
            className={inputCls}
          >
            <option value={0}>Select stage…</option>
            {stages.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name}
              </option>
            ))}
          </select>
        </div>
      </div>

      <div>
        <label className="block text-xs font-semibold text-gray-600 mb-1">Deal Value (₹)</label>
        <input
          type="number"
          min={0}
          value={form.value ?? ''}
          onChange={(e) =>
            setForm((f) => ({ ...f, value: e.target.value ? Number(e.target.value) : undefined }))
          }
          placeholder="0"
          className={inputCls}
        />
      </div>

      <div className="border border-gray-100 rounded-xl p-3 space-y-3 bg-gray-50/50">
        <p className="text-xs font-semibold text-gray-600">Initial Payment (optional)</p>
        <div>
          <label className="block text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">
            Amount Received (₹)
          </label>
          <input
            type="number"
            min={0}
            step="0.01"
            value={paymentAmount}
            onChange={(e) => setPaymentAmount(e.target.value ? Number(e.target.value) : '')}
            placeholder="0"
            className={inputCls}
          />
        </div>
        {paymentAmount !== '' && Number(paymentAmount) > 0 && (
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">
                Payment Date
              </label>
              <input
                type="date"
                value={paymentDate}
                onChange={(e) => setPaymentDate(e.target.value)}
                className={inputCls}
              />
            </div>
            <div>
              <label className="block text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">
                Payment Mode
              </label>
              <select
                value={paymentMode}
                onChange={(e) =>
                  setPaymentMode(e.target.value as NonNullable<ConvertPayload['payment']>['payment_mode'])
                }
                className={inputCls}
              >
                <option value="upi">UPI</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="cash">Cash</option>
                <option value="cheque">Cheque</option>
                <option value="card">Card</option>
                <option value="other">Other</option>
              </select>
            </div>
          </div>
        )}
      </div>

      <div className="flex gap-3 pt-1">
        <button
          onClick={handleSubmit}
          disabled={saving || !form.stage_id}
          className="flex-1 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-60 text-white font-semibold py-2.5 rounded-xl text-sm flex items-center justify-center gap-2 transition-colors"
        >
          {saving && (
            <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
          )}
          Convert to Deal
        </button>
        <button
          onClick={onClose}
          className="px-5 py-2.5 text-sm text-gray-500 hover:text-gray-700 border border-gray-200 rounded-xl transition-colors"
        >
          Cancel
        </button>
      </div>
    </div>
  );
}

/** @deprecated use leadName prop directly */
export function ConvertToDealFormFromLead({
  lead,
  ...props
}: Omit<Props, 'leadName'> & { lead: Lead }) {
  return <ConvertToDealForm {...props} leadName={lead.full_name} />;
}
