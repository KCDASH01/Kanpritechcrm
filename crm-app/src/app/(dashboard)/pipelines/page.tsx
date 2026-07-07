'use client';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { motion, AnimatePresence } from 'framer-motion';
import { pipelinesApi } from '@/lib/api/pipelines';
import { dealsApi } from '@/lib/api/deals';
import { useAuthStore } from '@/store/authStore';
import { getLimits } from '@/lib/planLimits';
import { PlanLimitBar } from '@/components/ui/PlanLimitBar';
import { Badge } from '@/components/ui/Badge';
import type { Pipeline, Stage, Deal } from '@/types';

// ── Negotiation Popup ─────────────────────────────────────────────────────────
function NegotiationPopup({
  deal,
  onClose,
}: {
  deal: Deal;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const [originalValue,     setOriginalValue]     = useState<string>(
    deal.original_value != null ? String(deal.original_value) : deal.value != null ? String(deal.value) : ''
  );
  const [counterOfferValue, setCounterOfferValue] = useState<string>(deal.counter_offer_value != null ? String(deal.counter_offer_value) : '');
  const [negotiationNotes,  setNegotiationNotes]  = useState<string>(deal.negotiation_notes  ?? '');
  const [error, setError] = useState('');

  const orig    = originalValue     !== '' ? Number(originalValue)     : null;
  const counter = counterOfferValue !== '' ? Number(counterOfferValue) : null;
  const diff    = orig != null && counter != null ? counter - orig : null;
  const pct     = diff != null && orig ? ((diff / orig) * 100).toFixed(1) : null;

  const finalValue = counter ?? (deal.value != null ? Number(deal.value) : undefined);

  const saveMutation = useMutation({
    mutationFn: () =>
      dealsApi.update(deal.id, {
        // required fields the backend always expects
        title:       deal.title,
        pipeline_id: deal.pipeline_id,
        stage_id:    deal.stage_id,
        // negotiation fields — counter offer becomes the new deal value
        original_value:      orig      ?? undefined,
        counter_offer_value: counter   ?? undefined,
        negotiation_notes:   negotiationNotes.trim() || undefined,
        value:               finalValue,
      }),
    onMutate: () => {
      // Optimistically update every deals cache entry so the new value
      // is visible immediately in the Kanban, Deals list, and Dashboard
      qc.setQueriesData<{ data: Deal[] }>({ queryKey: ['deals'] }, (old) => {
        if (!old?.data) return old;
        return {
          ...old,
          data: old.data.map((d) =>
            d.id === deal.id
              ? {
                  ...d,
                  value:               finalValue ?? d.value,
                  original_value:      orig       ?? d.original_value,
                  counter_offer_value: counter    ?? d.counter_offer_value,
                  negotiation_notes:   negotiationNotes.trim() || d.negotiation_notes,
                }
              : d
          ),
        };
      });
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['deals'] });
      qc.invalidateQueries({ queryKey: ['activities'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] }); // refresh revenue totals
      qc.invalidateQueries({ queryKey: ['reports'] });
      onClose();
    },
    onError: () => {
      qc.invalidateQueries({ queryKey: ['deals'] }); // revert optimistic on error
      setError('Failed to save. Please try again.');
    },
  });

  const inputCls = 'w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white';

  return (
    <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" onClick={onClose}>
      <motion.div
        initial={{ opacity: 0, y: 16, scale: 0.97 }}
        animate={{ opacity: 1, y: 0, scale: 1 }}
        exit={{ opacity: 0, y: 16, scale: 0.97 }}
        transition={{ type: 'spring', damping: 30, stiffness: 400 }}
        className="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden"
        onClick={(e) => e.stopPropagation()}
      >
        {/* Header */}
        <div className="bg-indigo-50 px-5 py-4 flex items-start justify-between gap-3 border-b border-indigo-100">
          <div>
            <div className="flex items-center gap-2 mb-0.5">
              <svg className="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
              </svg>
              <span className="text-sm font-bold text-indigo-800">Negotiation</span>
            </div>
            <p className="text-xs text-indigo-500 truncate max-w-[220px]">{deal.title}</p>
          </div>
          <button
            onClick={onClose}
            className="w-7 h-7 rounded-lg flex items-center justify-center text-indigo-400 hover:text-indigo-700 hover:bg-indigo-100 shrink-0 transition-colors"
          >
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div className="p-5 space-y-4">
          {/* Counter-offer fields */}
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-[11px] font-semibold text-gray-500 mb-1">Your Quote (₹)</label>
              <input
                type="number"
                min={0}
                value={originalValue}
                onChange={(e) => setOriginalValue(e.target.value)}
                placeholder="60,000"
                className={inputCls}
              />
            </div>
            <div>
              <label className="block text-[11px] font-semibold text-gray-500 mb-1">Client Offer (₹)</label>
              <input
                type="number"
                min={0}
                value={counterOfferValue}
                onChange={(e) => setCounterOfferValue(e.target.value)}
                placeholder="45,000"
                className={inputCls}
              />
            </div>
          </div>

          {/* Live diff */}
          {diff != null && pct != null && (
            <div className={`flex items-center gap-2 px-3 py-2.5 rounded-xl text-xs font-semibold ${
              diff < 0 ? 'bg-red-50 text-red-600 border border-red-100' : 'bg-emerald-50 text-emerald-700 border border-emerald-100'
            }`}>
              <svg className="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d={diff < 0
                    ? 'M17 13l-5 5m0 0l-5-5m5 5V6'
                    : 'M7 11l5-5m0 0l5 5m-5-5v12'} />
              </svg>
              <span>
                {diff < 0 ? 'Client asking' : 'Client offering'}{' '}
                <strong>₹{Math.abs(diff).toLocaleString('en-IN')}</strong>{' '}
                {diff < 0 ? 'less' : 'more'} ({diff < 0 ? '' : '+'}{pct}%)
              </span>
            </div>
          )}

          {/* Negotiation notes */}
          <div>
            <label className="block text-[11px] font-semibold text-gray-500 mb-1">Notes</label>
            <textarea
              rows={4}
              value={negotiationNotes}
              onChange={(e) => setNegotiationNotes(e.target.value)}
              placeholder="e.g. Client wants 20% discount + 6-month payment plan. Legal reviewing contract by Friday."
              className={`${inputCls} resize-none`}
            />
          </div>

          {error && <p className="text-xs text-red-500">{error}</p>}

          {/* Actions */}
          <div className="flex gap-3 pt-1">
            <button
              onClick={onClose}
              className="flex-1 border border-gray-200 text-gray-600 text-sm font-medium py-2.5 rounded-xl hover:bg-gray-50 transition-colors"
            >
              Cancel
            </button>
            <button
              onClick={() => saveMutation.mutate()}
              disabled={saveMutation.isPending}
              className="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white text-sm font-semibold py-2.5 rounded-xl transition-colors flex items-center justify-center gap-1.5"
            >
              {saveMutation.isPending ? (
                <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                </svg>
              ) : 'Save'}
            </button>
          </div>
        </div>
      </motion.div>
    </div>
  );
}

