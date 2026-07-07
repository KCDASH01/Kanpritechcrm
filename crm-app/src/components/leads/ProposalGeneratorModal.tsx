'use client';

import { useState, useCallback } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { proposalsApi } from '@/lib/api/proposals';
import type { Lead, Proposal, ProposalContent, ProposalTheme, ProposalStatus } from '@/types';
import { ProposalPDF } from './ProposalPDF';
import { Modal } from '@/components/ui/Modal';

// ── Theme config ──────────────────────────────────────────────────────────────

const THEMES: {
  value: ProposalTheme;
  label: string;
  desc: string;
  primary: string;
  accent: string;
  border: string;
  preview: string;
}[] = [
  {
    value: 'modern',
    label: 'Modern',
    desc: 'Indigo & Violet',
    primary: '#4f46e5',
    accent: '#7c3aed',
    border: 'border-indigo-400',
    preview: 'bg-gradient-to-br from-indigo-500 to-violet-600',
  },
  {
    value: 'corporate',
    label: 'Corporate',
    desc: 'Navy & Gold',
    primary: '#1e3a5f',
    accent: '#b8860b',
    border: 'border-blue-900',
    preview: 'bg-gradient-to-br from-blue-900 to-yellow-700',
  },
  {
    value: 'minimal',
    label: 'Minimal',
    desc: 'Black & Gray',
    primary: '#111827',
    accent: '#6b7280',
    border: 'border-gray-700',
    preview: 'bg-gradient-to-br from-gray-800 to-gray-500',
  },
  {
    value: 'vibrant',
    label: 'Vibrant',
    desc: 'Purple & Emerald',
    primary: '#7c3aed',
    accent: '#059669',
    border: 'border-purple-500',
    preview: 'bg-gradient-to-br from-purple-600 to-emerald-500',
  },
];

// ── Generating animation messages ─────────────────────────────────────────────

const GENERATING_MESSAGES = [
  'Analyzing client needs…',
  'Drafting executive summary…',
  'Building scope of work…',
  'Calculating investment breakdown…',
  'Writing terms & conditions…',
  'Finalizing proposal…',
];

// ── Status badge ──────────────────────────────────────────────────────────────

const STATUS_COLORS: Record<ProposalStatus, string> = {
  draft:    'bg-gray-100 text-gray-600',
  sent:     'bg-blue-100 text-blue-700',
  accepted: 'bg-emerald-100 text-emerald-700',
  rejected: 'bg-red-100 text-red-700',
};

// ── Main component ────────────────────────────────────────────────────────────

interface Props {
  lead: Lead;
  onClose: () => void;
}

type Step = 'input' | 'generating' | 'preview';

