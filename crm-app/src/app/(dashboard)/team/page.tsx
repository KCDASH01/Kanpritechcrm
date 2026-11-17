'use client';

import { useEffect, useRef, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { employeesApi, type EmployeePayload, type EmployeeRemovalPayload } from '@/lib/api/employees';
import { subscriptionApi } from '@/lib/api/subscription';
import { useAuthStore } from '@/store/authStore';
import { AccessDenied } from '@/components/ui/AccessDenied';
import type { User } from '@/types';

declare global {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  interface Window { Razorpay: any; }
}

interface ApiError {
  response?: { data?: { code?: string; message?: string } };
}

export default function TeamPage() {
  const qc = useQueryClient();
  const { user, canManageTeam, isAdmin, isOwner } = useAuthStore();
  const manageTeam = canManageTeam();

  // ── Employee modal state ───────────────────────────────────────────────────
  const [modal,     setModal]     = useState<User | null | undefined>(undefined);
  const [form,      setForm]      = useState<EmployeePayload>({ name: '', email: '', password: '' });
  const [formError, setFormError] = useState<string | null>(null);
  const [lifecycleMember, setLifecycleMember] = useState<User | null>(null);
  const [lifecycleKind, setLifecycleKind] = useState<'remove' | 'deactivate'>('remove');
  const [leadAction, setLeadAction] = useState<'transfer' | 'unassign'>('unassign');
  const [transferTo, setTransferTo] = useState('');
  const [lifecycleError, setLifecycleError] = useState<string | null>(null);
  const [lifecycleNotice, setLifecycleNotice] = useState<string | null>(null);
  const lifecycleDialogRef = useRef<HTMLDivElement>(null);

  // ── Password reset modal state ────────────────────────────────────────────
  const [pwModal,   setPwModal]   = useState<User | null>(null);
  const [pwForm,    setPwForm]    = useState({ password: '', confirm: '' });
  const [pwError,   setPwError]   = useState<string | null>(null);
  const [pwSuccess, setPwSuccess] = useState(false);

  // ── Buy Seats modal state ─────────────────────────────────────────────────
  const [seatModal,   setSeatModal]   = useState(false);
  const [seatQty,     setSeatQty]     = useState(1);
  const [seatLoading, setSeatLoading] = useState(false);
  const [seatError,   setSeatError]   = useState<string | null>(null);

  // ── Load Razorpay script once ─────────────────────────────────────────────
  const rzpLoaded = useRef(false);
  useEffect(() => {
    if (rzpLoaded.current) return;
    rzpLoaded.current = true;
    const script = document.createElement('script');
    script.src = 'https://checkout.razorpay.com/v1/checkout.js';
    script.async = true;
    document.body.appendChild(script);
  }, []);

  useEffect(() => {
    if (!lifecycleNotice) return;
    const timeout = window.setTimeout(() => setLifecycleNotice(null), 4500);
    return () => window.clearTimeout(timeout);
  }, [lifecycleNotice]);

  // ── Data (hooks must run before any conditional return) ─────────────────────

  const { data: employees, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['employees', 'team'],
    queryFn:  () => employeesApi.list({ include_inactive: true }),
    enabled:  manageTeam,
  });

  const { data: sub } = useQuery({
    queryKey: ['subscription'],
    queryFn:  subscriptionApi.get,
    enabled:  manageTeam,
  });

  // ── Seat usage ────────────────────────────────────────────────────────────

  const totalLimit = sub?.total_team_limit ?? 0;
  const usedSeats  = employees?.length ?? 0;
  const seatsLeft  = totalLimit > 0 ? totalLimit - usedSeats : null;
  const isAtLimit  = seatsLeft !== null && seatsLeft <= 0;
  const seatPrice       = sub?.extra_member_price ?? 299;
  const remainingMonths = sub?.end_date
    ? Math.max(1, Math.ceil((new Date(sub.end_date).getTime() - Date.now()) / (1000 * 60 * 60 * 24 * 30)))
    : 1;
  const totalPrice = seatQty * seatPrice * remainingMonths;

  const pillColor = isAtLimit
    ? 'bg-red-100 text-red-700'
    : seatsLeft === 1
      ? 'bg-amber-100 text-amber-700'
      : 'bg-emerald-100 text-emerald-700';

  // ── Employee mutations ────────────────────────────────────────────────────

  const createMutation = useMutation({
    mutationFn: (p: EmployeePayload) => employeesApi.create(p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['employees'] });
      setModal(undefined);
      setFormError(null);
    },
    onError: (err: unknown) => {
      const data = (err as ApiError)?.response?.data;
      if (data?.code === 'SEAT_LIMIT_REACHED') {
        setFormError(data.message ?? 'Team seat limit reached.');
      } else {
        setFormError('Failed to add member. Please try again.');
      }
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, p }: { id: number; p: Partial<EmployeePayload> }) => employeesApi.update(id, p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['employees'] });
      setModal(undefined);
      setFormError(null);
    },
    onError: () => setFormError('Failed to update member. Please try again.'),
  });

  const lifecyclePreview = useQuery({
    queryKey: ['employee-removal-preview', lifecycleMember?.id],
    queryFn: () => employeesApi.removalPreview(lifecycleMember!.id),
    enabled: lifecycleMember !== null,
  });

  const lifecycleMutation = useMutation({
    mutationFn: ({ id, kind, payload }: { id: number; kind: 'remove' | 'deactivate'; payload: EmployeeRemovalPayload }) =>
      kind === 'remove' ? employeesApi.delete(id, payload) : employeesApi.deactivate(id, payload),
    onSuccess: (result) => {
      qc.invalidateQueries({ queryKey: ['employees'] });
      qc.invalidateQueries({ queryKey: ['leads'] });
      const destination = leadAction === 'transfer'
        ? `transferred to ${employees?.find((member) => member.id === Number(transferTo))?.name ?? 'the selected employee'}`
        : 'moved to Unassigned';
      setLifecycleNotice(`${result.reassigned_leads} lead(s) ${destination}. ${result.message}`);
      setLifecycleMember(null);
      setLifecycleError(null);
    },
    onError: (err: unknown) => setLifecycleError(
      (err as ApiError)?.response?.data?.message ?? 'Failed to update this team member.',
    ),
  });

  useEffect(() => {
    if (!lifecycleMember) return;
    lifecycleDialogRef.current?.focus();
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && !lifecycleMutation.isPending) setLifecycleMember(null);
    };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [lifecycleMember, lifecycleMutation.isPending]);

  const reactivateMutation = useMutation({
    mutationFn: (id: number) => employeesApi.reactivate(id),
    onSuccess: (employee) => {
      qc.invalidateQueries({ queryKey: ['employees'] });
      setLifecycleNotice(`${employee.name} was reactivated successfully.`);
    },
  });

  const resetPasswordMutation = useMutation({
    mutationFn: ({ id, password }: { id: number; password: string }) =>
      employeesApi.update(id, { password }),
    onSuccess: () => {
      setPwSuccess(true);
      setPwError(null);
      setTimeout(() => {
        setPwModal(null);
        setPwForm({ password: '', confirm: '' });
        setPwSuccess(false);
      }, 1500);
    },
    onError: (err: unknown) => {
      const data = (err as {
        response?: { data?: { message?: string; errors?: Record<string, string[]> } };
      })?.response?.data;
      const fieldMsg =
        data?.errors?.password?.[0] ??
        data?.errors?.email?.[0] ??
        data?.errors?.name?.[0];
      setPwError(fieldMsg || data?.message || 'Failed to reset password. Please try again.');
    },
  });

  // ── Seat purchase ─────────────────────────────────────────────────────────

  async function handleBuySeats() {
    setSeatLoading(true);
    setSeatError(null);
    try {
      const order = await subscriptionApi.addSeat(seatQty);

      const rzp = new window.Razorpay({
        key:         order.key,
        amount:      order.amount,
        currency:    order.currency,
        name:        'Growneq CRM',
        description: `${seatQty} extra team seat${seatQty > 1 ? 's' : ''}`,
        order_id:    order.order_id,
        prefill:     { name: user?.name, email: user?.email },
        handler: async (response: {
          razorpay_payment_id: string;
          razorpay_order_id: string;
          razorpay_signature: string;
        }) => {
          try {
            await subscriptionApi.verifySeat({
              razorpay_order_id:   response.razorpay_order_id,
              razorpay_payment_id: response.razorpay_payment_id,
              razorpay_signature:  response.razorpay_signature,
              quantity:            seatQty,
            });
            qc.invalidateQueries({ queryKey: ['subscription'] });
            setSeatModal(false);
            setSeatQty(1);
          } catch {
            setSeatError('Payment received but verification failed. Please contact support.');
          } finally {
            setSeatLoading(false);
          }
        },
        modal: { ondismiss: () => setSeatLoading(false) },
        theme: { color: '#6366f1' },
      });

      rzp.open();
    } catch (err: unknown) {
      const msg = (err as ApiError)?.response?.data?.message ?? 'Failed to initiate payment. Please try again.';
      setSeatError(msg);
      setSeatLoading(false);
    }
  }

  // ── Employee modal helpers ────────────────────────────────────────────────

  const openResetPassword = (emp: User) => {
    setPwForm({ password: '', confirm: '' });
    setPwError(null);
    setPwSuccess(false);
    setPwModal(emp);
  };

  const handleResetPassword = () => {
    if (!pwModal) return;
    if (pwForm.password.length < 8) {
      setPwError('Password must be at least 8 characters.');
      return;
    }
    if (pwForm.password !== pwForm.confirm) {
      setPwError('Passwords do not match.');
      return;
    }
    setPwError(null);
    resetPasswordMutation.mutate({ id: pwModal.id, password: pwForm.password });
  };

  const openCreate = () => {
    setForm({ name: '', email: '', password: '' });
    setFormError(null);
    setModal(null);
  };

  const openEdit = (emp: User) => {
    setForm({ name: emp.name, email: emp.email, password: '' });
    setFormError(null);
    setModal(emp);
  };

  const handleSubmit = () => {
    setFormError(null);
    if (modal?.id) {
      updateMutation.mutate({ id: modal.id, p: form });
    } else {
      createMutation.mutate(form);
    }
  };

  const isPending = createMutation.isPending || updateMutation.isPending;

  const openLifecycle = (employee: User, kind: 'remove' | 'deactivate') => {
    setLifecycleMember(employee);
    setLifecycleKind(kind);
    setLeadAction('unassign');
    setTransferTo('');
    setLifecycleError(null);
  };

  const submitLifecycle = () => {
    if (!lifecycleMember) return;
    if (leadAction === 'transfer' && !transferTo) {
      setLifecycleError('Select an active team member to receive the leads.');
      return;
    }
    lifecycleMutation.mutate({
      id: lifecycleMember.id,
      kind: lifecycleKind,
      payload: {
        lead_action: leadAction,
        transfer_to: leadAction === 'transfer' ? Number(transferTo) : null,
        reason: lifecycleKind === 'remove' ? 'Removed from Team page' : 'Deactivated from Team page',
      },
    });
  };

  if (!manageTeam) {
    return <AccessDenied reason="Team management is only available to owners and admins on the Business or Enterprise plan." />;
  }

  const loadError = isError
    ? (error as ApiError)?.response?.data?.message ?? 'Failed to load team members.'
    : null;

  // ── Render ────────────────────────────────────────────────────────────────

  return (
    <div className="space-y-5" data-feature-team-lifecycle="v2">

      {lifecycleNotice && (
        <div className="fixed right-4 top-20 z-[70] max-w-sm rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 shadow-lg" role="status" aria-live="polite">
          {lifecycleNotice}
        </div>
      )}

      {/* ── Header ── */}
      <div className="flex items-center justify-between gap-4 flex-wrap">
        <div className="flex items-center gap-3 flex-wrap">
          <h1 className="text-xl font-bold text-gray-900">Team Members</h1>

          {totalLimit > 0 && (
            <span className={`text-xs font-semibold px-2.5 py-1 rounded-full ${pillColor}`}>
              {usedSeats} / {totalLimit} seats used
            </span>
          )}
        </div>

        <div className="flex items-center gap-2 flex-wrap">
          {/* Buy Seats button — owner only, visible even when not at limit */}
          {isOwner() && totalLimit > 0 && (
            <button
              onClick={() => { setSeatError(null); setSeatQty(1); setSeatModal(true); }}
              className="text-sm font-medium px-3 py-2 rounded-xl border border-indigo-200 text-indigo-700 hover:bg-indigo-50 transition-colors"
            >
              + Buy Seats
            </button>
          )}

          {/* Add Member button */}
          {isAdmin() && (
            <div className="flex flex-col items-end gap-1">
              <button
                onClick={openCreate}
                disabled={isAtLimit}
                className={`text-sm font-semibold px-4 py-2 rounded-xl transition-colors ${
                  isAtLimit
                    ? 'bg-gray-100 text-gray-400 cursor-not-allowed'
                    : 'bg-indigo-600 hover:bg-indigo-700 text-white'
                }`}
              >
                + Add Member
              </button>

              {isAtLimit && !isOwner() && (
                <p className="text-xs text-gray-500">
                  Seat limit reached. Ask the owner to purchase more seats.
                </p>
              )}
            </div>
          )}
        </div>
      </div>

      {/* ── Employee list ── */}
      <div className="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        {loadError ? (
          <div className="flex flex-col items-center justify-center h-48 px-6 text-center gap-3">
            <p className="text-sm text-red-600">{loadError}</p>
            <button
              onClick={() => refetch()}
              className="text-sm font-semibold text-indigo-600 hover:text-indigo-700"
            >
              Retry
            </button>
          </div>
        ) : isLoading ? (
          <div className="flex items-center justify-center h-48">
            <div className="w-6 h-6 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
          </div>
        ) : (
          <div className="divide-y divide-gray-50">
            {employees?.length ? employees.map((emp) => (
              <div key={emp.id} className="flex flex-col items-stretch gap-3 px-4 py-4 sm:flex-row sm:items-center sm:gap-4 sm:px-6">
                <div className="w-10 h-10 bg-gray-100 rounded-full flex items-center justify-center shrink-0">
                  <span className="text-gray-600 font-semibold">{emp.name[0]?.toUpperCase()}</span>
                </div>
                <div className="flex-1 min-w-0">
                  <p className="font-medium text-gray-900 text-sm">{emp.name}</p>
                  <p className="truncate text-xs text-gray-400">{emp.email}</p>
                </div>
                <div className="flex flex-wrap items-center gap-3 sm:shrink-0 sm:justify-end">
                  <span className={`text-xs px-2 py-0.5 rounded-full font-medium capitalize ${
                    emp.role === 'admin' ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600'
                  }`}>
                    {emp.role}
                  </span>
                  <span className={`text-[10px] font-semibold rounded-full px-2 py-0.5 ${emp.is_active ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>
                    {emp.is_active ? 'Active' : 'Inactive'}
                  </span>
                  {isAdmin() && (
                    <>
                      <button onClick={() => openEdit(emp)} className="text-xs text-indigo-600 hover:text-indigo-700 font-medium">Edit</button>
                      {isOwner() && (
                        <button
                          onClick={() => openResetPassword(emp)}
                          className="text-xs text-amber-600 hover:text-amber-700 font-medium"
                          title="Reset password"
                        >
                          Reset Password
                        </button>
                      )}
                      {emp.is_active ? (
                        <>
                          <button onClick={() => openLifecycle(emp, 'deactivate')} className="text-xs text-amber-600 hover:text-amber-700 font-medium">Deactivate</button>
                          <button onClick={() => openLifecycle(emp, 'remove')} className="text-xs text-red-500 hover:text-red-700 font-medium">Remove</button>
                        </>
                      ) : (
                        <button onClick={() => reactivateMutation.mutate(emp.id)} disabled={reactivateMutation.isPending} className="text-xs text-emerald-600 hover:text-emerald-700 font-medium disabled:opacity-50">Reactivate</button>
                      )}
                    </>
                  )}
                </div>
              </div>
            )) : (
              <div className="px-6 py-12 text-center text-gray-400 text-sm">
                No team members yet.{' '}
                {!isAtLimit && (
                  <button onClick={openCreate} className="text-indigo-600 hover:underline">
                    Add your first member
                  </button>
                )}
              </div>
            )}
          </div>
        )}
      </div>

      {lifecycleMember && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-labelledby="lifecycle-title">
          <div ref={lifecycleDialogRef} tabIndex={-1} className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl outline-none">
            <div className="flex items-start justify-between gap-4">
              <div>
                <h2 id="lifecycle-title" className="text-lg font-bold text-gray-900">
                  {lifecycleKind === 'remove' ? 'Remove' : 'Deactivate'} {lifecycleMember.name}?
                </h2>
                <p className="mt-1 text-sm text-gray-500">
                  {lifecyclePreview.isLoading
                    ? 'Checking assigned leads…'
                    : `${lifecyclePreview.data?.assigned_leads ?? 0} lead(s) are currently assigned to this member.`}
                </p>
                {!lifecyclePreview.isLoading && !lifecyclePreview.isError && (
                  <p className="mt-1 text-sm text-gray-500">
                    {lifecyclePreview.data?.active_follow_ups ?? 0} active follow-up(s) will move with these leads.
                  </p>
                )}
              </div>
              <button onClick={() => setLifecycleMember(null)} className="text-xl text-gray-400 hover:text-gray-600" aria-label="Close">×</button>
            </div>

            {(lifecycleError || lifecyclePreview.isError) && (
              <div className="mt-4 rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-700">
                {lifecycleError ?? 'Unable to verify this employee’s assigned work. Close the dialog and try again.'}
              </div>
            )}

            <div className="mt-5 space-y-3">
              <label className="flex cursor-pointer gap-3 rounded-xl border border-gray-200 p-4">
                <input type="radio" name="lead-action" checked={leadAction === 'transfer'} onChange={() => setLeadAction('transfer')} className="mt-1" />
                <span className="min-w-0 flex-1">
                  <span className="block text-sm font-semibold text-gray-900">Transfer leads</span>
                  <span className="block text-xs text-gray-500">Transfer all {lifecyclePreview.data?.assigned_leads ?? 0} lead(s) and their active follow-ups to another active team member.</span>
                  {leadAction === 'transfer' && (
                    <select value={transferTo} onChange={(event) => setTransferTo(event.target.value)} className="mt-3 w-full rounded-xl border border-gray-200 px-3 py-2 text-sm">
                      <option value="">Select team member…</option>
                      {(employees ?? []).filter((member) => member.is_active && member.id !== lifecycleMember.id).map((member) => (
                        <option key={member.id} value={member.id}>{member.name}</option>
                      ))}
                    </select>
                  )}
                </span>
              </label>
              <label className="flex cursor-pointer gap-3 rounded-xl border border-gray-200 p-4">
                <input type="radio" name="lead-action" checked={leadAction === 'unassign'} onChange={() => setLeadAction('unassign')} className="mt-1" />
                <span><span className="block text-sm font-semibold text-gray-900">Move leads to Unassigned</span><span className="block text-xs text-gray-500">Move all {lifecyclePreview.data?.assigned_leads ?? 0} lead(s) to Unassigned while preserving records and history.</span></span>
              </label>
            </div>

            <div className="mt-6 flex gap-3">
              <button onClick={submitLifecycle} disabled={lifecycleMutation.isPending || lifecyclePreview.isLoading || lifecyclePreview.isError} className={`flex-1 rounded-xl py-2.5 text-sm font-semibold text-white disabled:opacity-50 ${lifecycleKind === 'remove' ? 'bg-red-600 hover:bg-red-700' : 'bg-amber-600 hover:bg-amber-700'}`}>
                {lifecycleMutation.isPending ? 'Saving…' : leadAction === 'transfer' ? `Confirm Transfer & ${lifecycleKind === 'remove' ? 'Remove' : 'Deactivate'}` : `Confirm Unassign & ${lifecycleKind === 'remove' ? 'Remove' : 'Deactivate'}`}
              </button>
              <button onClick={() => setLifecycleMember(null)} className="rounded-xl border border-gray-200 px-4 text-sm text-gray-600">Cancel</button>
            </div>
          </div>
        </div>
      )}

      {/* ── Buy Seats Modal ── */}
      {seatModal && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6">
            <div className="flex items-center justify-between mb-5">
              <h2 className="text-lg font-bold text-gray-900">Buy Extra Seats</h2>
              <button
                onClick={() => setSeatModal(false)}
                className="text-gray-400 hover:text-gray-600 text-xl leading-none"
              >
                ✕
              </button>
            </div>

            {seatError && (
              <div className="mb-4 px-4 py-3 rounded-xl bg-red-50 border border-red-100 text-sm text-red-700">
                {seatError}
              </div>
            )}

            <p className="text-sm text-gray-500 mb-4">
              How many extra seats do you need?
            </p>

            {/* Quantity selector */}
            <div className="flex items-center justify-center gap-4 mb-5">
              <button
                onClick={() => setSeatQty((q) => Math.max(1, q - 1))}
                disabled={seatQty <= 1 || seatLoading}
                className="w-10 h-10 rounded-xl border border-gray-200 text-gray-600 text-xl font-medium hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
              >
                −
              </button>
              <span className="text-3xl font-bold text-gray-900 w-10 text-center">{seatQty}</span>
              <button
                onClick={() => setSeatQty((q) => Math.min(10, q + 1))}
                disabled={seatQty >= 10 || seatLoading}
                className="w-10 h-10 rounded-xl border border-gray-200 text-gray-600 text-xl font-medium hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
              >
                +
              </button>
            </div>

            {/* Price breakdown */}
            <div className="bg-indigo-50 rounded-xl px-4 py-3 mb-5 text-center">
              <p className="text-sm text-indigo-700 font-medium">
                {seatQty} seat{seatQty > 1 ? 's' : ''} × ₹{seatPrice.toLocaleString('en-IN')}
                {remainingMonths > 1 && ` × ${remainingMonths} months`}
                {' '}={' '}
                <span className="text-lg font-bold">
                  ₹{totalPrice.toLocaleString('en-IN')} {remainingMonths > 1 ? 'total' : '/mo'}
                </span>
              </p>
              <p className="text-xs text-indigo-500 mt-0.5">
                {remainingMonths > 1
                  ? `Pro-rated for ${remainingMonths} remaining months of your subscription`
                  : 'Charged as a one-time payment'}
              </p>
            </div>

            <div className="flex gap-3">
              <button
                onClick={handleBuySeats}
                disabled={seatLoading}
                className="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 disabled:cursor-not-allowed text-white font-bold py-2.5 rounded-xl text-sm flex items-center justify-center gap-2 transition-colors"
              >
                {seatLoading ? (
                  <span className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                ) : (
                  <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                  </svg>
                )}
                Pay ₹{totalPrice.toLocaleString('en-IN')} →
              </button>
              <button
                onClick={() => setSeatModal(false)}
                disabled={seatLoading}
                className="px-4 text-sm text-gray-500 hover:text-gray-700 border border-gray-200 rounded-xl disabled:opacity-40"
              >
                Cancel
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ── Reset Password Modal ── */}
      {pwModal && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6">

            {/* Header */}
            <div className="flex items-center justify-between mb-1">
              <h2 className="text-lg font-bold text-gray-900">Reset Password</h2>
              <button
                onClick={() => setPwModal(null)}
                className="text-gray-400 hover:text-gray-600 text-xl leading-none"
              >✕</button>
            </div>
            <p className="text-sm text-gray-500 mb-5">
              Set a new password for <span className="font-semibold text-gray-700">{pwModal.name}</span>.
            </p>

            {/* Success state */}
            {pwSuccess ? (
              <div className="flex flex-col items-center py-4 gap-3">
                <div className="w-12 h-12 rounded-full bg-emerald-100 flex items-center justify-center">
                  <svg className="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                  </svg>
                </div>
                <p className="text-sm font-semibold text-emerald-700">Password updated successfully!</p>
              </div>
            ) : (
              <>
                {pwError && (
                  <div className="mb-4 px-4 py-3 rounded-xl bg-red-50 border border-red-100 text-sm text-red-700">
                    {pwError}
                  </div>
                )}

                <div className="space-y-4">
                  <div>
                    <label className="block text-xs font-medium text-gray-700 mb-1">New Password *</label>
                    <input
                      type="password"
                      value={pwForm.password}
                      onChange={(e) => setPwForm({ ...pwForm, password: e.target.value })}
                      placeholder="Min. 8 characters"
                      className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-gray-700 mb-1">Confirm Password *</label>
                    <input
                      type="password"
                      value={pwForm.confirm}
                      onChange={(e) => setPwForm({ ...pwForm, confirm: e.target.value })}
                      placeholder="Re-enter new password"
                      className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                  </div>
                </div>

                <div className="flex gap-3 mt-6">
                  <button
                    onClick={handleResetPassword}
                    disabled={resetPasswordMutation.isPending}
                    className="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 disabled:cursor-not-allowed text-white font-semibold py-2.5 rounded-xl text-sm flex items-center justify-center gap-2 transition-colors"
                  >
                    {resetPasswordMutation.isPending && (
                      <span className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                    )}
                    Update Password
                  </button>
                  <button
                    onClick={() => setPwModal(null)}
                    disabled={resetPasswordMutation.isPending}
                    className="px-4 text-sm text-gray-500 hover:text-gray-700 border border-gray-200 rounded-xl disabled:opacity-40"
                  >
                    Cancel
                  </button>
                </div>
              </>
            )}
          </div>
        </div>
      )}

      {/* ── Add / Edit Member Modal ── */}
      {modal !== undefined && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
            <h2 className="text-lg font-bold text-gray-900 mb-5">
              {modal ? 'Edit Member' : 'Add Team Member'}
            </h2>

            {formError && (
              <div className="mb-4 px-4 py-3 rounded-xl bg-red-50 border border-red-100 text-sm text-red-700">
                {formError}
                {formError.toLowerCase().includes('seat') && isOwner() && (
                  <button
                    onClick={() => { setModal(undefined); setSeatError(null); setSeatQty(1); setSeatModal(true); }}
                    className="ml-2 font-semibold underline hover:no-underline"
                  >
                    Buy more seats →
                  </button>
                )}
              </div>
            )}

            <div className="space-y-4">
              {(['name', 'email'] as const).map((k) => (
                <div key={k}>
                  <label className="block text-xs font-medium text-gray-700 mb-1 capitalize">{k} *</label>
                  <input
                    value={form[k]}
                    onChange={(e) => setForm({ ...form, [k]: e.target.value })}
                    type={k === 'email' ? 'email' : 'text'}
                    className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
              ))}
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">
                  Password {modal ? '(leave blank to keep)' : '*'}
                </label>
                <input
                  type="password"
                  value={form.password ?? ''}
                  onChange={(e) => setForm({ ...form, password: e.target.value })}
                  className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Role</label>
                <select
                  value={form.role ?? 'employee'}
                  onChange={(e) => setForm({ ...form, role: e.target.value as 'admin' | 'employee' })}
                  className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                  <option value="employee">Employee</option>
                  <option value="admin">Admin</option>
                </select>
              </div>
            </div>

            <div className="flex gap-3 mt-6">
              <button
                onClick={handleSubmit}
                disabled={isPending}
                className="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 disabled:cursor-not-allowed text-white font-semibold py-2.5 rounded-xl text-sm flex items-center justify-center gap-2"
              >
                {isPending && (
                  <span className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                )}
                {modal ? 'Save Changes' : 'Add Member'}
              </button>
              <button
                onClick={() => { setModal(undefined); setFormError(null); }}
                className="px-4 text-sm text-gray-500 hover:text-gray-700 border border-gray-200 rounded-xl"
              >
                Cancel
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