// ── Hand-off Modal ─────────────────────────────────────────────────────────────
function HandoffModal({
  deal,
  currentPipelineId,
  pipelines,
  onClose,
}: {
  deal: Deal;
  currentPipelineId: number;
  pipelines: Pipeline[];
  onClose: () => void;
}) {
  const qc = useQueryClient();

  // Pipelines available to hand off to (exclude the current one)
  const targets = pipelines.filter((p) => p.id !== currentPipelineId);

  const [selectedPipelineId, setSelectedPipelineId] = useState<number>(targets[0]?.id ?? 0);
  const [selectedStageId, setSelectedStageId]       = useState<number>(0);
  const [error, setError] = useState('');

  const targetPipeline = targets.find((p) => p.id === selectedPipelineId) ?? null;
  const stages = targetPipeline
    ? [...targetPipeline.stages].sort((a, b) => a.sort_order - b.sort_order).filter((s) => !s.is_won && !s.is_lost)
    : [];

  // Reset stage when pipeline changes
  const handlePipelineChange = (pipelineId: number) => {
    setSelectedPipelineId(pipelineId);
    setSelectedStageId(0);
    setError('');
  };

  const handoffMutation = useMutation({
    mutationFn: async () => {
      if (!selectedPipelineId || !selectedStageId) throw new Error('Select a pipeline and stage.');
      // Single call — backend stamps handed_off_at on source deal atomically
      return dealsApi.create({
        title:       deal.title,
        lead_id:     deal.lead?.id,
        pipeline_id: selectedPipelineId,
        stage_id:    selectedStageId,
        assigned_to: deal.assigned_to?.id,
        value:       deal.value != null ? Number(deal.value) : undefined,
        status:      'open',
        // tells the backend to stamp handed_off_at on the source deal
        source_deal_id: deal.id,
      });
    },
    onMutate: () => {
      // Optimistically mark the source deal as handed off so the button
      // disappears the instant the user clicks "Hand Off"
      qc.setQueriesData<{ data: Deal[] }>({ queryKey: ['deals'] }, (old) => {
        if (!old?.data) return old;
        return {
          ...old,
          data: old.data.map((d) =>
            d.id === deal.id ? { ...d, handed_off_at: new Date().toISOString() } : d
          ),
        };
      });
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['deals'] });
      onClose();
    },
    onError: (e: unknown) => {
      qc.invalidateQueries({ queryKey: ['deals'] }); // revert optimistic
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
      setError(msg || 'Something went wrong.');
    },
  });

  const handleConfirm = () => {
    if (!selectedPipelineId) { setError('Select a target pipeline.'); return; }
    if (!selectedStageId)    { setError('Select a starting stage.');  return; }
    handoffMutation.mutate();
  };

  const inputCls = 'w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white';

  return (
    <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" onClick={onClose}>
      <motion.div
        initial={{ opacity: 0, y: 20, scale: 0.97 }}
        animate={{ opacity: 1, y: 0, scale: 1 }}
        exit={{ opacity: 0, y: 20, scale: 0.97 }}
        transition={{ type: 'spring', damping: 30, stiffness: 400 }}
        className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 space-y-4"
        onClick={(e) => e.stopPropagation()}
      >
        {/* Header */}
        <div>
          <div className="flex items-center gap-2 mb-1">
            <div className="w-7 h-7 rounded-lg bg-indigo-50 flex items-center justify-center">
              <svg className="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7l5 5m0 0l-5 5m5-5H6"/>
              </svg>
            </div>
            <h3 className="text-base font-bold text-gray-900">Hand off to Pipeline</h3>
          </div>
          <p className="text-xs text-gray-400 pl-9">
            Creates a new deal in the selected pipeline linked to <span className="font-semibold text-gray-600">{deal.title}</span>.
          </p>
        </div>

        {targets.length === 0 ? (
          <p className="text-sm text-gray-500 bg-gray-50 rounded-xl p-4 text-center">
            No other pipelines available. Create one first.
          </p>
        ) : (
          <>
            {/* Target pipeline */}
            <div>
              <label className="block text-xs font-semibold text-gray-600 mb-1">Target Pipeline *</label>
              <select
                value={selectedPipelineId}
                onChange={(e) => handlePipelineChange(Number(e.target.value))}
                className={inputCls}
              >
                <option value={0} disabled>Select pipeline…</option>
                {targets.map((p) => (
                  <option key={p.id} value={p.id}>{p.name}</option>
                ))}
              </select>
            </div>

            {/* Starting stage */}
            <div>
              <label className="block text-xs font-semibold text-gray-600 mb-1">Starting Stage *</label>
              <select
                value={selectedStageId}
                onChange={(e) => { setSelectedStageId(Number(e.target.value)); setError(''); }}
                className={inputCls}
                disabled={!selectedPipelineId || stages.length === 0}
              >
                <option value={0} disabled>Select stage…</option>
                {stages.map((s) => (
                  <option key={s.id} value={s.id}>{s.name}</option>
                ))}
              </select>
              {selectedPipelineId > 0 && stages.length === 0 && (
                <p className="text-xs text-amber-600 mt-1">This pipeline has no active stages yet.</p>
              )}
            </div>

            {/* Deal info summary */}
            <div className="bg-gray-50 rounded-xl px-4 py-3 space-y-1 text-xs text-gray-500">
              <div className="flex justify-between">
                <span>Deal</span>
                <span className="font-semibold text-gray-700 truncate max-w-[160px]">{deal.title}</span>
              </div>
              {deal.lead && (
                <div className="flex justify-between">
                  <span>Lead</span>
                  <span className="font-semibold text-gray-700">{deal.lead.full_name}</span>
                </div>
              )}
              {deal.value != null && (
                <div className="flex justify-between">
                  <span>Value</span>
                  <span className="font-semibold text-indigo-600">₹{Number(deal.value).toLocaleString('en-IN')}</span>
                </div>
              )}
            </div>

            {error && <p className="text-xs text-red-500">{error}</p>}
          </>
        )}

        {/* Actions */}
        <div className="flex gap-3 pt-1">
          <button onClick={onClose} className="flex-1 border border-gray-200 text-gray-600 text-sm font-medium py-2.5 rounded-xl hover:bg-gray-50 transition-colors">
            Cancel
          </button>
          {targets.length > 0 && (
            <button
              onClick={handleConfirm}
              disabled={handoffMutation.isPending || !selectedStageId}
              className="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white text-sm font-semibold py-2.5 rounded-xl transition-colors flex items-center justify-center gap-1.5"
            >
              {handoffMutation.isPending ? (
                <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                </svg>
              ) : (
                <>
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                  </svg>
                  Hand Off
                </>
              )}
            </button>
          )}
        </div>
      </motion.div>
    </div>
  );
}

