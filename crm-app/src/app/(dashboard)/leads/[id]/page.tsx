'use client';

import { useParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { leadsApi, type LeadPayload, type ConvertPayload } from '@/lib/api/leads';
import { activitiesApi } from '@/lib/api/activities';
import { notesApi } from '@/lib/api/notes';
import { toWhatsAppNumber } from '@/lib/phone';
import { whatsappTemplatesApi } from '@/lib/api/whatsappTemplates';
import { pipelinesApi } from '@/lib/api/pipelines';
import { LeadInfoCard } from '@/components/leads/LeadInfoCard';
import { StatusStepperCard } from '@/components/leads/StatusStepperCard';
import { ScheduleStatusModal } from '@/components/leads/ScheduleStatusModal';
import { isScheduledLeadStatus, type LeadStatus, type ScheduledLeadStatus } from '@/lib/leadStatuses';
import { AssignmentCard } from '@/components/leads/AssignmentCard';
import { LeadActivityFeed } from '@/components/leads/LeadActivityFeed';

function leadsReturnUrl(): string {
  if (typeof window === 'undefined') return '/leads';
  return sessionStorage.getItem('leads-return-url') ?? '/leads';
}
import { Badge } from '@/components/ui/Badge';
import { Modal } from '@/components/ui/Modal';
import { useState, useEffect } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { useAuthStore } from '@/store/authStore';

// ── Success Toast ─────────────────────────────────────────────────────────────
function AssignToast({ name, onDone }: { name: string; onDone: () => void }) {
  useEffect(() => {
    const t = setTimeout(onDone, 3000);
    return () => clearTimeout(t);
  }, [onDone]);

  return (
    <motion.div
      initial={{ opacity: 0, y: -24, scale: 0.96 }}
      animate={{ opacity: 1, y: 0,   scale: 1 }}
      exit={{    opacity: 0, y: -16,  scale: 0.96 }}
      transition={{ type: 'spring', damping: 28, stiffness: 380 }}
      className="fixed top-5 left-1/2 -translate-x-1/2 z-[9999] flex items-center gap-3 bg-white border border-emerald-200 shadow-xl shadow-black/10 rounded-2xl px-5 py-3.5"
    >
      <div className="w-7 h-7 rounded-full bg-emerald-100 flex items-center justify-center shrink-0">
        <svg className="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 13l4 4L19 7"/>
        </svg>
      </div>
      <div>
        <p className="text-sm font-semibold text-gray-900">Lead assigned</p>
        <p className="text-xs text-gray-500">Now assigned to <span className="font-medium text-emerald-600">{name}</span></p>
      </div>
      <button onClick={onDone} className="ml-2 text-gray-300 hover:text-gray-500 transition-colors">
        <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
      {/* Progress bar */}
      <motion.div
        initial={{ scaleX: 1 }}
        animate={{ scaleX: 0 }}
        transition={{ duration: 3, ease: 'linear' }}
        style={{ originX: 0 }}
        className="absolute bottom-0 left-0 right-0 h-0.5 bg-emerald-400 rounded-b-2xl"
      />
    </motion.div>
  );
}

// ── Spinner ───────────────────────────────────────────────────────────────────
function Spinner() {
  return (
    <div className="flex items-center justify-center py-24">
      <svg className="w-8 h-8 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
      </svg>
    </div>
  );
}

// ── Convert-to-deal form (same as in leads/page.tsx) ─────────────────────────
const inputCls = 'w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-shadow bg-white';

function ConvertForm({ leadName, pipelines, onSave, onClose, saving }: {
  leadName: string;
  pipelines: import('@/types').Pipeline[] | undefined;
  onSave: (d: ConvertPayload) => void;
  onClose: () => void;
  saving?: boolean;
}) {
  const [selectedPipelineId, setSelectedPipelineId] = useState<number>(0);
  const [form, setForm] = useState<ConvertPayload>({
    pipeline_id: 0,
    stage_id:    0,
    title:       `Deal — ${leadName}`,
    value:       undefined,
  });

  const pipelineId = selectedPipelineId || pipelines?.[0]?.id || 0;
  const stages     = pipelines?.find((p) => p.id === pipelineId)?.stages ?? [];

  return (
    <div className="space-y-4">
      <div className="bg-indigo-50 rounded-xl p-3 text-sm text-indigo-700">
        Converting <strong>{leadName}</strong> to a deal.
      </div>
      <div>
        <label className="block text-xs font-semibold text-gray-600 mb-1">Deal Title</label>
        <input value={form.title ?? ''} onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))} className={inputCls} />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1">Pipeline</label>
          <select
            value={pipelineId}
            onChange={(e) => { const id = Number(e.target.value); setSelectedPipelineId(id); setForm((f) => ({ ...f, pipeline_id: id, stage_id: 0 })); }}
            className={inputCls}
          >
            {pipelines?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        </div>
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1">Stage *</label>
          <select value={form.stage_id} onChange={(e) => setForm((f) => ({ ...f, stage_id: Number(e.target.value) }))} className={inputCls}>
            <option value={0}>Select stage…</option>
            {stages.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
        </div>
      </div>
      <div>
        <label className="block text-xs font-semibold text-gray-600 mb-1">Deal Value (₹)</label>
        <input
          type="number" min={0}
          value={form.value ?? ''}
          onChange={(e) => setForm((f) => ({ ...f, value: e.target.value ? Number(e.target.value) : undefined }))}
          placeholder="0"
          className={inputCls}
        />
      </div>
      <div className="flex gap-3 pt-1">
        <button
          onClick={() => onSave({ ...form, pipeline_id: pipelineId })}
          disabled={saving || !form.stage_id}
          className="flex-1 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-60 text-white font-semibold py-2.5 rounded-xl text-sm flex items-center justify-center gap-2 transition-colors"
        >
          {saving && <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>}
          Convert to Deal
        </button>
        <button onClick={onClose} className="px-5 py-2.5 text-sm text-gray-500 hover:text-gray-700 border border-gray-200 rounded-xl transition-colors">Cancel</button>
      </div>
    </div>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────
export default function LeadDetailPage() {
  const params    = useParams<{ id: string }>();
  const router    = useRouter();
  const qc        = useQueryClient();
  const leadId    = Number(params.id);
  const { isAdmin, isEmployee, user } = useAuthStore();

  const [convertOpen, setConvertOpen] = useState(false);
  const [waOpen, setWaOpen]           = useState(false);
  const [waSending, setWaSending]     = useState(false);
  const [assignToast, setAssignToast] = useState<string | null>(null);
  const [scheduleStatus, setScheduleStatus] = useState<ScheduledLeadStatus | null>(null);

  const { data: waTemplates } = useQuery({
    queryKey: ['whatsapp-templates'],
    queryFn:  whatsappTemplatesApi.list,
    staleTime: 10 * 60_000,
    enabled:  waOpen,
  });

  const handleWaSend = async (tpl: string) => {
    if (!lead?.phone) return;
    const msg = tpl.replace(/\{\{name\}\}/g, lead.full_name).replace(/\{\{company\}\}/g, lead.company ?? '');
    const num = toWhatsAppNumber(lead.phone);
    window.open(`https://wa.me/${num}?text=${encodeURIComponent(msg)}`, '_blank');
    setWaSending(true);
    try {
      await activitiesApi.create({
        subject_type: 'lead',
        subject_id:   lead.id,
        type:         'whatsapp',
        title:        `WhatsApp sent to ${lead.full_name}`,
        description:  msg,
        due_at:       new Date().toISOString(),
        assigned_to:  lead.assigned_to?.id,
        priority:     'low',
        is_done:      true,
      });
      qc.invalidateQueries({ queryKey: ['lead-activities', lead.id] });
      setWaOpen(false);
    } finally {
      setWaSending(false);
    }
  };

  // ── Queries ────────────────────────────────────────────────────────────────
  const { data: pipelines } = useQuery({
    queryKey: ['pipelines'],
    queryFn:  pipelinesApi.list,
    staleTime: 10 * 60_000,
  });

  const { data: lead, isLoading: loadingLead, isError } = useQuery({
    queryKey: ['lead', leadId],
    queryFn:  () => leadsApi.get(leadId),
    enabled:  !!leadId,
  });

  const { data: activities = [] } = useQuery({
    queryKey: ['lead-activities', leadId],
    queryFn:  () => activitiesApi.list({ subject_type: 'lead', subject_id: leadId, per_page: 100 }).then((r) => r.data),
    enabled:  !!leadId,
  });

  const { data: notes = [] } = useQuery({
    queryKey: ['lead-notes', leadId],
    queryFn:  () => notesApi.list({ notable_type: 'lead', notable_id: leadId }),
    enabled:  !!leadId,
  });

  const { data: timeline = [] } = useQuery({
    queryKey: ['lead-timeline', leadId],
    queryFn:  () => leadsApi.timeline(leadId),
    enabled:  !!leadId,
  });

  // ── Mutations ──────────────────────────────────────────────────────────────
  const invalidateLead = () => {
    qc.invalidateQueries({ queryKey: ['lead', leadId] });
    qc.invalidateQueries({ queryKey: ['lead-timeline', leadId] });
  };

  const updateMutation = useMutation({
    mutationFn: (payload: Partial<LeadPayload> & { _assigneeName?: string }) => {
      const { _assigneeName: _, ...rest } = payload;
      return leadsApi.update(leadId, rest);
    },
    onSuccess: (_data, variables) => {
      invalidateLead();
      qc.invalidateQueries({ queryKey: ['leads'] });
      qc.invalidateQueries({ queryKey: ['lead-activities', leadId] });
      qc.invalidateQueries({ queryKey: ['activities'] });
      setScheduleStatus(null);
      if (variables._assigneeName !== undefined) {
        setAssignToast(variables._assigneeName || 'Unassigned');
      }
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      alert(msg ?? 'Failed to update lead.');
    },
  });

  const deleteMutation = useMutation({
    mutationFn: () => leadsApi.delete(leadId),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['leads'] }); router.push(leadsReturnUrl()); },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      alert(msg ?? 'Failed to delete lead.');
    },
  });

  const convertMutation = useMutation({
    mutationFn: (payload: ConvertPayload) => leadsApi.convertToDeal(leadId, payload),
    onSuccess: () => {
      setConvertOpen(false);
      qc.invalidateQueries({ queryKey: ['leads'] });
      qc.invalidateQueries({ queryKey: ['deals'] });
      invalidateLead();
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      alert(msg ?? 'Failed to convert lead.');
    },
  });

  const saving = updateMutation.isPending;

  const handleStatusChange = (s: LeadStatus) => {
    if (isScheduledLeadStatus(s)) {
      setScheduleStatus(s);
      return;
    }
    updateMutation.mutate({ status: s });
  };

  // ── Guards ─────────────────────────────────────────────────────────────────
  if (isError) {
    return (
      <div className="flex flex-col items-center justify-center py-24 gap-4">
        <p className="text-gray-500">Lead not found or you don&apos;t have access.</p>
        <Link href={leadsReturnUrl()} className="text-indigo-600 hover:underline text-sm">← Back to Leads</Link>
      </div>
    );
  }

  if (loadingLead || !lead) return <Spinner />;

  const canEditThisLead =
    isAdmin() || (isEmployee() && lead.assigned_to?.id === user?.id);

  return (
    <>
      <div className="space-y-5">
        {/* ── Header ──────────────────────────────────────────────────────── */}
        <div className="flex items-center gap-4 flex-wrap">
          {/* Back */}
          <Link
            href={leadsReturnUrl()}
            className="flex items-center gap-1.5 text-sm text-gray-500 hover:text-indigo-600 transition-colors"
          >
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7"/>
            </svg>
            Leads
          </Link>

          <span className="text-gray-300">/</span>

          {/* Name + badge */}
          <div className="flex items-center gap-3 flex-1 min-w-0">
            <div className="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 flex items-center justify-center text-white font-bold text-sm shadow-sm shrink-0">
              {lead.full_name[0]?.toUpperCase()}
            </div>
            <div className="min-w-0">
              <h1 className="text-xl font-bold text-gray-900 truncate">{lead.full_name}</h1>
              {lead.company && <p className="text-xs text-gray-400">{lead.company}</p>}
            </div>
            <Badge value={lead.status} />
          </div>

          {/* Header actions */}
          <div className="flex items-center gap-2 shrink-0">
            {lead.phone && (
              <div className="relative">
                <button
                  onClick={() => setWaOpen((v) => !v)}
                  className="flex items-center gap-1.5 px-4 py-2 bg-green-500 hover:bg-green-600 text-white text-sm font-semibold rounded-xl transition-colors shadow-sm"
                >
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                  WhatsApp
                </button>
                <AnimatePresence>
                  {waOpen && (
                    <motion.div
                      initial={{ opacity: 0, y: -4, scale: 0.97 }}
                      animate={{ opacity: 1, y: 0, scale: 1 }}
                      exit={{ opacity: 0, y: -4, scale: 0.97 }}
                      transition={{ type: 'spring', damping: 30, stiffness: 400 }}
                      className="absolute right-0 top-full mt-2 w-80 bg-white border border-gray-100 rounded-2xl shadow-xl shadow-black/10 z-50 p-3 space-y-2"
                    >
                      <p className="text-[11px] text-gray-400 font-medium px-1 pb-1 border-b border-gray-100">Select a template:</p>
                      {!waTemplates ? (
                        <div className="space-y-1.5">
                          {[...Array(3)].map((_, i) => <div key={i} className="skeleton h-9 rounded-xl" />)}
                        </div>
                      ) : waTemplates.length === 0 ? (
                        <p className="text-xs text-gray-400 text-center py-2">No templates. Ask admin to add some.</p>
                      ) : waTemplates.map((tpl) => (
                        <button
                          key={tpl.id}
                          disabled={waSending}
                          onClick={() => handleWaSend(tpl.message)}
                          className="w-full text-left p-2.5 border border-gray-100 rounded-xl hover:border-green-300 hover:bg-green-50 transition-all text-xs text-gray-700 disabled:opacity-50"
                        >
                          <span className="text-green-500 mr-1">💬</span>
                          <span className="font-semibold text-green-700 mr-1">[{tpl.name}]</span>
                          {tpl.message.replace(/\{\{name\}\}/g, lead.full_name).replace(/\{\{company\}\}/g, lead.company ?? '')}
                        </button>
                      ))}
                      <button onClick={() => setWaOpen(false)} className="w-full text-xs text-gray-400 hover:text-gray-600 py-1">Cancel</button>
                    </motion.div>
                  )}
                </AnimatePresence>
              </div>
            )}
            {lead.status !== 'converted' && (
              <button
                onClick={() => setConvertOpen(true)}
                className="flex items-center gap-1.5 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-xl transition-colors shadow-sm"
              >
                💰 Convert to Deal
              </button>
            )}
            {isAdmin() && (
            <button
              onClick={() => { if (confirm(`Delete ${lead.full_name}? This cannot be undone.`)) deleteMutation.mutate(); }}
              disabled={deleteMutation.isPending}
              className="flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-red-600 border border-red-200 hover:bg-red-50 rounded-xl transition-colors"
            >
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
              </svg>
              {deleteMutation.isPending ? 'Deleting…' : 'Delete'}
            </button>
            )}
          </div>
        </div>

        {/* ── Two-column layout ────────────────────────────────────────────── */}
        <div className="flex gap-5 items-start">
          {/* Left column */}
          <div className="w-[340px] shrink-0 space-y-4">
            <LeadInfoCard
              lead={lead}
              onUpdate={(payload) => updateMutation.mutate(payload)}
              saving={saving}
              canEdit={canEditThisLead}
            />
            <StatusStepperCard
              status={lead.status}
              onStatusChange={handleStatusChange}
              saving={saving}
              canEdit={canEditThisLead}
            />
            <AssignmentCard
              assignedTo={lead.assigned_to}
              createdBy={lead.created_by}
              createdAt={lead.created_at}
              onAssign={(userId, name) => updateMutation.mutate({ assigned_to: userId ?? undefined, _assigneeName: name ?? '' })}
              saving={saving}
              canReassign={isAdmin()}
            />
          </div>

          {/* Right column — feed */}
          <div className="flex-1 min-w-0">
            <LeadActivityFeed
              leadId={leadId}
              leadName={lead.full_name}
              activities={activities}
              notes={notes}
              timeline={timeline}
            />
          </div>
        </div>
      </div>

      {/* Assignment success toast */}
      <AnimatePresence>
        {assignToast !== null && (
          <AssignToast name={assignToast} onDone={() => setAssignToast(null)} />
        )}
      </AnimatePresence>

      {/* Convert modal */}
      <Modal
        open={convertOpen}
        onClose={() => setConvertOpen(false)}
        title="Convert Lead to Deal"
        maxWidth="max-w-md"
      >
        <ConvertForm
          leadName={lead.full_name}
          pipelines={pipelines}
          onSave={(p) => convertMutation.mutate(p)}
          onClose={() => setConvertOpen(false)}
          saving={convertMutation.isPending}
        />
      </Modal>

      {scheduleStatus && lead && (
        <ScheduleStatusModal
          lead={lead}
          status={scheduleStatus}
          onClose={() => setScheduleStatus(null)}
          onConfirm={(scheduleAt) =>
            updateMutation.mutate({ status: scheduleStatus, schedule_at: scheduleAt })
          }
          saving={saving}
        />
      )}
    </>
  );
}
