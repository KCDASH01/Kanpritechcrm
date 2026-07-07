'use client';

import { useState, useEffect, useRef, useCallback } from 'react';
import { flushSync } from 'react-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useParams } from 'next/navigation';
import Link from 'next/link';
import { motion, AnimatePresence } from 'framer-motion';
import { leadsApi } from '@/lib/api/leads';
import { proposalsApi } from '@/lib/api/proposals';
import { ProposalPDF } from '@/components/leads/ProposalPDF';
import { Modal } from '@/components/ui/Modal';
import type { ProposalTheme, ProposalContent, ProposedSolution, Proposal, ProposalStatus } from '@/types';


// ── Theme config ───────────────────────────────────────────────────────────────

const THEMES: {
  value: ProposalTheme;
  label: string;
  desc: string;
  gradient: string;
}[] = [
  { value: 'modern',    label: 'Modern',    desc: 'Indigo & Violet',   gradient: 'from-indigo-500 to-violet-600'  },
  { value: 'corporate', label: 'Corporate', desc: 'Navy & Gold',       gradient: 'from-blue-900 to-yellow-700'    },
  { value: 'minimal',   label: 'Minimal',   desc: 'Black & Gray',      gradient: 'from-gray-800 to-gray-500'      },
  { value: 'vibrant',   label: 'Vibrant',   desc: 'Purple & Emerald',  gradient: 'from-purple-600 to-emerald-500' },
];

// ── Streaming types ────────────────────────────────────────────────────────────

type StreamBlock = {
  id: string;
  icon: string;
  heading: string;
  text: string;
  isTitle?: boolean;
  isFootnote?: boolean;
};

type DisplayBlock = StreamBlock & { visibleText: string; done: boolean };

/** Serialize new proposed_solution object to display text for streaming view */
function serializeProposedSolution(ps: ProposedSolution | string): string {
  if (typeof ps === 'string') return ps;
  const parts: string[] = [];
  if (ps.overview) parts.push(ps.overview);
  if (ps.approach) parts.push(`\nApproach: ${ps.approach}`);
  if (ps.technology_stack?.length) parts.push(`\nTechnology Stack: ${ps.technology_stack.join(' · ')}`);
  if (ps.key_features?.length) {
    parts.push('\nKey Features:');
    ps.key_features.forEach((f) => parts.push(`  ▸ ${f.feature}: ${f.benefit}`));
  }
  if (ps.differentiators) parts.push(`\nWhat Sets Us Apart: ${ps.differentiators}`);
  if (ps.success_metrics?.length) {
    parts.push('\nSuccess Metrics:');
    ps.success_metrics.forEach((m) => parts.push(`  ▸ ${m.metric} → ${m.target}`));
  }
  if (ps.implementation_approach) parts.push(`\nDelivery Model: ${ps.implementation_approach}`);
  return parts.join('\n');
}

/** Normalize legacy string proposed_solution to object */
function normalizeProposedSolution(ps: ProposedSolution | string): ProposedSolution {
  if (typeof ps === 'string') {
    return {
      overview: ps, approach: '', technology_stack: [],
      key_features: [], differentiators: '',
      success_metrics: [], implementation_approach: '',
    };
  }
  return ps;
}

/** Ensure a loaded proposal content has all new fields with safe defaults */
function normalizeContent(raw: ProposalContent): ProposalContent {
  return {
    about_us: '',
    why_choose_us: [],
    key_benefits: [],
    team: [],
    risk_mitigation: [],
    assumptions: [],
    client_pain_points: [],
    platform_tech: [],
    maintenance_support: undefined,
    closing_note: '',
    ...raw,
    proposed_solution: normalizeProposedSolution(raw.proposed_solution),
    investment: {
      resource_breakdown: [],
      ...raw.investment,
    },
  };
}

function buildBlocks(c: ProposalContent): StreamBlock[] {
  const blocks: StreamBlock[] = [
    { id: 'title',   icon: '', heading: '', text: c.title, isTitle: true },
    { id: 'summary', icon: '📋', heading: 'Executive Summary', text: c.executive_summary },
  ];

  if (c.about_us) {
    blocks.push({ id: 'about', icon: '🏢', heading: 'About Us', text: c.about_us });
  }

  blocks.push({ id: 'understanding', icon: '🎯', heading: 'Our Understanding', text: c.understanding });

  if (c.client_pain_points?.length) {
    blocks.push({
      id: 'pain_points', icon: '⚠️', heading: 'Problems Identified',
      text: c.client_pain_points.map((p) => `▸ ${p.title}\n${p.points.map((pt) => `   • ${pt}`).join('\n')}`).join('\n\n'),
    });
  }

  if (c.why_choose_us?.length) {
    blocks.push({
      id: 'why', icon: '⭐', heading: 'Why Choose Us',
      text: c.why_choose_us.map((w, i) => `${i + 1}. ${w}`).join('\n'),
    });
  }

  if (c.key_benefits?.length) {
    blocks.push({
      id: 'benefits', icon: '🎯', heading: 'Key Benefits',
      text: c.key_benefits.map((b) => `▸ ${b.title}\n   ${b.description}`).join('\n\n'),
    });
  }

  blocks.push({
    id: 'solution', icon: '💡', heading: 'Proposed Solution',
    text: serializeProposedSolution(c.proposed_solution),
  });

  blocks.push({
    id: 'scope', icon: '📦', heading: 'Scope of Work',
    text: c.scope_of_work.map((s, i) => `${i + 1}. ${s.item}\n   ${s.description}${s.key_deliverables?.length ? '\n' + s.key_deliverables.map((d) => `     ▸ ${d}`).join('\n') : ''}`).join('\n\n'),
  });

  if (c.team?.length) {
    blocks.push({
      id: 'team', icon: '👥', heading: 'Our Team',
      text: c.team.map((m) => `▸ ${m.role}\n   ${m.responsibility}`).join('\n\n'),
    });
  }

  blocks.push({
    id: 'timeline', icon: '📅', heading: 'Project Timeline',
    text: c.timeline.map((t) => `▸  ${t.phase}  ·  ${t.duration}\n   ${t.deliverables}`).join('\n\n'),
  });

  if (c.platform_tech?.length) {
    blocks.push({
      id: 'platform', icon: '🔧', heading: 'Platform & Technology',
      text: c.platform_tech.map((pt) => `${pt.label.padEnd(16, ' ')}  ${pt.value}`).join('\n'),
    });
  }

  blocks.push({
    id: 'investment', icon: '💰', heading: 'Investment',
    text: [
      ...c.investment.breakdown.map((b) => `   ${b.item.padEnd(32, ' ')}  ₹${Number(b.amount).toLocaleString('en-IN')}`),
      `   ${'─'.repeat(48)}`,
      `   ${'Total'.padEnd(32, ' ')}  ₹${Number(c.investment.total).toLocaleString('en-IN')} ${c.investment.currency}`,
      `\n   Payment Terms: ${c.investment.payment_terms}`,
    ].join('\n'),
  });

  if (c.risk_mitigation?.length) {
    blocks.push({
      id: 'risks', icon: '🛡️', heading: 'Risk & Mitigation',
      text: c.risk_mitigation.map((r) => `▸ ${r.risk}\n   Mitigation: ${r.mitigation}`).join('\n\n'),
    });
  }

  if (c.assumptions?.length) {
    blocks.push({
      id: 'assumptions', icon: '📌', heading: 'Assumptions',
      text: c.assumptions.map((a, i) => `${i + 1}. ${a}`).join('\n'),
    });
  }

  if (c.maintenance_support) {
    blocks.push({
      id: 'support', icon: '🛟', heading: 'Maintenance & Support',
      text: `Free Support Period: ${c.maintenance_support.period}\n\n${c.maintenance_support.includes.map((item) => `• ${item}`).join('\n')}`,
    });
  }

  blocks.push({ id: 'terms', icon: '📜', heading: 'Terms & Conditions', text: c.terms_and_conditions });
  blocks.push({ id: 'next',  icon: '🚀', heading: 'Next Steps',         text: c.next_steps });

  if (c.closing_note) {
    blocks.push({ id: 'closing', icon: '🙏', heading: 'Closing', text: c.closing_note });
  }

  blocks.push({
    id: 'validity', icon: '', heading: '',
    text: `✦  This proposal is valid for ${c.validity_days} days from the date of issue.`,
    isFootnote: true,
  });

  return blocks;
}

// ── Generation step config ─────────────────────────────────────────────────────

const GEN_STEPS = [
  { label: 'Preparing your proposal…',      buttonLabel: 'Preparing…'  },
  { label: 'Analyzing conversation notes…', buttonLabel: 'Analyzing…'  },
  { label: 'Generating with AI…',           buttonLabel: 'Generating…' },
] as const;

// ── Generating skeleton ────────────────────────────────────────────────────────
// Shown while the API call is in-flight (before streaming starts).
// Cycles through status steps so the user always knows what's happening.