// ── Helpers ───────────────────────────────────────────────────────────────────
const STAGE_COLORS = [
  '#6366f1','#3b82f6','#f59e0b','#8b5cf6','#10b981',
  '#ef4444','#ec4899','#14b8a6','#f97316','#84cc16',
];

type StageType = 'open' | 'won' | 'lost';

function stageType(stage: Pick<Stage, 'is_won' | 'is_lost'>): StageType {
  if (stage.is_won) return 'won';
  if (stage.is_lost) return 'lost';
  return 'open';
}

function stageFlags(type: StageType): { is_won: boolean; is_lost: boolean } {
  return { is_won: type === 'won', is_lost: type === 'lost' };
}

function StageTypeBadge({ type }: { type: StageType }) {
  const cls =
    type === 'won'  ? 'bg-emerald-100 text-emerald-700' :
    type === 'lost' ? 'bg-red-100 text-red-600' :
                      'bg-blue-100 text-blue-700';
  const label = type === 'won' ? 'Won' : type === 'lost' ? 'Lost' : 'Open';
  return (
    <span className={`text-[10px] font-semibold px-1.5 py-0.5 rounded-full shrink-0 ${cls}`}>
      {label}
    </span>
  );
}

function StageTypePicker({ value, onChange }: { value: StageType; onChange: (t: StageType) => void }) {
  const options: { key: StageType; label: string }[] = [
    { key: 'open', label: 'Open' },
    { key: 'won',  label: 'Won'  },
    { key: 'lost', label: 'Lost' },
  ];
  return (
    <div className="flex gap-1.5">
      {options.map(({ key, label }) => (
        <button
          key={key}
          type="button"
          onClick={() => onChange(key)}
          className={`flex-1 py-1.5 rounded-lg text-xs font-semibold border transition-all ${
            value === key
              ? key === 'won'  ? 'bg-emerald-600 text-white border-emerald-600' :
                key === 'lost' ? 'bg-red-600 text-white border-red-600' :
                                 'bg-blue-600 text-white border-blue-600'
              : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'
          }`}
        >
          {label}
        </button>
      ))}
    </div>
  );
}

function KanbanSkeleton() {
  return (
    <div className="flex gap-4 overflow-x-auto pb-4">
      {[1,2,3,4].map((i) => (
        <div key={i} className="flex-shrink-0 w-72 space-y-2">
          <div className="skeleton h-10 rounded-xl" />
          {Array.from({ length: i % 2 === 0 ? 3 : 2 }).map((_, j) => (
            <div key={j} className="skeleton h-20 rounded-xl" />
          ))}
        </div>
      ))}
    </div>
  );
}