export function ProposalGeneratorModal({ lead, onClose }: Props) {
  const qc = useQueryClient();

  const [step, setStep]           = useState<Step>('input');
  const [notes, setNotes]         = useState('');
  const [theme, setTheme]         = useState<ProposalTheme>('modern');
  const [proposal, setProposal]   = useState<Proposal | null>(null);
  const [msgIdx, setMsgIdx]       = useState(0);
  const [editContent, setEditContent] = useState<ProposalContent | null>(null);
  const [editStatus, setEditStatus]   = useState<ProposalStatus>('draft');
  const [editValidUntil, setEditValidUntil] = useState('');
  const [showPDF, setShowPDF]     = useState(false);
  const [saveSuccess, setSaveSuccess] = useState(false);

  // Animate through loading messages
  const startMsgCycle = useCallback(() => {
    let i = 0;
    const interval = setInterval(() => {
      i = (i + 1) % GENERATING_MESSAGES.length;
      setMsgIdx(i);
    }, 1800);
    return interval;
  }, []);

  const generateMutation = useMutation({
    mutationFn: () => proposalsApi.generate(lead.id, notes, theme),
    onMutate: () => {
      setStep('generating');
      const interval = startMsgCycle();
      return { interval };
    },
    onSuccess: (data, _vars, ctx) => {
      clearInterval((ctx as { interval: ReturnType<typeof setInterval> }).interval);
      setProposal(data);
      setEditContent(data.content);
      setEditStatus(data.status);
      setEditValidUntil(data.valid_until ?? '');
      setStep('preview');
      qc.invalidateQueries({ queryKey: ['lead-proposals', lead.id] });
    },
    onError: (_err, _vars, ctx) => {
      clearInterval((ctx as { interval: ReturnType<typeof setInterval> }).interval);
      setStep('input');
    },
  });

  const saveMutation = useMutation({
    mutationFn: () =>
      proposalsApi.update(proposal!.id, {
        content:     editContent ?? undefined,
        status:      editStatus,
        valid_until: editValidUntil || null,
      }),
    onSuccess: (data) => {
      setProposal(data);
      setSaveSuccess(true);
      setTimeout(() => setSaveSuccess(false), 3000);
      qc.invalidateQueries({ queryKey: ['lead-proposals', lead.id] });
    },
  });

  const inputCls =
    'w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500';

  // ── Step: Input ─────────────────────────────────────────────────────────────

  if (step === 'input') {
    return (
      <div className="space-y-5">
        {/* Lead context banner */}
        <div className="bg-indigo-50 border border-indigo-100 rounded-xl px-4 py-3 flex items-center gap-3">
          <div className="w-9 h-9 rounded-full bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center text-white font-bold text-sm shrink-0">
            {lead.full_name.charAt(0).toUpperCase()}
          </div>
          <div>
            <p className="text-sm font-semibold text-gray-900">{lead.full_name}</p>
            <p className="text-xs text-gray-500">
              {[lead.company, lead.industry, lead.stage?.name].filter(Boolean).join(' · ')}
            </p>
          </div>
        </div>

        {/* Conversation notes */}
        <div>
          <label className="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">
            What did you discuss with the client? *
          </label>
          <textarea
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            rows={5}
            placeholder="e.g. Client wants to automate their billing workflow. Budget around ₹3L. Timeline: 8 weeks. Main pain points are manual invoicing and lack of payment tracking..."
            className={`${inputCls} resize-none`}
          />
          <p className="text-xs text-gray-400 mt-1">
            {notes.length < 20
              ? `Minimum 20 characters (${20 - notes.length} more needed)`
              : `${notes.length} characters — good detail helps the AI write a better proposal`}
          </p>
        </div>

        {/* Theme picker */}
        <div>
          <label className="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">
            Choose Proposal Theme
          </label>
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
            {THEMES.map((t) => (
              <button
                key={t.value}
                onClick={() => setTheme(t.value)}
                className={`relative flex flex-col items-center gap-2 p-3 rounded-xl border-2 transition-all text-center
                  ${theme === t.value
                    ? `${t.border} bg-gray-50 shadow-sm`
                    : 'border-gray-200 hover:border-gray-300'}`}
              >
                <div className={`w-10 h-10 rounded-lg ${t.preview} shrink-0`} />
                <div>
                  <p className="text-xs font-semibold text-gray-800">{t.label}</p>
                  <p className="text-[10px] text-gray-400">{t.desc}</p>
                </div>
                {theme === t.value && (
                  <span className="absolute top-1.5 right-1.5 w-4 h-4 bg-indigo-600 rounded-full flex items-center justify-center">
                    <svg className="w-2.5 h-2.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
                    </svg>
                  </span>
                )}
              </button>
            ))}
          </div>
        </div>

        {/* Error */}
        {generateMutation.isError && (
          <p className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-xl px-4 py-3">
            {(generateMutation.error as { response?: { data?: { message?: string } } })?.response?.data?.message
              ?? (generateMutation.error as Error)?.message
              ?? 'Failed to generate proposal. Please try again.'}
          </p>
        )}

        {/* Generate button */}
        <button
          onClick={() => generateMutation.mutate()}
          disabled={notes.trim().length < 20}
          className="w-full flex items-center justify-center gap-2 px-4 py-3 bg-indigo-600 hover:bg-indigo-700
                     disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-semibold rounded-xl
                     transition-colors"
        >
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
          </svg>
          Generate Proposal with AI
        </button>
      </div>
    );
  }

  // ── Step: Generating ────────────────────────────────────────────────────────

  if (step === 'generating') {
    return (
      <div className="flex flex-col items-center justify-center py-12 space-y-6">
        {/* Pulsing brain icon */}
        <div className="relative">
          <div className="w-20 h-20 rounded-full bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center">
            <svg className="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
            </svg>
          </div>
          {/* Pulsing rings */}
          {[1, 2, 3].map((i) => (
            <div
              key={i}
              className="absolute inset-0 rounded-full border-2 border-indigo-400 animate-ping"
              style={{ animationDelay: `${i * 0.4}s`, animationDuration: '1.8s' }}
            />
          ))}
        </div>

        {/* Message */}
        <AnimatePresence mode="wait">
          <motion.p
            key={msgIdx}
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -8 }}
            transition={{ duration: 0.3 }}
            className="text-sm font-medium text-gray-700"
          >
            {GENERATING_MESSAGES[msgIdx]}
          </motion.p>
        </AnimatePresence>

        {/* Dots */}
        <div className="flex items-center gap-2">
          {[0, 1, 2].map((i) => (
            <div
              key={i}
              className="w-2 h-2 rounded-full bg-indigo-500 animate-bounce"
              style={{ animationDelay: `${i * 0.15}s` }}
            />
          ))}
        </div>

        <p className="text-xs text-gray-400">This takes 10–20 seconds…</p>
      </div>
    );
  }

  // ── Step: Preview / Edit ────────────────────────────────────────────────────

  if (!proposal || !editContent) return null;

  const selectedTheme = THEMES.find((t) => t.value === proposal.theme) ?? THEMES[0];

  return (
    <div className="space-y-5">
      {/* Success / save bar */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <span className="flex items-center gap-1.5 text-sm font-semibold text-emerald-700">
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Proposal Ready
          </span>
          {saveSuccess && (
            <span className="text-xs text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full">Saved!</span>
          )}
        </div>
        <div className="flex items-center gap-2">
          {/* Status */}
          <select
            value={editStatus}
            onChange={(e) => setEditStatus(e.target.value as ProposalStatus)}
            className="text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-indigo-500"
          >
            {(['draft', 'sent', 'accepted', 'rejected'] as ProposalStatus[]).map((s) => (
              <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>
            ))}
          </select>
          {/* Valid until */}
          <input
            type="date"
            value={editValidUntil}
            onChange={(e) => setEditValidUntil(e.target.value)}
            className="text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />
        </div>
      </div>

      {/* Theme indicator */}
      <div className="flex items-center gap-2">
        <div className={`w-4 h-4 rounded-full ${selectedTheme.preview}`} />
        <span className="text-xs text-gray-500">{selectedTheme.label} theme</span>
        <span className={`text-xs px-2 py-0.5 rounded-full ${STATUS_COLORS[editStatus]}`}>
          {editStatus}
        </span>
      </div>

      {/* Scrollable content editor */}
      <div className="space-y-4 max-h-[50vh] overflow-y-auto pr-1">
        {/* Title */}
        <Section label="Title">
          <input
            value={editContent.title}
            onChange={(e) => setEditContent({ ...editContent, title: e.target.value })}
            className={inputCls}
          />
        </Section>

        {/* Executive Summary */}
        <Section label="Executive Summary">
          <textarea
            rows={4}
            value={editContent.executive_summary}
            onChange={(e) => setEditContent({ ...editContent, executive_summary: e.target.value })}
            className={`${inputCls} resize-none`}
          />
        </Section>

        {/* Understanding */}
        <Section label="Client Understanding">
          <textarea
            rows={3}
            value={editContent.understanding}
            onChange={(e) => setEditContent({ ...editContent, understanding: e.target.value })}
            className={`${inputCls} resize-none`}
          />
        </Section>

        {/* Proposed Solution */}
        <Section label="Proposed Solution">
          <textarea
            rows={3}
            value={typeof editContent.proposed_solution === 'string'
              ? editContent.proposed_solution
              : editContent.proposed_solution?.overview ?? ''}
            onChange={(e) => {
              const ps = typeof editContent.proposed_solution === 'object' && editContent.proposed_solution !== null
                ? { ...editContent.proposed_solution, overview: e.target.value }
                : e.target.value;
              setEditContent({ ...editContent, proposed_solution: ps });
            }}
            className={`${inputCls} resize-none`}
          />
        </Section>

        {/* Scope of Work */}
        <Section label="Scope of Work">
          <div className="space-y-2">
            {editContent.scope_of_work.map((item, i) => (
              <div key={i} className="border border-gray-100 rounded-xl p-3 space-y-2">
                <input
                  value={item.item}
                  placeholder="Deliverable"
                  onChange={(e) => {
                    const updated = [...editContent.scope_of_work];
                    updated[i] = { ...updated[i], item: e.target.value };
                    setEditContent({ ...editContent, scope_of_work: updated });
                  }}
                  className={inputCls}
                />
                <input
                  value={item.description}
                  placeholder="Description"
                  onChange={(e) => {
                    const updated = [...editContent.scope_of_work];
                    updated[i] = { ...updated[i], description: e.target.value };
                    setEditContent({ ...editContent, scope_of_work: updated });
                  }}
                  className={inputCls}
                />
              </div>
            ))}
          </div>
        </Section>

        {/* Timeline */}
        <Section label="Timeline">
          <div className="space-y-2">
            {editContent.timeline.map((item, i) => (
              <div key={i} className="grid grid-cols-3 gap-2">
                <input
                  value={item.phase}
                  placeholder="Phase"
                  onChange={(e) => {
                    const updated = [...editContent.timeline];
                    updated[i] = { ...updated[i], phase: e.target.value };
                    setEditContent({ ...editContent, timeline: updated });
                  }}
                  className={inputCls}
                />
                <input
                  value={item.duration}
                  placeholder="Duration"
                  onChange={(e) => {
                    const updated = [...editContent.timeline];
                    updated[i] = { ...updated[i], duration: e.target.value };
                    setEditContent({ ...editContent, timeline: updated });
                  }}
                  className={inputCls}
                />
                <input
                  value={item.deliverables}
                  placeholder="Deliverables"
                  onChange={(e) => {
                    const updated = [...editContent.timeline];
                    updated[i] = { ...updated[i], deliverables: e.target.value };
                    setEditContent({ ...editContent, timeline: updated });
                  }}
                  className={inputCls}
                />
              </div>
            ))}
          </div>
        </Section>

        {/* Investment */}
        <Section label="Investment">
          <div className="space-y-2">
            {editContent.investment.breakdown.map((item, i) => (
              <div key={i} className="grid grid-cols-2 gap-2">
                <input
                  value={item.item}
                  placeholder="Line item"
                  onChange={(e) => {
                    const updated = [...editContent.investment.breakdown];
                    updated[i] = { ...updated[i], item: e.target.value };
                    setEditContent({
                      ...editContent,
                      investment: { ...editContent.investment, breakdown: updated },
                    });
                  }}
                  className={inputCls}
                />
                <input
                  type="number"
                  value={item.amount}
                  onChange={(e) => {
                    const updated = [...editContent.investment.breakdown];
                    updated[i] = { ...updated[i], amount: Number(e.target.value) };
                    const total = updated.reduce((s, x) => s + x.amount, 0);
                    setEditContent({
                      ...editContent,
                      investment: { ...editContent.investment, breakdown: updated, total },
                    });
                  }}
                  className={inputCls}
                />
              </div>
            ))}
            <div className="flex items-center justify-between pt-1 px-1">
              <span className="text-xs font-semibold text-gray-600 uppercase">Total</span>
              <span className="text-sm font-bold text-gray-900">
                ₹{editContent.investment.total.toLocaleString('en-IN')}
              </span>
            </div>
            <input
              value={editContent.investment.payment_terms}
              placeholder="Payment terms"
              onChange={(e) =>
                setEditContent({
                  ...editContent,
                  investment: { ...editContent.investment, payment_terms: e.target.value },
                })
              }
              className={inputCls}
            />
          </div>
        </Section>

        {/* Terms */}
        <Section label="Terms & Conditions">
          <textarea
            rows={3}
            value={editContent.terms_and_conditions}
            onChange={(e) => setEditContent({ ...editContent, terms_and_conditions: e.target.value })}
            className={`${inputCls} resize-none`}
          />
        </Section>

        {/* Next Steps */}
        <Section label="Next Steps">
          <textarea
            rows={2}
            value={editContent.next_steps}
            onChange={(e) => setEditContent({ ...editContent, next_steps: e.target.value })}
            className={`${inputCls} resize-none`}
          />
        </Section>
      </div>

      {/* Action buttons */}
      <div className="flex items-center gap-2 pt-1 border-t border-gray-100">
        <button
          onClick={() => saveMutation.mutate()}
          disabled={saveMutation.isPending}
          className="flex items-center gap-1.5 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition-colors disabled:opacity-50"
        >
          {saveMutation.isPending ? (
            <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
          ) : (
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" />
            </svg>
          )}
          Save
        </button>

        <button
          onClick={() => setShowPDF(true)}
          className="flex items-center gap-1.5 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-xl transition-colors"
        >
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
          </svg>
          Download PDF
        </button>

        <button
          onClick={() => {
            setStep('input');
            setProposal(null);
            setEditContent(null);
          }}
          className="flex items-center gap-1.5 px-4 py-2 border border-gray-200 text-gray-600 text-sm font-medium rounded-xl hover:bg-gray-50 transition-colors ml-auto"
        >
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          Regenerate
        </button>
      </div>

      {/* PDF preview modal */}
      {showPDF && (
        <Modal
          open={showPDF}
          onClose={() => setShowPDF(false)}
          title="Download Proposal PDF"
          maxWidth="max-w-4xl"
        >
          <ProposalPDF
            proposal={{ ...proposal, content: editContent }}
            lead={lead}
            onClose={() => setShowPDF(false)}
          />
        </Modal>
      )}
    </div>
  );
}

// ── Section wrapper ───────────────────────────────────────────────────────────

function Section({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="space-y-1.5">
      <label className="block text-xs font-semibold text-gray-500 uppercase tracking-wide">{label}</label>
      {children}
    </div>
  );
}