function GeneratingSkeleton({ onStepChange }: { onStepChange: (i: number) => void }) {
  const [stepIdx, setStepIdx] = useState(0);

  useEffect(() => {
    const id = setInterval(() => {
      setStepIdx((i) => {
        const next = Math.min(i + 1, GEN_STEPS.length - 1);
        onStepChange(next);
        return next;
      });
    }, 2500);
    return () => clearInterval(id);
  }, [onStepChange]);

  return (
    <div className="max-w-3xl mx-auto px-10 py-10 space-y-8 select-none">

      {/* ── Step progress indicator ── */}
      <div className="flex flex-col gap-3 pb-6 border-b border-gray-100">
        {/* Step pills */}
        <div className="flex items-center gap-2">
          {GEN_STEPS.map((s, i) => (
            <div key={s.label} className="flex items-center gap-2">
              <div className={`h-1.5 rounded-full transition-all duration-700 ${
                i < stepIdx  ? 'w-10 bg-indigo-400' :
                i === stepIdx ? 'w-10 bg-indigo-500' :
                               'w-6  bg-gray-200'
              }`} />
              {i < GEN_STEPS.length - 1 && (
                <div className={`w-3 h-px transition-colors duration-700 ${i < stepIdx ? 'bg-indigo-300' : 'bg-gray-200'}`} />
              )}
            </div>
          ))}
        </div>

        {/* Animated label */}
        <AnimatePresence mode="wait">
          <motion.div
            key={stepIdx}
            initial={{ opacity: 0, y: 4 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -4 }}
            transition={{ duration: 0.25 }}
            className="flex items-center gap-2.5"
          >
            <div className="flex gap-1">
              {[0, 1, 2].map((i) => (
                <span key={i} className="w-1.5 h-1.5 rounded-full bg-indigo-400 inline-block"
                  style={{ animation: `skeletonBounce 1.2s ease-in-out ${i * 0.2}s infinite` }} />
              ))}
            </div>
            <span className="text-sm font-semibold text-indigo-600">
              {GEN_STEPS[stepIdx].label}
            </span>
          </motion.div>
        </AnimatePresence>
      </div>

      {/* header label */}
      <div className="flex items-center gap-2">
        <div className="w-5 h-5 rounded-md bg-indigo-100 skeleton" />
        <div className="h-3 w-36 rounded-full skeleton" />
      </div>

      {/* title line */}
      <div className="space-y-2 pb-7 border-b border-gray-200">
        <div className="h-7 w-3/4 rounded-lg skeleton" />
        <div className="h-7 w-1/2 rounded-lg skeleton" />
      </div>

      {/* sections */}
      {[
        { label: '📋  Executive Summary',  lines: [100, 95, 88, 70] },
        { label: '🎯  Our Understanding',  lines: [98, 90, 75]      },
        { label: '💡  Proposed Solution',  lines: [96, 92, 80, 60]  },
        { label: '📦  Scope of Work',      lines: [85, 78]          },
      ].map(({ label, lines }) => (
        <div key={label} className="space-y-3">
          <div className="flex items-center gap-2">
            <div className="h-4 w-4 rounded skeleton" />
            <div className="h-4 w-40 rounded-full skeleton" />
          </div>
          <div className="space-y-2 pl-1">
            {lines.map((w, i) => (
              <div key={i} className="h-3.5 rounded-full skeleton" style={{ width: `${w}%` }} />
            ))}
          </div>
        </div>
      ))}

      <style>{`
        @keyframes skeletonBounce {
          0%, 80%, 100% { transform: translateY(0); opacity: 0.4; }
          40%            { transform: translateY(-5px); opacity: 1; }
        }
      `}</style>
    </div>
  );
}

// ── Page ───────────────────────────────────────────────────────────────────────

type PageMode = 'loading' | 'create' | 'edit';
type StreamStep = 'idle' | 'streaming' | 'done';