// ── Manage Pipeline side panel ────────────────────────────────────────────────
function ManagePanel({ pipeline, onClose }: { pipeline: Pipeline; onClose: () => void }) {
  const qc = useQueryClient();
  const [pipelineName, setPipelineName] = useState(pipeline.name);
  const [editingName, setEditingName]   = useState(false);
  const [newStageName, setNewStageName] = useState('');
  const [newStageColor, setNewStageColor] = useState(STAGE_COLORS[0]);
  const [newStageType, setNewStageType] = useState<StageType>('open');
  const [editStage, setEditStage]       = useState<Stage | null>(null);
  const [editStageType, setEditStageType] = useState<StageType>('open');

  const invalidate = () => qc.invalidateQueries({ queryKey: ['pipelines'] });

  // Rename pipeline
  const renameMutation = useMutation({
    mutationFn: () => pipelinesApi.update(pipeline.id, { name: pipelineName }),
    onSuccess: () => { invalidate(); setEditingName(false); },
  });

  // Add stage
  const addStageMutation = useMutation({
    mutationFn: () => pipelinesApi.createStage(pipeline.id, {
      name: newStageName.trim(),
      color: newStageColor,
      sort_order: pipeline.stages.length,
      probability: 50,
      ...stageFlags(newStageType),
    }),
    onSuccess: () => { invalidate(); setNewStageName(''); setNewStageType('open'); },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      alert(msg ?? 'Failed to add stage.');
    },
  });

  // Update stage
  const updateStageMutation = useMutation({
    mutationFn: ({ stage, type }: { stage: Stage; type: StageType }) =>
      pipelinesApi.updateStage(pipeline.id, stage.id, {
        name: stage.name,
        color: stage.color,
        ...stageFlags(type),
      }),
    onSuccess: () => { invalidate(); setEditStage(null); },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      alert(msg ?? 'Failed to update stage.');
    },
  });

  // Delete stage
  const deleteStageMutation = useMutation({
    mutationFn: (stageId: number) => pipelinesApi.deleteStage(pipeline.id, stageId),
    onSuccess: () => invalidate(),
  });

  const stages = [...pipeline.stages].sort((a, b) => a.sort_order - b.sort_order);

  return (
    <motion.div
      initial={{ x: '100%' }}
      animate={{ x: 0 }}
      exit={{ x: '100%' }}
      transition={{ type: 'spring', damping: 30, stiffness: 350 }}
      className="fixed top-0 right-0 h-full w-[380px] bg-white shadow-2xl shadow-black/20 z-50 flex flex-col border-l border-gray-100"
    >
      {/* Header */}
      <div className="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
        <h3 className="font-bold text-gray-900 text-sm">Manage Pipeline</h3>
        <button onClick={onClose} className="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-700 hover:bg-gray-100">
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>

      <div className="flex-1 overflow-y-auto p-5 space-y-6">
        {/* Pipeline name */}
        <div>
          <p className="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-2">Pipeline Name</p>
          {editingName ? (
            <div className="flex gap-2">
              <input
                value={pipelineName}
                onChange={(e) => setPipelineName(e.target.value)}
                className="flex-1 border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                autoFocus
              />
              <button
                onClick={() => renameMutation.mutate()}
                disabled={renameMutation.isPending || !pipelineName.trim()}
                className="px-3 py-2 bg-indigo-600 text-white text-xs font-semibold rounded-xl disabled:opacity-60"
              >
                {renameMutation.isPending ? '…' : 'Save'}
              </button>
              <button onClick={() => { setPipelineName(pipeline.name); setEditingName(false); }} className="px-3 py-2 border border-gray-200 text-gray-500 text-xs rounded-xl">
                Cancel
              </button>
            </div>
          ) : (
            <div className="flex items-center justify-between">
              <span className="text-sm font-semibold text-gray-800">{pipeline.name}</span>
              <button onClick={() => setEditingName(true)} className="text-xs text-indigo-600 hover:underline">Rename</button>
            </div>
          )}
        </div>

        {/* Stages */}
        <div>
          <p className="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-3">
            Stages ({stages.length})
          </p>

          <div className="space-y-2">
            {stages.map((stage) => (
              <div key={stage.id} className="border border-gray-100 rounded-xl overflow-hidden">
                {editStage?.id === stage.id ? (
                  <div className="p-3 space-y-2">
                    <input
                      value={editStage.name}
                      onChange={(e) => setEditStage({ ...editStage, name: e.target.value })}
                      className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      autoFocus
                    />
                    <div className="flex flex-wrap gap-1.5">
                      {STAGE_COLORS.map((c) => (
                        <button
                          key={c}
                          onClick={() => setEditStage({ ...editStage, color: c })}
                          className={`w-5 h-5 rounded-full transition-transform ${editStage.color === c ? 'scale-125 ring-2 ring-offset-1 ring-gray-400' : 'hover:scale-110'}`}
                          style={{ backgroundColor: c }}
                        />
                      ))}
                    </div>
                    <div>
                      <p className="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1.5">Stage type</p>
                      <StageTypePicker value={editStageType} onChange={setEditStageType} />
                    </div>
                    <div className="flex gap-2">
                      <button
                        onClick={() => updateStageMutation.mutate({ stage: editStage, type: editStageType })}
                        disabled={updateStageMutation.isPending}
                        className="px-3 py-1 bg-indigo-600 text-white text-xs font-semibold rounded-lg disabled:opacity-60"
                      >
                        {updateStageMutation.isPending ? '…' : 'Save'}
                      </button>
                      <button onClick={() => setEditStage(null)} className="px-3 py-1 border border-gray-200 text-gray-500 text-xs rounded-lg">Cancel</button>
                    </div>
                  </div>
                ) : (
                  <div className="flex items-center gap-3 px-3 py-2.5">
                    <div className="w-3 h-3 rounded-full shrink-0" style={{ backgroundColor: stage.color }} />
                    <span className="text-sm text-gray-800 flex-1">{stage.name}</span>
                    <StageTypeBadge type={stageType(stage)} />
                    <div className="flex items-center gap-1 shrink-0">
                      <button
                        onClick={() => { setEditStage(stage); setEditStageType(stageType(stage)); }}
                        className="w-6 h-6 rounded-lg flex items-center justify-center text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors"
                      >
                        <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                      </button>
                      <button
                        onClick={() => { if (confirm(`Delete stage "${stage.name}"?`)) deleteStageMutation.mutate(stage.id); }}
                        className="w-6 h-6 rounded-lg flex items-center justify-center text-gray-400 hover:text-red-500 hover:bg-red-50 transition-colors"
                      >
                        <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                      </button>
                    </div>
                  </div>
                )}
              </div>
            ))}
          </div>

          {/* Add new stage */}
          <div className="mt-3 border border-dashed border-gray-200 rounded-xl p-3 space-y-2">
            <p className="text-xs font-semibold text-gray-500">Add New Stage</p>
            <input
              value={newStageName}
              onChange={(e) => setNewStageName(e.target.value)}
              placeholder="Stage name (e.g. Demo Scheduled)"
              className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
              onKeyDown={(e) => e.key === 'Enter' && newStageName.trim() && addStageMutation.mutate()}
            />
            <div className="flex flex-wrap gap-1.5">
              {STAGE_COLORS.map((c) => (
                <button
                  key={c}
                  onClick={() => setNewStageColor(c)}
                  className={`w-5 h-5 rounded-full transition-transform ${newStageColor === c ? 'scale-125 ring-2 ring-offset-1 ring-gray-400' : 'hover:scale-110'}`}
                  style={{ backgroundColor: c }}
                />
              ))}
            </div>
            <div>
              <p className="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1.5">Stage type</p>
              <StageTypePicker value={newStageType} onChange={setNewStageType} />
            </div>
            <button
              onClick={() => addStageMutation.mutate()}
              disabled={addStageMutation.isPending || !newStageName.trim()}
              className="w-full py-1.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white text-xs font-semibold rounded-lg transition-colors"
            >
              {addStageMutation.isPending ? 'Adding…' : '+ Add Stage'}
            </button>
          </div>
        </div>
      </div>
    </motion.div>
  );
}

// ── New pipeline modal ────────────────────────────────────────────────────────
function NewPipelineModal({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient();
  const [name, setName] = useState('');

  const createMutation = useMutation({
    mutationFn: () => pipelinesApi.create({ name: name.trim() }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['pipelines'] }); onClose(); },
  });

  return (
    <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" onClick={onClose}>
      <motion.div
        initial={{ opacity: 0, y: 20, scale: 0.97 }}
        animate={{ opacity: 1, y: 0, scale: 1 }}
        exit={{ opacity: 0, y: 20, scale: 0.97 }}
        transition={{ type: 'spring', damping: 30, stiffness: 400 }}
        className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 space-y-4"
        onClick={(e) => e.stopPropagation()}
      >
        <h3 className="text-base font-bold text-gray-900">New Pipeline</h3>
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1">Pipeline Name *</label>
          <input
            autoFocus
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="e.g. Enterprise Sales"
            onKeyDown={(e) => e.key === 'Enter' && name.trim() && createMutation.mutate()}
            className="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />
        </div>
        <div className="flex gap-3">
          <button
            onClick={() => createMutation.mutate()}
            disabled={createMutation.isPending || !name.trim()}
            className="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white font-semibold py-2.5 rounded-xl text-sm transition-colors"
          >
            {createMutation.isPending ? 'Creating…' : 'Create Pipeline'}
          </button>
          <button onClick={onClose} className="px-4 text-sm text-gray-500 border border-gray-200 rounded-xl hover:bg-gray-50">
            Cancel
          </button>
        </div>
      </motion.div>
    </div>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────
export default function PipelinesPage() {
  const qc = useQueryClient();
  const { user, isOwner, isAdmin, isPaidPlan } = useAuthStore();
  const canManage = isOwner() || isAdmin();

  const [activePipelineId, setActivePipelineId] = useState<number | null>(null);
  const [dragOverStageId, setDragOverStageId]   = useState<number | null>(null);
  const [showManage, setShowManage]             = useState(false);
  const [showNewPipeline, setShowNewPipeline]   = useState(false);
  const [handoffDeal, setHandoffDeal]           = useState<Deal | null>(null);
  const [negotiationDeal, setNegotiationDeal]   = useState<Deal | null>(null);

  const { data: pipelines, isLoading: loadingPipelines } = useQuery({
    queryKey: ['pipelines'],
    queryFn: pipelinesApi.list,
  });

  // All users see all pipelines — pipeline is an org-level structure
  // Team members see deals filtered to only their assigned ones (in the Kanban)
  const visiblePipelines = pipelines ?? [];

  // ── Free plan usage limits ────────────────────────────────────────────────
  const planLimits      = getLimits(user?.subscription?.plan);
  const pipelineCount   = visiblePipelines.length;
  const atPipelineLimit = !isPaidPlan() && pipelineCount >= planLimits.pipelines;

  const activePipeline: Pipeline | null =
    visiblePipelines.find((p) => p.id === activePipelineId) ?? visiblePipelines[0] ?? null;

  const { data: dealsData, isLoading: loadingDeals } = useQuery({
    queryKey: ['deals', { pipeline_id: activePipeline?.id, assigned_to: canManage ? undefined : user?.id }],
    queryFn: () => dealsApi.list({
      pipeline_id:  activePipeline?.id,
      assigned_to:  canManage ? undefined : user?.id,
      per_page: 100,
    }),
    enabled: !!activePipeline?.id,
  });

  const activeDealsKey = ['deals', { pipeline_id: activePipeline?.id, assigned_to: canManage ? undefined : user?.id }];

  const moveStageMutation = useMutation({
    mutationFn: ({ dealId, stageId }: { dealId: number; stageId: number }) =>
      dealsApi.moveStage(dealId, stageId),
    onMutate: async ({ dealId, stageId }) => {
      await qc.cancelQueries({ queryKey: activeDealsKey });
      const snapshot = qc.getQueryData(activeDealsKey);
      qc.setQueryData(activeDealsKey, (old: { data: Deal[] } | undefined) => {
        if (!old) return old;
        return { ...old, data: old.data.map((d: Deal) => d.id === dealId ? { ...d, stage_id: stageId } : d) };
      });
      return { snapshot };
    },
    onError: (_err, _vars, ctx) => {
      if (ctx?.snapshot) qc.setQueryData(activeDealsKey, ctx.snapshot);
    },
    onSettled: () => {
      qc.invalidateQueries({ queryKey: ['deals'] });
      qc.invalidateQueries({ queryKey: ['leads'] });   // lost stage → lead lost cascade
    },
  });

  const deals = dealsData?.data ?? [];

  // Group deals by stage. For won/lost stages, use deal.status as the source of truth
  // so that any stale stage_id (e.g. a deal marked "lost" still pointing at a "won" stage)
  // is always rendered in the correct column.
  const dealsByStage = (stage: Stage) => deals.filter((d) => {
    if (d.status === 'lost') return stage.is_lost === true;
    if (d.status === 'won')  return stage.is_won  === true;
    return d.stage_id === stage.id;   // open deals: match by stage_id
  });

  const handleDrop = (e: React.DragEvent, stageId: number) => {
    e.preventDefault();
    setDragOverStageId(null);
    const dealId = Number(e.dataTransfer.getData('dealId'));
    const currentStageId = Number(e.dataTransfer.getData('stageId'));
    if (dealId && stageId !== currentStageId) moveStageMutation.mutate({ dealId, stageId });
  };

  if (loadingPipelines) {
    return (
      <div className="space-y-5">
        <div className="skeleton h-7 w-48 rounded-xl" />
        <KanbanSkeleton />
      </div>
    );
  }

  return (
    <>
      <div className="space-y-5 flex flex-col" style={{ minHeight: 'calc(100vh - 120px)' }}>

        {/* Header */}
        <div className="flex items-center justify-between gap-4 flex-wrap">
          <div className="flex items-center gap-3 flex-wrap">
            <div>
              <h1 className="text-xl font-bold text-gray-900">Pipelines</h1>
              {!isPaidPlan() && pipelines && (
                <div className="mt-1">
                  <PlanLimitBar used={pipelineCount} limit={planLimits.pipelines} label="Pipelines" />
                </div>
              )}
            </div>

            {/* Pipeline tabs */}
            <div className="flex gap-2 flex-wrap">
              {pipelines?.map((p) => {
                const isActive = activePipeline?.id === p.id;
                const count    = isActive ? deals.length : 0;
                return (
                  <button
                    key={p.id}
                    onClick={() => { setActivePipelineId(p.id); setShowManage(false); }}
                    className={`flex items-center gap-2 px-3 py-1.5 rounded-xl text-sm font-medium transition-all ${
                      isActive
                        ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-500/25'
                        : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50'
                    }`}
                  >
                    {p.name}
                    {p.is_default && (
                      <span className={`text-[10px] font-semibold px-1.5 py-0.5 rounded-full ${isActive ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-500'}`}>
                        default
                      </span>
                    )}
                    {isActive && count > 0 && (
                      <span className="bg-white/20 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full">{count}</span>
                    )}
                  </button>
                );
              })}
            </div>
          </div>

          {/* Right actions — owner/admin only */}
          {canManage && (
            <div className="flex items-center gap-2">
              {activePipeline && (
                <button
                  onClick={() => setShowManage((v) => !v)}
                  className={`flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-xl border transition-colors ${
                    showManage
                      ? 'bg-indigo-50 text-indigo-700 border-indigo-200'
                      : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50'
                  }`}
                >
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                      d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                  </svg>
                  Manage Stages
                </button>
              )}
              <button
                onClick={() => !atPipelineLimit && setShowNewPipeline(true)}
                disabled={atPipelineLimit}
                title={atPipelineLimit ? `You've reached the ${planLimits.pipelines}-pipeline limit on the Free plan. Upgrade to add more.` : undefined}
                className={`flex items-center gap-1.5 px-3 py-2 text-white text-sm font-semibold rounded-xl transition-colors shadow-sm
                  ${atPipelineLimit
                    ? 'bg-gray-300 cursor-not-allowed shadow-none'
                    : 'bg-indigo-600 hover:bg-indigo-700 shadow-indigo-500/30'}`}
              >
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4"/>
                </svg>
                New Pipeline
              </button>
            </div>
          )}
        </div>

        {/* Board */}
        {!activePipeline ? (
          <div className="flex-1 flex items-center justify-center">
            <div className="text-center">
              <div className="w-16 h-16 bg-violet-50 rounded-2xl flex items-center justify-center mx-auto mb-4">
                <svg className="w-8 h-8 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                    d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2" />
                </svg>
              </div>
              <p className="text-gray-800 font-semibold mb-1">No pipelines yet</p>
              <p className="text-sm text-gray-400 mb-4">
                {canManage ? 'Create your first pipeline to start tracking deals' : 'No deals have been assigned to you yet.'}
              </p>
              {canManage && (
                <button
                  onClick={() => !atPipelineLimit && setShowNewPipeline(true)}
                  disabled={atPipelineLimit}
                  title={atPipelineLimit ? `Pipeline limit reached on Free plan. Upgrade to add more.` : undefined}
                  className={`inline-flex items-center gap-1.5 text-white text-sm font-semibold px-4 py-2.5 rounded-xl transition-colors
                    ${atPipelineLimit ? 'bg-gray-300 cursor-not-allowed' : 'bg-indigo-600 hover:bg-indigo-700'}`}
                >
                  + Create Pipeline
                </button>
              )}
            </div>
          </div>
        ) : loadingDeals ? (
          <KanbanSkeleton />
        ) : (
          <div className="flex gap-4 overflow-x-auto pb-6 flex-1">
            {activePipeline.stages
              .slice()
              .sort((a, b) => a.sort_order - b.sort_order)
              .map((stage) => {
                const stageDeals = dealsByStage(stage);
                const totalValue = stageDeals.reduce((s, d) => s + (Number(d.value) || 0), 0);
                const isDragOver = dragOverStageId === stage.id;

                return (
                  <div key={stage.id} className="flex-shrink-0 w-72">
                    <div className="rounded-2xl border border-gray-100 overflow-hidden shadow-sm"
                      style={{ borderTopColor: stage.color, borderTopWidth: 4 }}>

                      {/* Stage header */}
                      <div className="px-3 py-3 bg-white">
                        <div className="flex items-center gap-2">
                          <div className="w-2.5 h-2.5 rounded-full shrink-0" style={{ backgroundColor: stage.color }} />
                          <span className="text-sm font-semibold text-gray-800 flex-1 truncate">{stage.name}</span>
                          <StageTypeBadge type={stageType(stage)} />
                          <span className="text-xs font-bold px-2 py-0.5 rounded-full text-white shrink-0"
                            style={{ backgroundColor: stage.color }}>
                            {stageDeals.length}
                          </span>
                        </div>
                        {totalValue > 0 && (
                          <p className="text-xs text-gray-400 mt-1.5 font-medium">₹{totalValue.toLocaleString()}</p>
                        )}
                      </div>

                      {/* Deal cards */}
                      <div className="bg-gray-50/50 p-2 space-y-2 min-h-[80px]">
                        <AnimatePresence>
                          {stageDeals.map((deal) => {
                            const overdue = deal.expected_close_date && deal.status === 'open' &&
                              new Date(deal.expected_close_date) < new Date();
                            return (
                              <motion.div
                                key={deal.id}
                                layout
                                initial={{ opacity: 0, y: 8 }}
                                animate={{ opacity: 1, y: 0 }}
                                exit={{ opacity: 0, scale: 0.95 }}
                                transition={{ type: 'spring', damping: 30, stiffness: 400 }}
                                draggable
                                onDragStart={(e) => {
                                  (e as unknown as DragEvent).dataTransfer?.setData('dealId', String(deal.id));
                                  (e as unknown as DragEvent).dataTransfer?.setData('stageId', String(stage.id));
                                }}
                                className="bg-white rounded-xl shadow-sm hover:shadow-md transition-all duration-150 hover:-translate-y-0.5 cursor-grab active:cursor-grabbing select-none"
                                style={{ borderLeft: `3px solid ${stage.color}` }}
                              >
                                <div className="p-3">
                                  <p className="text-sm font-semibold text-gray-900 mb-1 leading-tight">{deal.title}</p>
                                  {deal.lead && <p className="text-xs text-gray-400 mb-2 truncate">{deal.lead.full_name}</p>}
                                  <div className="flex items-center justify-between gap-2">
                                    <div className="flex items-center gap-1.5">
                                      {deal.value != null && (
                                        <span className="text-xs font-bold text-indigo-600">₹{Number(deal.value).toLocaleString('en-IN')}</span>
                                      )}
                                      <Badge value={deal.status} />
                                    </div>
                                    {deal.expected_close_date && (
                                      <span className={`text-[10px] font-medium shrink-0 ${overdue ? 'text-red-500' : 'text-gray-400'}`}>
                                        {overdue && '⚠ '}
                                        {new Date(deal.expected_close_date).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })}
                                      </span>
                                    )}
                                  </div>
                                  {deal.probability != null && (
                                    <div className="mt-2">
                                      <div className="flex items-center justify-between mb-1">
                                        <span className="text-[10px] text-gray-400">Probability</span>
                                        <span className="text-[10px] font-semibold text-gray-600">{deal.probability}%</span>
                                      </div>
                                      <div className="h-1 bg-gray-100 rounded-full overflow-hidden">
                                        <div className="h-full rounded-full transition-all"
                                          style={{ width: `${deal.probability}%`, backgroundColor: stage.color }} />
                                      </div>
                                    </div>
                                  )}

                                  {/* Negotiation quick-edit button — only on Negotiation stage */}
                                  {deal.status === 'open' && stage.name.toLowerCase().includes('negotiat') && (
                                    <button
                                      onClick={(e) => { e.stopPropagation(); setNegotiationDeal(deal); }}
                                      className="mt-2.5 w-full flex items-center justify-center gap-1.5 py-2 rounded-lg bg-violet-600 hover:bg-violet-700 active:bg-violet-800 text-white text-[11px] font-semibold transition-colors shadow-sm shadow-violet-400/40"
                                    >
                                      <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                          d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                      </svg>
                                      Negotiation
                                    </button>
                                  )}

                                  {/* Negotiation counter-offer badge */}
                                  {deal.counter_offer_value != null && deal.original_value != null && (
                                    (() => {
                                      const diff = Number(deal.counter_offer_value) - Number(deal.original_value);
                                      const pct  = Math.abs((diff / Number(deal.original_value)) * 100).toFixed(0);
                                      const down = diff < 0;
                                      return (
                                        <div className={`mt-2 flex items-center gap-1.5 px-2 py-1 rounded-lg text-[10px] font-semibold ${
                                          down ? 'bg-red-50 text-red-600' : 'bg-emerald-50 text-emerald-700'
                                        }`}>
                                          <svg className="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                              d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                          </svg>
                                          ₹{Number(deal.original_value).toLocaleString('en-IN')}
                                          {' → '}
                                          ₹{Number(deal.counter_offer_value).toLocaleString('en-IN')}
                                          {' '}({down ? '-' : '+'}{pct}%)
                                        </div>
                                      );
                                    })()
                                  )}

                                  {/* Negotiation notes snippet */}
                                  {deal.negotiation_notes && (
                                    <p className="mt-1.5 text-[10px] text-gray-400 leading-relaxed line-clamp-2 italic">
                                      &ldquo;{deal.negotiation_notes}&rdquo;
                                    </p>
                                  )}

                                  {/* Handed-off indicator */}
                                  {stage.is_won && deal.handed_off_at && (
                                    <div className="mt-2.5 w-full flex items-center justify-center gap-1.5 py-1.5 rounded-lg bg-emerald-50 text-emerald-600 text-[11px] font-semibold border border-emerald-100">
                                      <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                      </svg>
                                      Handed off
                                    </div>
                                  )}

                                  {/* Hand-off button — only on Won stage cards that haven't been handed off yet */}
                                  {stage.is_won && !deal.handed_off_at && (
                                    <button
                                      onClick={(e) => { e.stopPropagation(); setHandoffDeal(deal); }}
                                      className="mt-2.5 w-full flex items-center justify-center gap-1.5 py-1.5 rounded-lg bg-indigo-50 hover:bg-indigo-100 text-indigo-600 text-[11px] font-semibold transition-colors border border-indigo-100"
                                    >
                                      <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                                      </svg>
                                      Hand off to Pipeline
                                    </button>
                                  )}
                                </div>
                              </motion.div>
                            );
                          })}
                        </AnimatePresence>

                        {/* Drop zone */}
                        <div
                          onDragOver={(e) => { e.preventDefault(); setDragOverStageId(stage.id); }}
                          onDragLeave={() => setDragOverStageId(null)}
                          onDrop={(e) => handleDrop(e, stage.id)}
                          className={`h-14 rounded-xl border-2 border-dashed flex items-center justify-center text-xs font-medium transition-all ${
                            isDragOver
                              ? 'border-indigo-400 bg-indigo-50/60 text-indigo-500'
                              : 'border-gray-200 text-gray-300 hover:border-gray-300'
                          }`}
                        >
                          {isDragOver ? '+ Drop here' : 'Drop deal here'}
                        </div>
                      </div>
                    </div>
                  </div>
                );
              })}

            {/* Add stage shortcut column — owner/admin only */}
            {canManage && (
              <div className="flex-shrink-0 w-64">
                <button
                  onClick={() => setShowManage(true)}
                  className="w-full h-24 rounded-2xl border-2 border-dashed border-gray-200 flex flex-col items-center justify-center gap-1.5 text-gray-400 hover:border-indigo-300 hover:text-indigo-500 hover:bg-indigo-50/30 transition-all"
                >
                  <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4"/>
                  </svg>
                  <span className="text-xs font-medium">Add Stage</span>
                </button>
              </div>
            )}
          </div>
        )}
      </div>

      {/* Manage panel backdrop + panel — owner/admin only */}
      {canManage && (
        <AnimatePresence>
          {showManage && activePipeline && (
            <>
              <motion.div
                initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
                className="fixed inset-0 bg-black/20 z-40"
                onClick={() => setShowManage(false)}
              />
              <ManagePanel pipeline={activePipeline} onClose={() => setShowManage(false)} />
            </>
          )}
        </AnimatePresence>
      )}

      {/* New pipeline modal — owner/admin only */}
      {canManage && (
        <AnimatePresence>
          {showNewPipeline && <NewPipelineModal onClose={() => setShowNewPipeline(false)} />}
        </AnimatePresence>
      )}

      {/* Hand-off modal — available to all users */}
      <AnimatePresence>
        {handoffDeal && activePipeline && (
          <HandoffModal
            deal={handoffDeal}
            currentPipelineId={activePipeline.id}
            pipelines={visiblePipelines}
            onClose={() => setHandoffDeal(null)}
          />
        )}
      </AnimatePresence>

      {/* Negotiation popup */}
      <AnimatePresence>
        {negotiationDeal && (
          <NegotiationPopup
            deal={negotiationDeal}
            onClose={() => setNegotiationDeal(null)}
          />
        )}
      </AnimatePresence>
    </>
  );
}