export default function ProposalNewPage() {
  const params   = useParams();
  const qc       = useQueryClient();
  const leadId   = Number(params.id);
  const rightRef = useRef<HTMLDivElement>(null);

  // ── Mode ─────────────────────────────────────────────────────────────────────
  const [mode, setMode] = useState<PageMode>('loading');

  // ── Create-mode state ────────────────────────────────────────────────────────
  const [notes, setNotes]         = useState('');
  const [theme, setTheme]         = useState<ProposalTheme>('modern');
  const [step, setStep]           = useState<StreamStep>('idle');
  const [displayedBlocks, setDisplayedBlocks] = useState<DisplayBlock[]>([]);
  const [errorMsg, setErrorMsg]   = useState('');
  const [genStep, setGenStep]     = useState(0); // index into GEN_STEPS for preloader

  // ── Edit-mode state ──────────────────────────────────────────────────────────
  const [proposal, setProposal]       = useState<Proposal | null>(null);
  const [editContent, setEditContent] = useState<ProposalContent | null>(null);
  const [propStatus, setPropStatus]   = useState<ProposalStatus>('draft');
  const [validUntil, setValidUntil]   = useState('');
  const [showPDF, setShowPDF]         = useState(false);
  const [saveSuccess, setSaveSuccess] = useState(false);

  // ── Streaming engine refs ─────────────────────────────────────────────────────
  // streamRef: holds the active setTimeout id so we can cancel on unmount/restart
  const streamRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const sRef = useRef<{
    blockIdx: number; charIdx: number; blocks: StreamBlock[]; active: boolean;
  }>({ blockIdx: 0, charIdx: 0, blocks: [], active: false });

  // ── Fetch lead ────────────────────────────────────────────────────────────────
  const { data: lead, isLoading: leadLoading } = useQuery({
    queryKey: ['lead', leadId],
    queryFn:  () => leadsApi.get(leadId),
    enabled:  !!leadId,
  });

  // ── Fetch existing proposals ──────────────────────────────────────────────────
  const { data: existingProposals, isLoading: proposalsLoading } = useQuery({
    queryKey: ['lead-proposals', leadId],
    queryFn:  () => proposalsApi.list(leadId),
    enabled:  !!leadId,
  });

  // When proposals load, decide mode.
  // Guard: skip while streaming — onSuccess invalidates the query, the refetch
  // returns quickly, and without this guard loadProposalIntoEdit fires mid-stream
  // switching to edit mode and making it look like streaming "suddenly finished".
  useEffect(() => {
    if (proposalsLoading) return;
    if (step === 'streaming') return;
    if (existingProposals && existingProposals.length > 0) {
      const p = existingProposals[0];
      loadProposalIntoEdit(p);
    } else {
      setMode('create');
    }
  }, [existingProposals, proposalsLoading, step]);

  function loadProposalIntoEdit(p: Proposal) {
    setProposal(p);
    const raw = JSON.parse(JSON.stringify(p.content)) as ProposalContent;
    setEditContent(normalizeContent(raw));
    setPropStatus(p.status);
    setTheme(p.theme);
    setValidUntil(p.valid_until ?? '');
    setMode('edit');
  }

  // ── Cleanup on unmount ────────────────────────────────────────────────────────
  useEffect(() => () => {
    sRef.current.active = false;
    if (streamRef.current) clearTimeout(streamRef.current);
  }, []);

  // ── Auto-scroll: follow new content, but stop if the user has scrolled up ─────
  //
  //  We read el.scrollTop directly at effect-fire time — it reflects the actual
  //  DOM scroll position right now.  If the user scrolled up, el.scrollTop is
  //  smaller → distFromBottom is larger → we skip and leave them where they are.
  //  No refs, no event listeners, no flag-race-conditions.
  //
  useEffect(() => {
    if (step !== 'streaming' || !rightRef.current) return;
    const el = rightRef.current;
    // Auto-scroll unless the user has deliberately scrolled up more than 200px
    // from the bottom — gives them room to read without fighting the scroll.
    const distFromBottom = el.scrollHeight - el.scrollTop - el.clientHeight;
    if (distFromBottom > 200) return;
    el.scrollTop = el.scrollHeight;
  }, [displayedBlocks, step]);

  // ── Streaming engine — pure setTimeout chain ──────────────────────────────────
  //
  //  One character per setTimeout tick at a fixed TICK_MS interval.
  //  flushSync forces React 18 to paint each character immediately instead of
  //  batching multiple ticks into a single frame (which made it look chunky).
  //
  //  We use setTimeout (not rAF) so the interval is identical for every block —
  //  rAF's timestamp-throttle approach was causing subsequent blocks to appear
  //  all at once because the throttle misfired after block transitions.
  //
  const tick = useCallback(() => {
    const s = sRef.current;
    if (!s.active) return;

    const TICK_MS        = 30;  // ms per tick
    const CHARS_PER_TICK = 10;  // chars per tick ≈ 333 chars / sec
    const BLOCK_PAUSE_MS = 380; // pause between sections

    // Snapshot blockIdx BEFORE any mutation so the functional updater
    // uses the correct index even if s.blockIdx advances in this same call.
    const currentBlockIdx = s.blockIdx;
    const block = s.blocks[currentBlockIdx];
    if (!block) { s.active = false; setStep('done'); return; }

    const next        = Math.min(s.charIdx + CHARS_PER_TICK, block.text.length);
    s.charIdx         = next;
    const isDone      = next >= block.text.length;
    const visibleText = block.text.slice(0, next);

    // flushSync: paint this single character to the DOM right now,
    // bypassing React 18 automatic batching.
    flushSync(() => {
      setDisplayedBlocks((prev) => {
        const arr = [...prev];
        arr[currentBlockIdx] = { ...block, visibleText, done: isDone };
        return arr;
      });
    });

    if (isDone) {
      const nextIdx = currentBlockIdx + 1;
      s.blockIdx = nextIdx;
      s.charIdx  = 0;
      if (nextIdx < s.blocks.length) {
        // Pause between sections, then add the next block header and resume
        streamRef.current = setTimeout(() => {
          if (!sRef.current.active) return;
          flushSync(() => {
            setDisplayedBlocks((prev) => [
              ...prev,
              { ...s.blocks[nextIdx], visibleText: '', done: false },
            ]);
          });
          streamRef.current = setTimeout(tick, TICK_MS);
        }, BLOCK_PAUSE_MS);
      } else {
        s.active = false;
        setStep('done');
      }
    } else {
      streamRef.current = setTimeout(tick, TICK_MS);
    }
  }, []);

  const startStreaming = useCallback((content: ProposalContent) => {
    // Cancel any running stream
    if (streamRef.current) clearTimeout(streamRef.current);

    const blocks = buildBlocks(content);
    sRef.current = { blockIdx: 0, charIdx: 0, blocks, active: true };

    setDisplayedBlocks([{ ...blocks[0], visibleText: '', done: false }]);
    setStep('streaming');
    if (rightRef.current) rightRef.current.scrollTop = 0;

    // 120 ms pause before the first character appears
    streamRef.current = setTimeout(tick, 120);
  }, [tick]);

  // ── Generate mutation ─────────────────────────────────────────────────────────
  const generateMutation = useMutation({
    mutationFn: () => proposalsApi.generate(leadId, notes, theme),
    onMutate: () => { setErrorMsg(''); setDisplayedBlocks([]); },
    onSuccess: (data) => {
      setProposal(data);
      startStreaming(data.content);
      // Intentionally NOT invalidating queries here — the refetch would complete
      // mid-stream and trigger loadProposalIntoEdit, cutting the animation short.
      // Invalidations happen in the step==='done' effect below instead.
    },
    onError: (err: unknown) => {
      setStep('idle');
      const e = err as { response?: { data?: { message?: string } }; message?: string };
      setErrorMsg(e?.response?.data?.message ?? e?.message ?? 'Something went wrong. Please try again.');
    },
  });

  // When streaming is done → invalidate queries then switch to edit mode.
  // Invalidations are deferred to here (not in onSuccess) so the refetch cannot
  // fire mid-stream and cut the animation short.
  useEffect(() => {
    if (step === 'done' && proposal) {
      qc.invalidateQueries({ queryKey: ['lead-proposals', leadId] });
      qc.invalidateQueries({ queryKey: ['lead', leadId] });
      // Small delay so user sees "done" state briefly before switching
      const t = setTimeout(() => {
        loadProposalIntoEdit(proposal);
      }, 1200);
      return () => clearTimeout(t);
    }
  }, [step, proposal]);

  // ── Save mutation ─────────────────────────────────────────────────────────────
  const saveMutation = useMutation({
    mutationFn: () => {
      if (!proposal || !editContent) throw new Error('No proposal to save');
      return proposalsApi.update(proposal.id, {
        content:     editContent,
        status:      propStatus,
        valid_until: validUntil || null,
        title:       editContent.title,
        theme,
      });
    },
    onSuccess: (updated) => {
      setProposal(updated);
      setSaveSuccess(true);
      qc.invalidateQueries({ queryKey: ['lead-proposals', leadId] });
      qc.invalidateQueries({ queryKey: ['lead', leadId] });
      setTimeout(() => setSaveSuccess(false), 2500);
    },
  });

  const handleGenerate = () => {
    if (notes.trim().length < 20) {
      setErrorMsg('Please write at least 20 characters describing your conversation.');
      return;
    }
    setErrorMsg('');
    setGenStep(0);
    // Do NOT set step here — keep it 'idle' so the preloader skeleton shows
    // while the API call is in-flight. startStreaming() will set it to 'streaming'.
    generateMutation.mutate();
  };

  const handleDownloadPDF = async () => {
    // Save first if needed
    if (saveMutation.isPending) return;
    await saveMutation.mutateAsync();
    setShowPDF(true);
  };

  // ── Edit-content helpers ──────────────────────────────────────────────────────

  function setField<K extends keyof ProposalContent>(key: K, val: ProposalContent[K]) {
    setEditContent((prev) => prev ? { ...prev, [key]: val } : prev);
  }

  function setScope(i: number, field: 'item' | 'description', val: string) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const arr = [...prev.scope_of_work];
      arr[i] = { ...arr[i], [field]: val };
      return { ...prev, scope_of_work: arr };
    });
  }

  function addScope() {
    setEditContent((prev) => {
      if (!prev) return prev;
      return { ...prev, scope_of_work: [...prev.scope_of_work, { item: '', description: '' }] };
    });
  }

  function removeScope(i: number) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const arr = prev.scope_of_work.filter((_, idx) => idx !== i);
      return { ...prev, scope_of_work: arr };
    });
  }

  function setTimeline(i: number, field: 'phase' | 'duration' | 'deliverables', val: string) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const arr = [...prev.timeline];
      arr[i] = { ...arr[i], [field]: val };
      return { ...prev, timeline: arr };
    });
  }

  function addTimeline() {
    setEditContent((prev) => {
      if (!prev) return prev;
      return { ...prev, timeline: [...prev.timeline, { phase: '', duration: '', deliverables: '' }] };
    });
  }

  function removeTimeline(i: number) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const arr = prev.timeline.filter((_, idx) => idx !== i);
      return { ...prev, timeline: arr };
    });
  }

  function setBreakdown(i: number, field: 'item' | 'amount', val: string) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const arr = [...prev.investment.breakdown];
      const updated = { ...arr[i], [field]: field === 'amount' ? Number(val) || 0 : val };
      arr[i] = updated;
      const total = arr.reduce((sum, b) => sum + (Number(b.amount) || 0), 0);
      return { ...prev, investment: { ...prev.investment, breakdown: arr, total } };
    });
  }

  function addBreakdown() {
    setEditContent((prev) => {
      if (!prev) return prev;
      const breakdown = [...prev.investment.breakdown, { item: '', amount: 0 }];
      return { ...prev, investment: { ...prev.investment, breakdown } };
    });
  }

  function removeBreakdown(i: number) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const breakdown = prev.investment.breakdown.filter((_, idx) => idx !== i);
      const total = breakdown.reduce((sum, b) => sum + (Number(b.amount) || 0), 0);
      return { ...prev, investment: { ...prev.investment, breakdown, total } };
    });
  }

  // ── Proposed Solution sub-field helpers ───────────────────────────────────────

  function setPS<K extends keyof ProposedSolution>(key: K, val: ProposedSolution[K]) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const ps = normalizeProposedSolution(prev.proposed_solution);
      return { ...prev, proposed_solution: { ...ps, [key]: val } };
    });
  }

  function setPSFeature(i: number, field: 'feature' | 'benefit', val: string) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const ps = normalizeProposedSolution(prev.proposed_solution);
      const arr = [...(ps.key_features ?? [])];
      arr[i] = { ...arr[i], [field]: val };
      return { ...prev, proposed_solution: { ...ps, key_features: arr } };
    });
  }

  function addPSFeature() {
    setEditContent((prev) => {
      if (!prev) return prev;
      const ps = normalizeProposedSolution(prev.proposed_solution);
      return { ...prev, proposed_solution: { ...ps, key_features: [...(ps.key_features ?? []), { feature: '', benefit: '' }] } };
    });
  }

  function removePSFeature(i: number) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const ps = normalizeProposedSolution(prev.proposed_solution);
      return { ...prev, proposed_solution: { ...ps, key_features: (ps.key_features ?? []).filter((_, idx) => idx !== i) } };
    });
  }

  function setPSMetric(i: number, field: 'metric' | 'target', val: string) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const ps = normalizeProposedSolution(prev.proposed_solution);
      const arr = [...(ps.success_metrics ?? [])];
      arr[i] = { ...arr[i], [field]: val };
      return { ...prev, proposed_solution: { ...ps, success_metrics: arr } };
    });
  }

  function addPSMetric() {
    setEditContent((prev) => {
      if (!prev) return prev;
      const ps = normalizeProposedSolution(prev.proposed_solution);
      return { ...prev, proposed_solution: { ...ps, success_metrics: [...(ps.success_metrics ?? []), { metric: '', target: '' }] } };
    });
  }

  function removePSMetric(i: number) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const ps = normalizeProposedSolution(prev.proposed_solution);
      return { ...prev, proposed_solution: { ...ps, success_metrics: (ps.success_metrics ?? []).filter((_, idx) => idx !== i) } };
    });
  }

  // ── Team helpers ──────────────────────────────────────────────────────────────

  function setTeamMember(i: number, field: 'role' | 'responsibility', val: string) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const arr = [...(prev.team ?? [])];
      arr[i] = { ...arr[i], [field]: val };
      return { ...prev, team: arr };
    });
  }

  function addTeamMember() {
    setEditContent((prev) => {
      if (!prev) return prev;
      return { ...prev, team: [...(prev.team ?? []), { role: '', responsibility: '' }] };
    });
  }

  function removeTeamMember(i: number) {
    setEditContent((prev) => {
      if (!prev) return prev;
      return { ...prev, team: (prev.team ?? []).filter((_, idx) => idx !== i) };
    });
  }

  // ── Risk helpers ──────────────────────────────────────────────────────────────

  function setRisk(i: number, field: 'risk' | 'mitigation', val: string) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const arr = [...(prev.risk_mitigation ?? [])];
      arr[i] = { ...arr[i], [field]: val };
      return { ...prev, risk_mitigation: arr };
    });
  }

  function addRisk() {
    setEditContent((prev) => {
      if (!prev) return prev;
      return { ...prev, risk_mitigation: [...(prev.risk_mitigation ?? []), { risk: '', mitigation: '' }] };
    });
  }

  function removeRisk(i: number) {
    setEditContent((prev) => {
      if (!prev) return prev;
      return { ...prev, risk_mitigation: (prev.risk_mitigation ?? []).filter((_, idx) => idx !== i) };
    });
  }

  // ── Assumption helpers ────────────────────────────────────────────────────────

  function setAssumption(i: number, val: string) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const arr = [...(prev.assumptions ?? [])];
      arr[i] = val;
      return { ...prev, assumptions: arr };
    });
  }

  function addAssumption() {
    setEditContent((prev) => {
      if (!prev) return prev;
      return { ...prev, assumptions: [...(prev.assumptions ?? []), ''] };
    });
  }

  function removeAssumption(i: number) {
    setEditContent((prev) => {
      if (!prev) return prev;
      return { ...prev, assumptions: (prev.assumptions ?? []).filter((_, idx) => idx !== i) };
    });
  }

  // ── Why Choose Us helpers ─────────────────────────────────────────────────────

  function setWhyChooseUs(i: number, val: string) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const arr = [...(prev.why_choose_us ?? [])];
      arr[i] = val;
      return { ...prev, why_choose_us: arr };
    });
  }

  function addWhyChooseUs() {
    setEditContent((prev) => {
      if (!prev) return prev;
      return { ...prev, why_choose_us: [...(prev.why_choose_us ?? []), ''] };
    });
  }

  function removeWhyChooseUs(i: number) {
    setEditContent((prev) => {
      if (!prev) return prev;
      return { ...prev, why_choose_us: (prev.why_choose_us ?? []).filter((_, idx) => idx !== i) };
    });
  }

  // ── Key Benefits helpers ──────────────────────────────────────────────────────

  function setKeyBenefit(i: number, field: 'title' | 'description', val: string) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const arr = [...(prev.key_benefits ?? [])];
      arr[i] = { ...arr[i], [field]: val };
      return { ...prev, key_benefits: arr };
    });
  }

  function addKeyBenefit() {
    setEditContent((prev) => {
      if (!prev) return prev;
      return { ...prev, key_benefits: [...(prev.key_benefits ?? []), { title: '', description: '' }] };
    });
  }

  function removeKeyBenefit(i: number) {
    setEditContent((prev) => {
      if (!prev) return prev;
      return { ...prev, key_benefits: (prev.key_benefits ?? []).filter((_, idx) => idx !== i) };
    });
  }

  // ── Resource Breakdown helpers ────────────────────────────────────────────────

  function setResourceBreakdown(i: number, field: 'resource' | 'rate' | 'hours' | 'amount', val: string) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const arr = [...(prev.investment.resource_breakdown ?? [])];
      const isNum = field !== 'resource';
      arr[i] = { ...arr[i], [field]: isNum ? Number(val) || 0 : val };
      // Auto-compute amount from rate * hours
      if (field === 'rate' || field === 'hours') {
        arr[i].amount = arr[i].rate * arr[i].hours;
      }
      return { ...prev, investment: { ...prev.investment, resource_breakdown: arr } };
    });
  }

  function addResourceBreakdown() {
    setEditContent((prev) => {
      if (!prev) return prev;
      const rb = [...(prev.investment.resource_breakdown ?? []), { resource: '', rate: 0, hours: 0, amount: 0 }];
      return { ...prev, investment: { ...prev.investment, resource_breakdown: rb } };
    });
  }

  function removeResourceBreakdown(i: number) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const rb = (prev.investment.resource_breakdown ?? []).filter((_, idx) => idx !== i);
      return { ...prev, investment: { ...prev.investment, resource_breakdown: rb } };
    });
  }

  // ── Pain Point helpers ────────────────────────────────────────────────────────

  function setPainPoint(i: number, field: 'title', val: string) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const arr = [...(prev.client_pain_points ?? [])];
      arr[i] = { ...arr[i], [field]: val };
      return { ...prev, client_pain_points: arr };
    });
  }

  function setPainPointBullet(i: number, j: number, val: string) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const arr = [...(prev.client_pain_points ?? [])];
      const points = [...(arr[i]?.points ?? [])];
      points[j] = val;
      arr[i] = { ...arr[i], points };
      return { ...prev, client_pain_points: arr };
    });
  }

  function addPainPointBullet(i: number) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const arr = [...(prev.client_pain_points ?? [])];
      arr[i] = { ...arr[i], points: [...(arr[i]?.points ?? []), ''] };
      return { ...prev, client_pain_points: arr };
    });
  }

  function removePainPointBullet(i: number, j: number) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const arr = [...(prev.client_pain_points ?? [])];
      arr[i] = { ...arr[i], points: arr[i].points.filter((_, idx) => idx !== j) };
      return { ...prev, client_pain_points: arr };
    });
  }

  function addPainPoint() {
    setEditContent((prev) => {
      if (!prev) return prev;
      return { ...prev, client_pain_points: [...(prev.client_pain_points ?? []), { title: '', points: [''] }] };
    });
  }

  function removePainPoint(i: number) {
    setEditContent((prev) => {
      if (!prev) return prev;
      return { ...prev, client_pain_points: (prev.client_pain_points ?? []).filter((_, idx) => idx !== i) };
    });
  }

  // ── Platform & Technology helpers ─────────────────────────────────────────────

  function setPlatformTech(i: number, field: 'label' | 'value', val: string) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const arr = [...(prev.platform_tech ?? [])];
      arr[i] = { ...arr[i], [field]: val };
      return { ...prev, platform_tech: arr };
    });
  }

  function addPlatformTech() {
    setEditContent((prev) => {
      if (!prev) return prev;
      return { ...prev, platform_tech: [...(prev.platform_tech ?? []), { label: '', value: '' }] };
    });
  }

  function removePlatformTech(i: number) {
    setEditContent((prev) => {
      if (!prev) return prev;
      return { ...prev, platform_tech: (prev.platform_tech ?? []).filter((_, idx) => idx !== i) };
    });
  }

  // ── Maintenance & Support helpers ─────────────────────────────────────────────

  function setMaintenancePeriod(val: string) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const ms = prev.maintenance_support ?? { period: '', includes: [] };
      return { ...prev, maintenance_support: { ...ms, period: val } };
    });
  }

  function setMaintenanceSupportItem(i: number, val: string) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const ms = prev.maintenance_support ?? { period: '', includes: [] };
      const includes = [...ms.includes];
      includes[i] = val;
      return { ...prev, maintenance_support: { ...ms, includes } };
    });
  }

  function addMaintenanceSupportItem() {
    setEditContent((prev) => {
      if (!prev) return prev;
      const ms = prev.maintenance_support ?? { period: '', includes: [] };
      return { ...prev, maintenance_support: { ...ms, includes: [...ms.includes, ''] } };
    });
  }

  function removeMaintenanceSupportItem(i: number) {
    setEditContent((prev) => {
      if (!prev) return prev;
      const ms = prev.maintenance_support ?? { period: '', includes: [] };
      return { ...prev, maintenance_support: { ...ms, includes: ms.includes.filter((_, idx) => idx !== i) } };
    });
  }

  // ── Computed ──────────────────────────────────────────────────────────────────
  const activeTheme  = THEMES.find((t) => t.value === theme) ?? THEMES[0];
  const isGenerating = step === 'streaming' || generateMutation.isPending;
  const charCount    = notes.trim().length;
  const isSaving     = saveMutation.isPending;

  // ── Input style helpers ───────────────────────────────────────────────────────
  const inputCls  = 'w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-transparent transition-all bg-white';
  const textaCls  = `${inputCls} resize-none`;
  const labelCls  = 'block text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-1.5';

  // ── Render ────────────────────────────────────────────────────────────────────

  if (mode === 'loading') {
    return (
      <div className="flex h-[calc(100vh-56px)] items-center justify-center" style={{ background: '#0d0f14' }}>
        <div className="flex flex-col items-center gap-4">
          <svg className="w-8 h-8 animate-spin text-indigo-400" fill="none" viewBox="0 0 24 24">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          <span className="text-sm text-white/40">Loading…</span>
        </div>
      </div>
    );
  }

  return (
    <div className="flex flex-col h-[calc(100vh-56px)] overflow-hidden" style={{ background: '#0d0f14' }}>

      {/* ══════════════════ TOP BAR ══════════════════ */}
      <div className="flex items-center justify-between px-6 py-3 border-b shrink-0"
        style={{ borderColor: 'rgba(255,255,255,0.07)', background: '#13151c' }}>
        <div className="flex items-center gap-4">
          <Link
            href={`/leads/${leadId}`}
            className="flex items-center gap-1.5 text-xs font-medium transition-colors"
            style={{ color: 'rgba(255,255,255,0.45)' }}
          >
            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
            Back
          </Link>
          <div className="w-px h-4" style={{ background: 'rgba(255,255,255,0.1)' }} />
          <div className="flex items-center gap-2.5">
            <div className={`w-6 h-6 rounded-md bg-gradient-to-br ${activeTheme.gradient} flex items-center justify-center`}>
              <svg className="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
              </svg>
            </div>
            <span className="text-sm font-semibold" style={{ color: 'rgba(255,255,255,0.9)' }}>
              {mode === 'edit' ? 'Proposal' : 'AI Proposal Generator'}
            </span>
            {mode === 'edit' && proposal && (
              <span className="text-xs px-2 py-0.5 rounded-md font-medium"
                style={{ background: 'rgba(99,102,241,0.18)', color: 'rgba(165,180,252,0.9)' }}>
                #{proposal.id}
              </span>
            )}
          </div>
        </div>

        {/* Lead chip */}
        {lead && (
          <div className="flex items-center gap-2.5 px-3 py-1.5 rounded-full"
            style={{ background: 'rgba(255,255,255,0.06)', border: '1px solid rgba(255,255,255,0.09)' }}>
            <div className={`w-5 h-5 rounded-full bg-gradient-to-br ${activeTheme.gradient} flex items-center justify-center text-[10px] font-bold text-white`}>
              {lead.full_name.charAt(0).toUpperCase()}
            </div>
            <span className="text-xs font-medium" style={{ color: 'rgba(255,255,255,0.7)' }}>
              {lead.full_name}
              {lead.company && <span style={{ color: 'rgba(255,255,255,0.35)' }}> · {lead.company}</span>}
            </span>
            {lead.stage?.name && (
              <>
                <div className="w-px h-3" style={{ background: 'rgba(255,255,255,0.15)' }} />
                <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded-md"
                  style={{ background: 'rgba(99,102,241,0.2)', color: 'rgba(165,180,252,1)' }}>
                  {lead.stage.name}
                </span>
              </>
            )}
          </div>
        )}

        {/* Status chips */}
        <div className="flex items-center gap-2">
          {saveSuccess && (
            <motion.div
              initial={{ opacity: 0, scale: 0.9 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0 }}
              className="flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-full"
              style={{ background: 'rgba(16,185,129,0.15)', color: 'rgba(110,231,183,0.9)' }}>
              <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 13l4 4L19 7" />
              </svg>
              Saved
            </motion.div>
          )}
          {step === 'streaming' && (
            <div className="flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-full"
              style={{ background: 'rgba(99,102,241,0.15)', color: 'rgba(165,180,252,0.9)' }}>
              <span className="w-1.5 h-1.5 rounded-full bg-indigo-400 animate-pulse" />
              Writing…
            </div>
          )}
          {mode === 'edit' && (
            <div className="text-xs px-2.5 py-1 rounded-full font-semibold capitalize"
              style={{
                background: propStatus === 'accepted' ? 'rgba(16,185,129,0.15)'
                  : propStatus === 'sent' ? 'rgba(59,130,246,0.15)'
                  : propStatus === 'rejected' ? 'rgba(239,68,68,0.15)'
                  : 'rgba(255,255,255,0.08)',
                color: propStatus === 'accepted' ? 'rgba(110,231,183,0.9)'
                  : propStatus === 'sent' ? 'rgba(147,197,253,0.9)'
                  : propStatus === 'rejected' ? 'rgba(252,165,165,0.9)'
                  : 'rgba(255,255,255,0.45)',
              }}>
              {propStatus}
            </div>
          )}
        </div>
      </div>

      {/* ══════════════════ MAIN CONTENT ══════════════════ */}
      <div className="flex flex-1 overflow-hidden">

        {/* ════════════ LEFT PANEL — fixed height, no scroll ════════════ */}
        <div className="w-[380px] flex-shrink-0 flex flex-col h-full overflow-hidden"
          style={{ background: '#13151c', borderRight: '1px solid rgba(255,255,255,0.07)' }}>

          {/* scrollable content area — only this part scrolls if needed, hidden scrollbar */}
          <div className="flex-1 flex flex-col gap-4 p-5 overflow-y-auto"
            style={{ scrollbarWidth: 'none' }}>

            {/* ── CREATE MODE: Notes + Theme ── */}
            {mode === 'create' && (
              <>
                {/* Lead context pills */}
                {lead && !leadLoading && (
                  <div className="flex flex-wrap gap-1.5">
                    {[lead.industry, lead.email, lead.phone].filter(Boolean).map((val) => (
                      <span key={val} className="text-xs px-2 py-0.5 rounded-md font-medium"
                        style={{ background: 'rgba(255,255,255,0.06)', color: 'rgba(255,255,255,0.4)', border: '1px solid rgba(255,255,255,0.07)' }}>
                        {val}
                      </span>
                    ))}
                  </div>
                )}

                {/* Notes textarea — flex-1 so it fills available space */}
                <div className="flex flex-col gap-1.5 flex-1 min-h-0">
                  <div className="flex items-center justify-between">
                    <label className="text-xs font-semibold uppercase tracking-widest" style={{ color: 'rgba(255,255,255,0.35)' }}>
                      Conversation Notes
                    </label>
                    <span className={`text-xs font-medium transition-colors ${charCount >= 20 ? 'text-emerald-400' : ''}`}
                      style={charCount < 20 ? { color: 'rgba(255,255,255,0.2)' } : {}}>
                      {charCount < 20 ? `${20 - charCount} more` : '✓ Ready'}
                    </span>
                  </div>
                  <textarea
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    disabled={isGenerating}
                    placeholder={`Describe your conversation with ${lead?.full_name ?? 'the client'}…\n\nInclude their pain points, budget, timeline, and key requirements.`}
                    className="flex-1 w-full text-sm resize-none focus:outline-none transition-all leading-relaxed rounded-xl px-4 py-3 disabled:opacity-50"
                    style={{
                      minHeight: '120px',
                      background: 'rgba(255,255,255,0.04)',
                      border: charCount >= 20 ? '1px solid rgba(99,102,241,0.4)' : '1px solid rgba(255,255,255,0.08)',
                      color: 'rgba(255,255,255,0.85)', caretColor: '#818cf8',
                    }}
                  />
                </div>

                {/* Theme picker */}
                <ThemePicker theme={theme} setTheme={setTheme} disabled={isGenerating} />

                {/* Error */}
                <AnimatePresence>
                  {errorMsg && (
                    <motion.div initial={{ opacity: 0, y: -6 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }}
                      className="rounded-xl px-3 py-2.5 text-xs leading-relaxed"
                      style={{ background: 'rgba(239,68,68,0.12)', border: '1px solid rgba(239,68,68,0.25)', color: 'rgba(252,165,165,1)' }}>
                      {errorMsg}
                    </motion.div>
                  )}
                </AnimatePresence>
              </>
            )}

            {/* ── EDIT MODE: Theme + Status + Valid Until ── */}
            {mode === 'edit' && editContent && (
              <>
                {/* Theme picker */}
                <ThemePicker theme={theme} setTheme={setTheme} disabled={isSaving} />

                {/* Status + Valid Until side by side */}
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="text-xs font-semibold uppercase tracking-widest block mb-1.5"
                      style={{ color: 'rgba(255,255,255,0.35)' }}>Status</label>
                    <select
                      value={propStatus}
                      onChange={(e) => setPropStatus(e.target.value as ProposalStatus)}
                      className="w-full rounded-xl text-xs font-medium px-2.5 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-400"
                      style={{ background: 'rgba(255,255,255,0.06)', border: '1px solid rgba(255,255,255,0.1)', color: 'rgba(255,255,255,0.8)' }}
                    >
                      <option value="draft">Draft</option>
                      <option value="sent">Sent</option>
                      <option value="accepted">Accepted</option>
                      <option value="rejected">Rejected</option>
                    </select>
                  </div>
                  <div>
                    <label className="text-xs font-semibold uppercase tracking-widest block mb-1.5"
                      style={{ color: 'rgba(255,255,255,0.35)' }}>Valid Until</label>
                    <input
                      type="date"
                      value={validUntil}
                      onChange={(e) => setValidUntil(e.target.value)}
                      className="w-full rounded-xl text-xs px-2.5 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-400"
                      style={{ background: 'rgba(255,255,255,0.06)', border: '1px solid rgba(255,255,255,0.1)', color: 'rgba(255,255,255,0.7)', colorScheme: 'dark' }}
                    />
                  </div>
                </div>

                {/* Error */}
                <AnimatePresence>
                  {saveMutation.isError && (
                    <motion.div initial={{ opacity: 0, y: -6 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }}
                      className="rounded-xl px-3 py-2.5 text-xs"
                      style={{ background: 'rgba(239,68,68,0.12)', border: '1px solid rgba(239,68,68,0.25)', color: 'rgba(252,165,165,1)' }}>
                      Failed to save. Please try again.
                    </motion.div>
                  )}
                </AnimatePresence>
              </>
            )}
          </div>

          {/* ── Bottom actions — always visible, never scrolls ── */}
          <div className="p-4 shrink-0 flex flex-col gap-2"
            style={{ borderTop: '1px solid rgba(255,255,255,0.07)', background: '#0d0f14' }}>

            {mode === 'create' && (
              <button
                onClick={handleGenerate}
                disabled={isGenerating}
                className={`w-full py-3.5 rounded-xl text-sm font-bold text-white transition-all duration-200 disabled:cursor-not-allowed disabled:opacity-60 bg-gradient-to-r ${activeTheme.gradient}`}
              >
                <span className="flex items-center justify-center gap-2">
                  {isGenerating ? (
                    <>
                      <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                      </svg>
                      {step === 'streaming'
                        ? 'Writing proposal…'
                        : GEN_STEPS[genStep]?.buttonLabel ?? 'Preparing…'}
                    </>
                  ) : (
                    <>
                      <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                      </svg>
                      Generate Proposal with AI
                    </>
                  )}
                </span>
              </button>
            )}

            {mode === 'edit' && (
              <>
                {/* Save */}
                <button
                  onClick={() => saveMutation.mutate()}
                  disabled={isSaving}
                  className="w-full py-3 rounded-xl text-sm font-bold text-white transition-all disabled:opacity-60 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                  style={{ background: 'rgba(99,102,241,0.9)' }}
                >
                  {isSaving ? (
                    <>
                      <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                      </svg>
                      Saving…
                    </>
                  ) : (
                    <>
                      <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" />
                      </svg>
                      Save Changes
                    </>
                  )}
                </button>

                {/* Download PDF */}
                <button
                  onClick={handleDownloadPDF}
                  disabled={isSaving}
                  className={`w-full py-2.5 rounded-xl text-sm font-semibold text-white transition-all disabled:opacity-60 flex items-center justify-center gap-2 bg-gradient-to-r ${activeTheme.gradient}`}
                >
                  <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                      d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                  </svg>
                  Download PDF
                </button>

                {/* Regenerate (goes back to create mode) */}
                <button
                  onClick={() => {
                    setMode('create');
                    setStep('idle');
                    setDisplayedBlocks([]);
                    setNotes('');
                  }}
                  className="w-full py-2 rounded-xl text-xs font-medium transition-all flex items-center justify-center gap-1.5"
                  style={{ color: 'rgba(255,255,255,0.3)', background: 'transparent' }}
                >
                  <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                  </svg>
                  Regenerate with new notes
                </button>
              </>
            )}
          </div>
        </div>

        {/* ════════════ RIGHT PANEL ════════════ */}
        <div ref={rightRef} className="flex-1 overflow-y-auto no-scrollbar relative" style={{ background: '#f9fafb' }}>
          <AnimatePresence mode="wait">

            {/* ── IDLE (create mode, no generation yet) ── */}
            {mode === 'create' && step === 'idle' && !generateMutation.isPending && (
              <motion.div key="idle" initial={{ opacity: 0 }} animate={{ opacity: 1 }}
                exit={{ opacity: 0, transition: { duration: 0 } }}
                transition={{ duration: 0.15 }}
                className="absolute inset-0 flex flex-col items-center justify-center text-center px-16 select-none">
                <div className="relative mb-10">
                  <div className={`w-28 h-28 rounded-3xl bg-gradient-to-br ${activeTheme.gradient} flex items-center justify-center shadow-2xl`}
                    style={{ boxShadow: '0 20px 60px rgba(99,102,241,0.25)' }}>
                    <svg className="w-14 h-14 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                        d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                  </div>
                  <div className="absolute -inset-3 rounded-[28px] opacity-20 blur-xl"
                    style={{ background: 'radial-gradient(circle, rgba(99,102,241,0.6) 0%, transparent 70%)' }} />
                </div>
                <h2 className="text-2xl font-bold text-gray-900 mb-3 tracking-tight">
                  Your proposal will appear here
                </h2>
                <p className="text-gray-400 text-sm max-w-sm leading-relaxed">
                  Describe your conversation on the left, choose a theme, and hit{' '}
                  <span className="font-semibold text-indigo-500">Generate</span>.
                  The AI writes each section live — right here.
                </p>
                <div className="flex flex-wrap items-center justify-center gap-2 mt-10 max-w-lg">
                  {[{ label: 'Executive Summary', icon: '📋' }, { label: 'Scope of Work', icon: '📦' },
                    { label: 'Timeline', icon: '📅' }, { label: 'Investment', icon: '💰' }, { label: 'Next Steps', icon: '🚀' }
                  ].map(({ label, icon }) => (
                    <span key={label} className="flex items-center gap-1.5 text-xs text-gray-400 bg-white border border-gray-200 px-3 py-1.5 rounded-full shadow-sm">
                      <span>{icon}</span> {label}
                    </span>
                  ))}
                </div>
              </motion.div>
            )}

            {/* ── LOADING (API call in-flight, before streaming starts) ── */}
            {mode === 'create' && step === 'idle' && generateMutation.isPending && (
              <motion.div key="loading" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
                transition={{ duration: 0.15 }}>
                <GeneratingSkeleton onStepChange={setGenStep} />
              </motion.div>
            )}

            {/* ── STREAMING ── */}
            {mode === 'create' && (step === 'streaming' || step === 'done') && (
              <motion.div key="streaming" initial={{ opacity: 0 }} animate={{ opacity: 1 }}
                transition={{ duration: 0.2 }}
                className="max-w-3xl mx-auto px-10 py-10">
                <div className="space-y-7">
                  {displayedBlocks.map((block, i) => {
                    if (!block) return null; // guard against stale async state during transitions
                    const isLastBlock = i === displayedBlocks.length - 1;
                    const showCursor  = isLastBlock && step === 'streaming' && !block.done;
                    if (block.isTitle) {
                      return (
                        <motion.div key={block.id} initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }}
                          className="pb-7" style={{ borderBottom: '1px solid #e5e7eb' }}>
                          <div className="flex items-center gap-2 mb-4">
                            <div className={`w-5 h-5 rounded-md bg-gradient-to-br ${activeTheme.gradient} flex items-center justify-center`}>
                              <svg className="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                              </svg>
                            </div>
                            <span className="text-[10px] font-bold uppercase tracking-widest text-gray-400">
                              AI Generated Proposal
                            </span>
                          </div>
                          <h1 className="text-[28px] font-bold text-gray-900 leading-snug tracking-tight">
                            {block.visibleText}{showCursor && <Cursor />}
                          </h1>
                        </motion.div>
                      );
                    }
                    if (block.isFootnote) {
                      return (
                        <motion.div key={block.id} initial={{ opacity: 0 }} animate={{ opacity: 1 }}
                          className="pt-5 text-xs text-gray-400 italic" style={{ borderTop: '1px dashed #e5e7eb' }}>
                          {block.visibleText}{showCursor && <Cursor />}
                        </motion.div>
                      );
                    }
                    return (
                      <motion.div key={block.id} initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.3 }}>
                        <div className="flex items-center gap-2.5 mb-3">
                          <div className={`h-[18px] w-0.5 rounded-full bg-gradient-to-b ${activeTheme.gradient}`} />
                          <span className="text-[10px] font-bold uppercase tracking-widest text-gray-400">
                            {block.icon} {block.heading}
                          </span>
                        </div>
                        <div className="rounded-2xl bg-white shadow-sm overflow-hidden" style={{ border: '1px solid rgba(0,0,0,0.06)' }}>
                          <p className="text-sm text-gray-700 leading-[1.85] whitespace-pre-wrap px-6 py-5">
                            {block.visibleText}{showCursor && <Cursor />}
                          </p>
                        </div>
                      </motion.div>
                    );
                  })}
                </div>

                {step === 'done' && (
                  <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.25 }}
                    className="mt-10 pt-6 flex items-center gap-3" style={{ borderTop: '1px solid #e5e7eb' }}>
                    <div className="w-6 h-6 rounded-full bg-emerald-50 border border-emerald-200 flex items-center justify-center">
                      <svg className="w-3.5 h-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 13l4 4L19 7" />
                      </svg>
                    </div>
                    <span className="text-sm font-semibold text-gray-800">Proposal generated · Switching to edit mode…</span>
                  </motion.div>
                )}
              </motion.div>
            )}

            {/* ── EDIT MODE: Editable form ── */}
            {mode === 'edit' && editContent && (
              <motion.div key="edit" initial={{ opacity: 0 }} animate={{ opacity: 1 }}
                className="max-w-3xl mx-auto px-10 py-10">

                <div className="space-y-8">

                  {/* Title */}
                  <div>
                    <div className="flex items-center gap-2 mb-3">
                      <div className={`w-5 h-5 rounded-md bg-gradient-to-br ${activeTheme.gradient} flex items-center justify-center`}>
                        <svg className="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                        </svg>
                      </div>
                      <span className="text-[10px] font-bold uppercase tracking-widest text-gray-400">Proposal Title</span>
                    </div>
                    <input
                      value={editContent.title}
                      onChange={(e) => setField('title', e.target.value)}
                      className="w-full text-2xl font-bold text-gray-900 bg-transparent border-0 border-b-2 border-gray-200 focus:outline-none focus:border-indigo-400 pb-2 transition-colors"
                      placeholder="Proposal title…"
                    />
                  </div>

                  {/* Executive Summary */}
                  <EditSection icon="📋" heading="Executive Summary" gradient={activeTheme.gradient}>
                    <textarea rows={6} value={editContent.executive_summary}
                      onChange={(e) => setField('executive_summary', e.target.value)}
                      className={textaCls} placeholder="Executive summary…" />
                  </EditSection>

                  {/* About Us */}
                  <EditSection icon="🏢" heading="About Us" gradient={activeTheme.gradient}>
                    <textarea rows={3} value={editContent.about_us ?? ''}
                      onChange={(e) => setField('about_us', e.target.value)}
                      className={textaCls} placeholder="2-3 sentences about your company, expertise, and experience…" />
                  </EditSection>

                  {/* Why Choose Us */}
                  <EditSection icon="⭐" heading="Why Choose Us" gradient={activeTheme.gradient}>
                    <div className="space-y-2">
                      {(editContent.why_choose_us ?? []).map((item, i) => (
                        <div key={i} className="flex items-center gap-2">
                          <span className="text-xs font-bold text-gray-400 w-4 shrink-0">{i + 1}</span>
                          <input value={item} onChange={(e) => setWhyChooseUs(i, e.target.value)}
                            placeholder="Key differentiator…"
                            className="flex-1 border border-gray-200 rounded-xl px-3 py-2 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                          <button onClick={() => removeWhyChooseUs(i)}
                            className="p-1.5 rounded-lg text-gray-300 hover:text-red-400 hover:bg-red-50 transition-colors">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                            </svg>
                          </button>
                        </div>
                      ))}
                      <button onClick={addWhyChooseUs}
                        className="w-full py-2 border-2 border-dashed border-gray-200 rounded-xl text-sm text-gray-400 hover:border-indigo-300 hover:text-indigo-500 transition-colors flex items-center justify-center gap-2">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                        </svg>
                        Add Differentiator
                      </button>
                    </div>
                  </EditSection>

                  {/* Understanding */}
                  <EditSection icon="🎯" heading="Our Understanding" gradient={activeTheme.gradient}>
                    <textarea rows={4} value={editContent.understanding}
                      onChange={(e) => setField('understanding', e.target.value)}
                      className={textaCls} placeholder="Understanding of client needs…" />
                  </EditSection>

                  {/* Problems Identified */}
                  <EditSection icon="⚠️" heading="Problems Identified" gradient={activeTheme.gradient}>
                    <div className="space-y-3">
                      {(editContent.client_pain_points ?? []).map((card, i) => (
                        <div key={i} className="bg-white rounded-xl border border-gray-200 p-3">
                          <div className="flex items-center gap-2 mb-2">
                            <input value={card.title} onChange={(e) => setPainPoint(i, 'title', e.target.value)}
                              placeholder="Problem area title (e.g. Outdated Visual Presentation)"
                              className="flex-1 border border-gray-200 rounded-lg px-3 py-1.5 text-sm font-semibold text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                            <button onClick={() => removePainPoint(i)}
                              className="p-1.5 rounded-lg text-gray-300 hover:text-red-400 hover:bg-red-50 transition-colors">
                              <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                              </svg>
                            </button>
                          </div>
                          <div className="space-y-1.5 ml-1">
                            {card.points.map((pt, j) => (
                              <div key={j} className="flex items-center gap-1.5">
                                <span className="text-gray-300 text-xs">•</span>
                                <input value={pt} onChange={(e) => setPainPointBullet(i, j, e.target.value)}
                                  placeholder="Specific problem…"
                                  className="flex-1 border border-gray-100 rounded-lg px-2.5 py-1 text-xs text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                                <button onClick={() => removePainPointBullet(i, j)}
                                  className="p-1 rounded text-gray-200 hover:text-red-400 transition-colors">
                                  <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                  </svg>
                                </button>
                              </div>
                            ))}
                            <button onClick={() => addPainPointBullet(i)}
                              className="ml-3 text-xs text-indigo-400 hover:text-indigo-600 transition-colors flex items-center gap-1 mt-1">
                              <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                              </svg>
                              Add bullet
                            </button>
                          </div>
                        </div>
                      ))}
                      <button onClick={addPainPoint}
                        className="w-full py-2 border-2 border-dashed border-gray-200 rounded-xl text-sm text-gray-400 hover:border-indigo-300 hover:text-indigo-500 transition-colors flex items-center justify-center gap-2">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                        </svg>
                        Add Problem Card
                      </button>
                    </div>
                  </EditSection>

                  {/* Key Benefits */}
                  <EditSection icon="✨" heading="Key Benefits" gradient={activeTheme.gradient}>
                    <div className="space-y-3">
                      {(editContent.key_benefits ?? []).map((b, i) => (
                        <div key={i} className="bg-white rounded-xl border border-gray-200 p-3">
                          <div className="flex items-center gap-2 mb-2">
                            <span className="text-xs font-bold text-gray-400 w-5 text-center">{i + 1}</span>
                            <input value={b.title} onChange={(e) => setKeyBenefit(i, 'title', e.target.value)}
                              placeholder="Benefit title"
                              className="flex-1 border border-gray-200 rounded-lg px-3 py-1.5 text-sm font-semibold text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                            <button onClick={() => removeKeyBenefit(i)}
                              className="p-1.5 rounded-lg text-gray-300 hover:text-red-400 hover:bg-red-50 transition-colors">
                              <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                              </svg>
                            </button>
                          </div>
                          <input value={b.description} onChange={(e) => setKeyBenefit(i, 'description', e.target.value)}
                            placeholder="Measurable outcome (e.g. 40% reduction in overhead)"
                            className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm text-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                        </div>
                      ))}
                      <button onClick={addKeyBenefit}
                        className="w-full py-2 border-2 border-dashed border-gray-200 rounded-xl text-sm text-gray-400 hover:border-indigo-300 hover:text-indigo-500 transition-colors flex items-center justify-center gap-2">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                        </svg>
                        Add Benefit
                      </button>
                    </div>
                  </EditSection>

                  {/* Proposed Solution */}
                  {(() => {
                    const ps = normalizeProposedSolution(editContent.proposed_solution);
                    return (
                      <EditSection icon="💡" heading="Proposed Solution" gradient={activeTheme.gradient}>
                        <div className="space-y-4">
                          <div>
                            <label className={labelCls}>Overview</label>
                            <textarea rows={5} value={ps.overview} onChange={(e) => setPS('overview', e.target.value)}
                              className={textaCls} placeholder="Detailed solution overview (3-4 paragraphs)…" />
                          </div>
                          <div className="grid grid-cols-2 gap-3">
                            <div>
                              <label className={labelCls}>Approach / Methodology</label>
                              <textarea rows={2} value={ps.approach} onChange={(e) => setPS('approach', e.target.value)}
                                className={textaCls} placeholder="How you will work…" />
                            </div>
                            <div>
                              <label className={labelCls}>Delivery Model</label>
                              <textarea rows={2} value={ps.implementation_approach} onChange={(e) => setPS('implementation_approach', e.target.value)}
                                className={textaCls} placeholder="Agile / phased / waterfall…" />
                            </div>
                          </div>
                          <div>
                            <label className={labelCls}>Technology Stack (comma-separated)</label>
                            <input
                              value={(ps.technology_stack ?? []).join(', ')}
                              onChange={(e) => setPS('technology_stack', e.target.value.split(',').map((t) => t.trim()).filter(Boolean))}
                              className={inputCls} placeholder="React, Node.js, PostgreSQL…" />
                          </div>
                          <div>
                            <label className={labelCls}>Key Features</label>
                            <div className="space-y-2">
                              {(ps.key_features ?? []).map((f, i) => (
                                <div key={i} className="flex gap-2 items-start">
                                  <div className="flex-1 space-y-1">
                                    <input value={f.feature} onChange={(e) => setPSFeature(i, 'feature', e.target.value)}
                                      placeholder="Feature name"
                                      className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm font-medium text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                                    <input value={f.benefit} onChange={(e) => setPSFeature(i, 'benefit', e.target.value)}
                                      placeholder="Client benefit"
                                      className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm text-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                                  </div>
                                  <button onClick={() => removePSFeature(i)}
                                    className="p-1.5 rounded-lg text-gray-300 hover:text-red-400 hover:bg-red-50 transition-colors mt-1">
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                  </button>
                                </div>
                              ))}
                              <button onClick={addPSFeature}
                                className="w-full py-2 border-2 border-dashed border-gray-200 rounded-xl text-sm text-gray-400 hover:border-indigo-300 hover:text-indigo-500 transition-colors flex items-center justify-center gap-2">
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                                </svg>
                                Add Feature
                              </button>
                            </div>
                          </div>
                          <div>
                            <label className={labelCls}>Success Metrics</label>
                            <div className="space-y-2">
                              {(ps.success_metrics ?? []).map((m, i) => (
                                <div key={i} className="flex gap-2 items-center">
                                  <input value={m.metric} onChange={(e) => setPSMetric(i, 'metric', e.target.value)}
                                    placeholder="Metric"
                                    className="flex-1 border border-gray-200 rounded-lg px-3 py-1.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                                  <input value={m.target} onChange={(e) => setPSMetric(i, 'target', e.target.value)}
                                    placeholder="Target"
                                    className="flex-1 border border-gray-200 rounded-lg px-3 py-1.5 text-sm text-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                                  <button onClick={() => removePSMetric(i)}
                                    className="p-1.5 rounded-lg text-gray-300 hover:text-red-400 hover:bg-red-50 transition-colors">
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                  </button>
                                </div>
                              ))}
                              <button onClick={addPSMetric}
                                className="w-full py-2 border-2 border-dashed border-gray-200 rounded-xl text-sm text-gray-400 hover:border-indigo-300 hover:text-indigo-500 transition-colors flex items-center justify-center gap-2">
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                                </svg>
                                Add Metric
                              </button>
                            </div>
                          </div>
                          <div>
                            <label className={labelCls}>What Sets Us Apart</label>
                            <textarea rows={2} value={ps.differentiators} onChange={(e) => setPS('differentiators', e.target.value)}
                              className={textaCls} placeholder="What makes this solution unique…" />
                          </div>
                        </div>
                      </EditSection>
                    );
                  })()}

                  {/* Scope of Work */}
                  <EditSection icon="📦" heading="Scope of Work" gradient={activeTheme.gradient}>
                    <div className="space-y-3">
                      {editContent.scope_of_work.map((s, i) => (
                        <div key={i} className="bg-white rounded-xl border border-gray-200 p-4">
                          <div className="flex items-center gap-2 mb-2">
                            <span className="text-xs font-bold text-gray-400 w-5 text-center">{i + 1}</span>
                            <input value={s.item} onChange={(e) => setScope(i, 'item', e.target.value)}
                              placeholder="Deliverable name"
                              className="flex-1 border border-gray-200 rounded-lg px-3 py-1.5 text-sm font-semibold text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                            <button onClick={() => removeScope(i)}
                              className="p-1.5 rounded-lg text-gray-300 hover:text-red-400 hover:bg-red-50 transition-colors">
                              <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                              </svg>
                            </button>
                          </div>
                          <textarea rows={2} value={s.description} onChange={(e) => setScope(i, 'description', e.target.value)}
                            placeholder="Description…"
                            className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-600 resize-none focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all ml-7"
                            style={{ width: 'calc(100% - 1.75rem)' }} />
                        </div>
                      ))}
                      <button onClick={addScope}
                        className="w-full py-2 border-2 border-dashed border-gray-200 rounded-xl text-sm text-gray-400 hover:border-indigo-300 hover:text-indigo-500 transition-colors flex items-center justify-center gap-2">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                        </svg>
                        Add Deliverable
                      </button>
                    </div>
                  </EditSection>

                  {/* Our Team */}
                  <EditSection icon="👥" heading="Our Team" gradient={activeTheme.gradient}>
                    <div className="space-y-3">
                      {(editContent.team ?? []).map((m, i) => (
                        <div key={i} className="flex gap-2 items-start">
                          <div className="flex-1 grid grid-cols-2 gap-2">
                            <input value={m.role} onChange={(e) => setTeamMember(i, 'role', e.target.value)}
                              placeholder="Role (e.g. Project Manager)"
                              className="border border-gray-200 rounded-lg px-3 py-2 text-sm font-medium text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                            <input value={m.responsibility} onChange={(e) => setTeamMember(i, 'responsibility', e.target.value)}
                              placeholder="Responsibility"
                              className="border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                          </div>
                          <button onClick={() => removeTeamMember(i)}
                            className="p-1.5 rounded-lg text-gray-300 hover:text-red-400 hover:bg-red-50 transition-colors mt-1">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                            </svg>
                          </button>
                        </div>
                      ))}
                      <button onClick={addTeamMember}
                        className="w-full py-2 border-2 border-dashed border-gray-200 rounded-xl text-sm text-gray-400 hover:border-indigo-300 hover:text-indigo-500 transition-colors flex items-center justify-center gap-2">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                        </svg>
                        Add Team Member
                      </button>
                    </div>
                  </EditSection>

                  {/* Timeline */}
                  <EditSection icon="📅" heading="Project Timeline" gradient={activeTheme.gradient}>
                    <div className="space-y-3">
                      {editContent.timeline.map((t, i) => (
                        <div key={i} className="bg-white rounded-xl border border-gray-200 p-4">
                          <div className="flex gap-2 mb-2">
                            <input value={t.phase} onChange={(e) => setTimeline(i, 'phase', e.target.value)}
                              placeholder="Phase name"
                              className="flex-1 border border-gray-200 rounded-lg px-3 py-1.5 text-sm font-semibold text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                            <input value={t.duration} onChange={(e) => setTimeline(i, 'duration', e.target.value)}
                              placeholder="Duration"
                              className="w-28 border border-gray-200 rounded-lg px-3 py-1.5 text-sm text-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                            <button onClick={() => removeTimeline(i)}
                              className="p-1.5 rounded-lg text-gray-300 hover:text-red-400 hover:bg-red-50 transition-colors">
                              <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                              </svg>
                            </button>
                          </div>
                          <textarea rows={2} value={t.deliverables} onChange={(e) => setTimeline(i, 'deliverables', e.target.value)}
                            placeholder="Deliverables…"
                            className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-600 resize-none focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                        </div>
                      ))}
                      <button onClick={addTimeline}
                        className="w-full py-2 border-2 border-dashed border-gray-200 rounded-xl text-sm text-gray-400 hover:border-indigo-300 hover:text-indigo-500 transition-colors flex items-center justify-center gap-2">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                        </svg>
                        Add Phase
                      </button>
                    </div>
                  </EditSection>

                  {/* Platform & Technology */}
                  <EditSection icon="🔧" heading="Platform & Technology" gradient={activeTheme.gradient}>
                    <div className="space-y-2">
                      {(editContent.platform_tech ?? []).map((pt, i) => (
                        <div key={i} className="flex items-center gap-2">
                          <input value={pt.label} onChange={(e) => setPlatformTech(i, 'label', e.target.value)}
                            placeholder="Label (e.g. Platform)"
                            className="w-32 border border-gray-200 rounded-lg px-3 py-2 text-sm font-semibold text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                          <input value={pt.value} onChange={(e) => setPlatformTech(i, 'value', e.target.value)}
                            placeholder="Value (e.g. React + Node.js)"
                            className="flex-1 border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                          <button onClick={() => removePlatformTech(i)}
                            className="p-1.5 rounded-lg text-gray-300 hover:text-red-400 hover:bg-red-50 transition-colors">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                            </svg>
                          </button>
                        </div>
                      ))}
                      <button onClick={addPlatformTech}
                        className="w-full py-2 border-2 border-dashed border-gray-200 rounded-xl text-sm text-gray-400 hover:border-indigo-300 hover:text-indigo-500 transition-colors flex items-center justify-center gap-2">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                        </svg>
                        Add Row
                      </button>
                    </div>
                  </EditSection>

                  {/* Investment */}
                  <EditSection icon="💰" heading="Investment" gradient={activeTheme.gradient}>
                    <div className="space-y-4">
                      {/* Resource Breakdown */}
                      {(editContent.investment.resource_breakdown ?? []).length > 0 && (
                        <div>
                          <label className={labelCls}>Resource Breakdown</label>
                          <div className="space-y-2">
                            {(editContent.investment.resource_breakdown ?? []).map((r, i) => (
                              <div key={i} className="grid grid-cols-4 gap-2 items-center">
                                <input value={r.resource} onChange={(e) => setResourceBreakdown(i, 'resource', e.target.value)}
                                  placeholder="Resource"
                                  className="col-span-2 border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                                <div className="flex items-center border border-gray-200 rounded-lg overflow-hidden">
                                  <span className="px-2 text-xs text-gray-400 bg-gray-50 border-r border-gray-200 py-2">₹/hr</span>
                                  <input type="number" value={r.rate || ''} onChange={(e) => setResourceBreakdown(i, 'rate', e.target.value)}
                                    placeholder="0" className="w-full px-2 py-2 text-sm text-gray-800 focus:outline-none" />
                                </div>
                                <div className="flex items-center gap-1">
                                  <input type="number" value={r.hours || ''} onChange={(e) => setResourceBreakdown(i, 'hours', e.target.value)}
                                    placeholder="hrs"
                                    className="flex-1 border border-gray-200 rounded-lg px-2 py-2 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                                  <button onClick={() => removeResourceBreakdown(i)}
                                    className="p-1.5 rounded-lg text-gray-300 hover:text-red-400 hover:bg-red-50 transition-colors">
                                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                  </button>
                                </div>
                              </div>
                            ))}
                          </div>
                        </div>
                      )}
                      <button onClick={addResourceBreakdown}
                        className="w-full py-1.5 border border-dashed border-gray-200 rounded-xl text-xs text-gray-400 hover:border-indigo-300 hover:text-indigo-500 transition-colors flex items-center justify-center gap-1.5">
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                        </svg>
                        Add Resource Row
                      </button>

                      {/* Summary breakdown */}
                      <div>
                        <label className={labelCls}>Summary Breakdown</label>
                        <div className="space-y-2">
                          {editContent.investment.breakdown.map((b, i) => (
                            <div key={i} className="flex items-center gap-2">
                              <input value={b.item} onChange={(e) => setBreakdown(i, 'item', e.target.value)}
                                placeholder="Line item"
                                className="flex-1 border border-gray-200 rounded-xl px-3 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                              <div className="flex items-center border border-gray-200 rounded-xl overflow-hidden focus-within:ring-2 focus-within:ring-indigo-400">
                                <span className="px-2.5 text-sm text-gray-400 bg-gray-50 border-r border-gray-200 py-2.5">₹</span>
                                <input type="number" value={b.amount || ''} onChange={(e) => setBreakdown(i, 'amount', e.target.value)}
                                  placeholder="0" className="w-28 px-3 py-2.5 text-sm text-gray-800 focus:outline-none" />
                              </div>
                              <button onClick={() => removeBreakdown(i)}
                                className="p-2 rounded-xl text-gray-300 hover:text-red-400 hover:bg-red-50 transition-colors">
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                </svg>
                              </button>
                            </div>
                          ))}
                          <button onClick={addBreakdown}
                            className="w-full py-2 border-2 border-dashed border-gray-200 rounded-xl text-sm text-gray-400 hover:border-indigo-300 hover:text-indigo-500 transition-colors flex items-center justify-center gap-2">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                            </svg>
                            Add Line Item
                          </button>
                        </div>
                      </div>

                      {/* Total */}
                      <div className="flex items-center justify-between bg-gray-900 text-white rounded-xl px-4 py-3">
                        <span className="text-sm font-bold">Total</span>
                        <span className="text-lg font-bold">
                          ₹{Number(editContent.investment.total).toLocaleString('en-IN')} {editContent.investment.currency}
                        </span>
                      </div>

                      {/* Payment Terms */}
                      <div>
                        <label className={labelCls}>Payment Terms</label>
                        <input value={editContent.investment.payment_terms}
                          onChange={(e) => setEditContent((prev) => prev ? {
                            ...prev, investment: { ...prev.investment, payment_terms: e.target.value },
                          } : prev)}
                          placeholder="e.g. 50% upfront, 25% at midpoint, 25% on completion"
                          className={inputCls} />
                      </div>
                    </div>
                  </EditSection>

                  {/* Risk & Mitigation */}
                  <EditSection icon="🛡️" heading="Risk & Mitigation" gradient={activeTheme.gradient}>
                    <div className="space-y-2">
                      {(editContent.risk_mitigation ?? []).map((r, i) => (
                        <div key={i} className="bg-white rounded-xl border border-gray-200 p-3">
                          <div className="flex gap-2 mb-2 items-center">
                            <input value={r.risk} onChange={(e) => setRisk(i, 'risk', e.target.value)}
                              placeholder="Potential risk"
                              className="flex-1 border border-gray-200 rounded-lg px-3 py-1.5 text-sm font-medium text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                            <button onClick={() => removeRisk(i)}
                              className="p-1.5 rounded-lg text-gray-300 hover:text-red-400 hover:bg-red-50 transition-colors">
                              <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                              </svg>
                            </button>
                          </div>
                          <input value={r.mitigation} onChange={(e) => setRisk(i, 'mitigation', e.target.value)}
                            placeholder="Mitigation strategy"
                            className="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-sm text-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                        </div>
                      ))}
                      <button onClick={addRisk}
                        className="w-full py-2 border-2 border-dashed border-gray-200 rounded-xl text-sm text-gray-400 hover:border-indigo-300 hover:text-indigo-500 transition-colors flex items-center justify-center gap-2">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                        </svg>
                        Add Risk
                      </button>
                    </div>
                  </EditSection>

                  {/* Assumptions */}
                  <EditSection icon="📌" heading="Assumptions" gradient={activeTheme.gradient}>
                    <div className="space-y-2">
                      {(editContent.assumptions ?? []).map((a, i) => (
                        <div key={i} className="flex items-center gap-2">
                          <span className="text-xs font-bold text-gray-400 w-4 shrink-0">{i + 1}</span>
                          <input value={a} onChange={(e) => setAssumption(i, e.target.value)}
                            placeholder="Assumption…"
                            className="flex-1 border border-gray-200 rounded-xl px-3 py-2 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                          <button onClick={() => removeAssumption(i)}
                            className="p-1.5 rounded-lg text-gray-300 hover:text-red-400 hover:bg-red-50 transition-colors">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                            </svg>
                          </button>
                        </div>
                      ))}
                      <button onClick={addAssumption}
                        className="w-full py-2 border-2 border-dashed border-gray-200 rounded-xl text-sm text-gray-400 hover:border-indigo-300 hover:text-indigo-500 transition-colors flex items-center justify-center gap-2">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                        </svg>
                        Add Assumption
                      </button>
                    </div>
                  </EditSection>

                  {/* Maintenance & Support */}
                  <EditSection icon="🛟" heading="Maintenance & Support" gradient={activeTheme.gradient}>
                    <div className="space-y-3">
                      <div>
                        <label className={labelCls}>Free Support Period</label>
                        <input
                          value={editContent.maintenance_support?.period ?? ''}
                          onChange={(e) => setMaintenancePeriod(e.target.value)}
                          placeholder="e.g. 3 months"
                          className={inputCls}
                        />
                      </div>
                      <div>
                        <label className={labelCls}>Includes</label>
                        <div className="space-y-2">
                          {(editContent.maintenance_support?.includes ?? []).map((item, i) => (
                            <div key={i} className="flex items-center gap-2">
                              <span className="text-gray-300 text-xs">•</span>
                              <input value={item} onChange={(e) => setMaintenanceSupportItem(i, e.target.value)}
                                placeholder="Support item…"
                                className="flex-1 border border-gray-200 rounded-xl px-3 py-2 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all" />
                              <button onClick={() => removeMaintenanceSupportItem(i)}
                                className="p-1.5 rounded-lg text-gray-300 hover:text-red-400 hover:bg-red-50 transition-colors">
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                </svg>
                              </button>
                            </div>
                          ))}
                          <button onClick={addMaintenanceSupportItem}
                            className="w-full py-2 border-2 border-dashed border-gray-200 rounded-xl text-sm text-gray-400 hover:border-indigo-300 hover:text-indigo-500 transition-colors flex items-center justify-center gap-2">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                            </svg>
                            Add Support Item
                          </button>
                        </div>
                      </div>
                    </div>
                  </EditSection>

                  {/* Terms & Conditions */}
                  <EditSection icon="📜" heading="Terms & Conditions" gradient={activeTheme.gradient}>
                    <textarea rows={5} value={editContent.terms_and_conditions}
                      onChange={(e) => setField('terms_and_conditions', e.target.value)}
                      className={textaCls} placeholder="Terms and conditions…" />
                  </EditSection>

                  {/* Next Steps */}
                  <EditSection icon="🚀" heading="Next Steps" gradient={activeTheme.gradient}>
                    <textarea rows={3} value={editContent.next_steps}
                      onChange={(e) => setField('next_steps', e.target.value)}
                      className={textaCls} placeholder="Next steps for the client…" />
                  </EditSection>

                  {/* Closing Note */}
                  <EditSection icon="🙏" heading="Closing Note" gradient={activeTheme.gradient}>
                    <textarea rows={2} value={editContent.closing_note ?? ''}
                      onChange={(e) => setField('closing_note', e.target.value)}
                      className={textaCls} placeholder="A warm 1-2 sentence closing — thank the client, express excitement…" />
                  </EditSection>

                  {/* Validity days */}
                  <EditSection icon="⏳" heading="Validity" gradient={activeTheme.gradient}>
                    <div className="flex items-center gap-3">
                      <input type="number" min={1} max={90} value={editContent.validity_days}
                        onChange={(e) => setField('validity_days', Number(e.target.value))}
                        className="w-24 border border-gray-200 rounded-xl px-3 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-all text-center font-semibold" />
                      <span className="text-sm text-gray-500">days from date of issue</span>
                    </div>
                  </EditSection>

                  {/* Bottom save row */}
                  <div className="pt-4 pb-8 flex items-center justify-between"
                    style={{ borderTop: '1px solid #e5e7eb' }}>
                    <span className="text-xs text-gray-400">
                      {saveSuccess ? '✓ All changes saved' : 'Unsaved changes will be lost on refresh'}
                    </span>
                    <button
                      onClick={() => saveMutation.mutate()}
                      disabled={isSaving}
                      className="flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold text-white transition-all hover:opacity-90 disabled:opacity-60"
                      style={{ background: 'rgba(99,102,241,0.9)' }}
                    >
                      {isSaving ? 'Saving…' : 'Save Changes'}
                    </button>
                  </div>

                </div>
              </motion.div>
            )}

          </AnimatePresence>
        </div>
      </div>

      {/* ── PDF Modal ─────────────────────────────────────────────────────────── */}
      {showPDF && proposal && lead && editContent && (
        <Modal open={showPDF} onClose={() => setShowPDF(false)} title="Download Proposal PDF" maxWidth="max-w-4xl">
          <ProposalPDF
            proposal={{ ...proposal, content: editContent, theme }}
            lead={lead}
            onClose={() => setShowPDF(false)}
          />
        </Modal>
      )}
    </div>
  );
}

// ── Reusable section wrapper ──────────────────────────────────────────────────

function EditSection({ icon, heading, gradient, children }: {
  icon: string; heading: string; gradient: string; children: React.ReactNode;
}) {
  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }}>
      <div className="flex items-center gap-2.5 mb-3">
        <div className={`h-[18px] w-0.5 rounded-full bg-gradient-to-b ${gradient}`} />
        <span className="text-[10px] font-bold uppercase tracking-widest text-gray-400">
          {icon} {heading}
        </span>
      </div>
      <div className="rounded-2xl bg-white shadow-sm overflow-hidden p-5" style={{ border: '1px solid rgba(0,0,0,0.06)' }}>
        {children}
      </div>
    </motion.div>
  );
}

// ── Theme picker ──────────────────────────────────────────────────────────────

function ThemePicker({ theme, setTheme, disabled }: {
  theme: ProposalTheme; setTheme: (t: ProposalTheme) => void; disabled: boolean;
}) {
  return (
    <div className="flex flex-col gap-2">
      <label className="text-xs font-semibold uppercase tracking-widest" style={{ color: 'rgba(255,255,255,0.35)' }}>
        Proposal Theme
      </label>
      <div className="grid grid-cols-2 gap-2">
        {THEMES.map((t) => (
          <button
            key={t.value}
            onClick={() => setTheme(t.value)}
            disabled={disabled}
            className="relative h-14 rounded-xl overflow-hidden transition-all duration-200 disabled:cursor-not-allowed"
            style={theme === t.value
              ? { boxShadow: '0 0 0 2px white, 0 0 0 4px rgba(99,102,241,0.5)', transform: 'scale(1.02)' }
              : { opacity: 0.5 }}
          >
            <div className={`absolute inset-0 bg-gradient-to-br ${t.gradient}`} />
            <div className="absolute inset-0" style={{ background: 'rgba(0,0,0,0.15)' }} />
            <div className="absolute inset-0" style={{ backgroundImage: 'radial-gradient(circle at 80% 20%, rgba(255,255,255,0.25) 0%, transparent 50%)' }} />
            <div className="absolute inset-0 flex flex-col items-center justify-center gap-0.5">
              <span className="text-white text-xs font-bold drop-shadow-sm">{t.label}</span>
              <span className="text-white/60 text-[9px] drop-shadow-sm">{t.desc}</span>
            </div>
            {theme === t.value && (
              <div className="absolute top-1.5 right-1.5 w-4 h-4 bg-white rounded-full flex items-center justify-center shadow-lg">
                <svg className="w-2.5 h-2.5 text-gray-900" fill="currentColor" viewBox="0 0 20 20">
                  <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                </svg>
              </div>
            )}
          </button>
        ))}
      </div>
    </div>
  );
}

// ── Blinking cursor ───────────────────────────────────────────────────────────

function Cursor() {
  return <span className="inline-block w-0.5 h-4 bg-indigo-500 ml-0.5 align-middle animate-pulse" aria-hidden />;
}
