'use client';

import { useEffect, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { subscriptionApi } from '@/lib/api/subscription';
import { useAuthStore } from '@/store/authStore';
import { AccessDenied } from '@/components/ui/AccessDenied';
import { PaymentSuccessModal } from '@/components/ui/PaymentSuccessModal';

// Extend Window for Razorpay
declare global {
  interface Window {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    Razorpay: any;
  }
}

export default function BillingPage() {
  const { user, canManageBilling, isPaidPlan, isSsoUser } = useAuthStore();
  const queryClient = useQueryClient();

  // ── All state & hooks MUST come before any conditional return ─────────────
  const [seatQty, setSeatQty]               = useState(1);
  const [seatLoading, setSeatLoading]         = useState(false);
  const [removeLoading, setRemoveLoading]     = useState(false);
  const [seatError, setSeatError]             = useState<string | null>(null);
  const [receiptId, setReceiptId]             = useState<number | null>(null);
  const [successModal, setSuccessModal]       = useState<{ message: string; subMessage?: string } | null>(null);
  const razorpayScriptRef                     = useRef(false);

  const { data: sub, isLoading } = useQuery({
    queryKey: ['subscription'],
    queryFn:  subscriptionApi.get,
  });

  const { data: payments, isLoading: paymentsLoading } = useQuery({
    queryKey: ['subscription-payments'],
    queryFn:  subscriptionApi.getPayments,
  });

  const { data: receipt, isLoading: receiptLoading } = useQuery({
    queryKey: ['subscription-payment', receiptId],
    queryFn:  () => subscriptionApi.getPayment(receiptId!),
    enabled:  receiptId !== null,
  });

  // Load Razorpay script once
  useEffect(() => {
    if (razorpayScriptRef.current) return;
    razorpayScriptRef.current = true;
    const script = document.createElement('script');
    script.src   = 'https://checkout.razorpay.com/v1/checkout.js';
    script.async = true;
    document.body.appendChild(script);
  }, []);

  // ── Guard: non-owner, non-SSO ─────────────────────────────────────────────
  if (!canManageBilling() && !isSsoUser()) {
    return <AccessDenied reason="Billing is only accessible by the organization owner." />;
  }

  // ── SSO users: show read-only plan info + payment history ─────────────────
  if (isSsoUser()) {
    return (
      <div className="max-w-2xl space-y-6">
        <div className="bg-white rounded-2xl border border-gray-200 p-8 text-center">
          <div className="w-12 h-12 bg-violet-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
            <svg className="w-6 h-6 text-violet-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <h2 className="text-lg font-bold text-gray-900 mb-2">SSO Managed Subscription</h2>
          <p className="text-gray-500 text-sm">
            Your subscription is managed through the Lead Scraping App. Visit your Lead Scraping account to manage your plan.
          </p>
          <div className="mt-6 px-4 py-3 bg-violet-50 rounded-xl text-sm text-violet-700">
            Current plan: <strong>{sub?.plan === 'enterprise' ? 'Enterprise' : sub?.plan === 'business' ? 'Business' : 'Free'}</strong>
            {sub?.end_date && ` · Expires ${new Date(sub.end_date).toLocaleDateString()}`}
          </div>
        </div>

        {/* Payment history still visible for SSO users */}
        <PaymentHistorySection
          payments={payments}
          paymentsLoading={paymentsLoading}
          receiptId={receiptId}
          setReceiptId={setReceiptId}
          receipt={receipt}
          receiptLoading={receiptLoading}
        />
      </div>
    );
  }

  // ── Guard: authenticated but not owner (and not SSO) ─────────────────────
  if (!canManageBilling()) {
    return (
      <div className="flex items-center justify-center h-64">
        <p className="text-gray-500 text-sm">Only the organization owner can manage billing.</p>
      </div>
    );
  }

  // ── Owner view ────────────────────────────────────────────────────────────
  const isPaid        = isPaidPlan();
  const planLabel     = sub?.plan === 'enterprise' ? 'Enterprise' : sub?.plan === 'business' ? 'Business' : 'Free';
  const planColor     = sub?.plan === 'enterprise'
    ? 'bg-violet-600 text-white'
    : sub?.plan === 'business'
      ? 'bg-indigo-600 text-white'
      : 'bg-gray-100 text-gray-700';

  const extraPurchased  = sub?.extra_members_purchased ?? 0;
  const totalTeamLimit  = sub?.total_team_limit ?? 0;
  const basePlanSeats   = sub?.plan === 'enterprise' ? 10 : sub?.plan === 'business' ? 3 : 0;
  const seatUnitPrice   = sub?.extra_member_price ?? 299;
  const remainingMonths = (() => {
    if (!sub?.end_date) return 1;
    const end = new Date(sub.end_date);
    const now = new Date();
    const months = (end.getFullYear() - now.getFullYear()) * 12 + (end.getMonth() - now.getMonth());
    return Math.max(1, months);
  })();

  // ── Add Seat ──────────────────────────────────────────────────────────────
  async function handleAddSeat() {
    setSeatLoading(true);
    setSeatError(null);
    try {
      const order = await subscriptionApi.addSeat(seatQty);
      const qty   = order.quantity ?? seatQty;

      const rzp = new window.Razorpay({
        key:         order.key,
        amount:      order.amount,
        currency:    order.currency,
        name:        'LeadBase CRM',
        description: `${qty} extra team seat${qty > 1 ? 's' : ''}`,
        order_id:    order.order_id,
        prefill: { name: user?.name, email: user?.email },
        handler: async (response: {
          razorpay_payment_id: string;
          razorpay_order_id:   string;
          razorpay_signature:  string;
        }) => {
          try {
            await subscriptionApi.verifySeat({
              razorpay_order_id:   response.razorpay_order_id,
              razorpay_payment_id: response.razorpay_payment_id,
              razorpay_signature:  response.razorpay_signature,
              quantity:            qty,
            });
            queryClient.invalidateQueries({ queryKey: ['subscription'] });
            queryClient.invalidateQueries({ queryKey: ['subscription-payments'] });
            setSeatQty(1);
            setSuccessModal({
              message:    `${qty} team seat${qty > 1 ? 's' : ''} added successfully!`,
              subMessage: `You can now invite ${qty} more team member${qty > 1 ? 's' : ''} · Payment ID: ${response.razorpay_payment_id}`,
            });
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
      const msg = err instanceof Error ? err.message : 'Failed to initiate payment. Please try again.';
      setSeatError(msg);
      setSeatLoading(false);
    }
  }

  // ── Remove Seat ───────────────────────────────────────────────────────────
  async function handleRemoveSeat() {
    if (!confirm('Remove 1 purchased seat? This cannot be undone.')) return;
    setRemoveLoading(true);
    setSeatError(null);
    try {
      await subscriptionApi.removeSeat();
      queryClient.invalidateQueries({ queryKey: ['subscription'] });
    } catch (err: unknown) {
      const apiErr = err as { response?: { data?: { message?: string } } };
      setSeatError(apiErr.response?.data?.message ?? 'Failed to remove seat.');
    } finally {
      setRemoveLoading(false);
    }
  }

  return (
    <div className="max-w-2xl space-y-6">
      <h1 className="text-xl font-bold text-gray-900">Billing &amp; Subscription</h1>

      {/* ── Current Plan ── */}
      <div className="bg-white rounded-2xl border border-gray-200 p-6">
        <h2 className="font-semibold text-gray-900 mb-4">Current Plan</h2>
        {isLoading ? (
          <div className="h-16 flex items-center">
            <div className="w-5 h-5 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
          </div>
        ) : sub ? (
          <div className="flex items-center gap-4 flex-wrap">
            <div className={`px-4 py-2 rounded-xl font-bold text-lg ${planColor}`}>
              {planLabel}
            </div>
            <div className="text-sm text-gray-500">
              <p>Status: <span className="text-gray-900 font-medium capitalize">{sub.status}</span></p>
              {sub.end_date && (
                <p>Expires: <span className="text-gray-900 font-medium">
                  {new Date(sub.end_date).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })}
                </span></p>
              )}
              {sub.amount && (
                <p>Amount: <span className="text-gray-900 font-medium">{sub.currency} {Number(sub.amount).toLocaleString('en-IN')}</span></p>
              )}
            </div>
          </div>
        ) : (
          <p className="text-gray-400 text-sm">No active subscription.</p>
        )}
      </div>

      {/* ── Team Seats (paid plans only) ── */}
      {isPaid && sub && (
        <div className="bg-white rounded-2xl border border-gray-200 p-6">
          <h2 className="font-semibold text-gray-900 mb-1">Team Seats</h2>
          <p className="text-xs text-gray-400 mb-5">
            Purchase extra seats to invite more team members. Each seat costs ₹{seatUnitPrice}/month.
          </p>

          <div className="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-3 sm:gap-4">
            <div className="bg-gray-50 rounded-xl px-4 py-3 text-center">
              <p className="text-xs text-gray-400 mb-1">Plan seats</p>
              <p className="text-xl font-bold text-gray-900">{basePlanSeats}</p>
            </div>
            <div className="bg-gray-50 rounded-xl px-4 py-3 text-center">
              <p className="text-xs text-gray-400 mb-1">Extra purchased</p>
              <p className="text-xl font-bold text-indigo-600">{extraPurchased}</p>
            </div>
            <div className="bg-indigo-50 rounded-xl px-4 py-3 text-center">
              <p className="text-xs text-gray-400 mb-1">Total seats</p>
              <p className="text-xl font-bold text-indigo-700">{totalTeamLimit}</p>
            </div>
          </div>

          <div className="flex items-center gap-3 flex-wrap">
            <div className="flex items-center gap-2 border border-gray-200 rounded-xl overflow-hidden">
              <button
                onClick={() => setSeatQty((q) => Math.max(1, q - 1))}
                className="px-3 py-2 text-gray-500 hover:bg-gray-50 transition-colors disabled:opacity-40"
                disabled={seatQty <= 1 || seatLoading}
              >−</button>
              <span className="px-3 py-2 text-sm font-semibold text-gray-900 min-w-[2.5rem] text-center">
                {seatQty}
              </span>
              <button
                onClick={() => setSeatQty((q) => Math.min(10, q + 1))}
                className="px-3 py-2 text-gray-500 hover:bg-gray-50 transition-colors disabled:opacity-40"
                disabled={seatQty >= 10 || seatLoading}
              >+</button>
            </div>

            <button
              onClick={handleAddSeat}
              disabled={seatLoading}
              className="flex items-center gap-2 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
            >
              {seatLoading ? (
                <span className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
              ) : (
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                </svg>
              )}
              Add {seatQty} Seat{seatQty > 1 ? 's' : ''} · ₹{(seatQty * seatUnitPrice * remainingMonths).toLocaleString('en-IN')}{remainingMonths > 1 ? ' total' : '/mo'}
            </button>

            {extraPurchased > 0 && (
              <button
                onClick={handleRemoveSeat}
                disabled={removeLoading}
                className="flex items-center gap-2 px-4 py-2.5 border border-gray-200 text-gray-600 hover:bg-gray-50 text-sm font-medium rounded-xl transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
              >
                {removeLoading ? (
                  <span className="w-4 h-4 border-2 border-gray-400 border-t-transparent rounded-full animate-spin" />
                ) : (
                  <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 12H4" />
                  </svg>
                )}
                Remove 1 Seat
              </button>
            )}
          </div>

          {seatError && (
            <p className="mt-3 text-xs text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">
              {seatError}
            </p>
          )}

          <p className="mt-4 text-xs text-gray-400">
            {remainingMonths > 1
              ? `Pro-rated for ${remainingMonths} remaining months · ₹${seatUnitPrice.toLocaleString('en-IN')} × ${remainingMonths} months per seat.`
              : 'Charged for the current month only.'}
          </p>
        </div>
      )}

      {/* ── Upgrade CTA (free plan only) ── */}
      {(!sub || sub.plan === 'free') && (
        <div className="bg-gradient-to-br from-indigo-600 to-violet-600 rounded-2xl p-6 text-white">
          <h2 className="text-lg font-bold mb-1">Upgrade to Business or Enterprise</h2>
          <p className="text-indigo-100 text-sm mb-4">
            Unlock team management, departments, advanced analytics, and more.
          </p>
          <ul className="space-y-1 text-sm text-indigo-100 mb-6">
            <li>✓ Business: 5,000 leads, 3 team seats — ₹999/mo</li>
            <li>✓ Enterprise: Unlimited everything, 10 team seats — ₹1,999/mo</li>
            <li>✓ Extra seats available at ₹299/seat/month on both plans</li>
          </ul>
          <a
            href="/plans"
            className="inline-block px-5 py-2.5 bg-white text-indigo-700 font-semibold text-sm rounded-xl hover:bg-indigo-50 transition-colors"
          >
            View Plans
          </a>
        </div>
      )}

      {/* ── Account info ── */}
      <div className="bg-white rounded-2xl border border-gray-200 p-6">
        <h2 className="font-semibold text-gray-900 mb-4">Account</h2>
        <dl className="space-y-3 text-sm">
          <div className="flex justify-between">
            <dt className="text-gray-500">Organization</dt>
            <dd className="text-gray-900 font-medium">{user?.organization?.name}</dd>
          </div>
          <div className="flex justify-between">
            <dt className="text-gray-500">Owner</dt>
            <dd className="text-gray-900">{user?.name}</dd>
          </div>
          <div className="flex justify-between">
            <dt className="text-gray-500">Email</dt>
            <dd className="text-gray-900">{user?.email}</dd>
          </div>
        </dl>
      </div>

      {/* ── Payment History ── */}
      <PaymentHistorySection
        payments={payments}
        paymentsLoading={paymentsLoading}
        receiptId={receiptId}
        setReceiptId={setReceiptId}
        receipt={receipt}
        receiptLoading={receiptLoading}
      />

      {/* ── Payment success modal ── */}
      <PaymentSuccessModal
        open={successModal !== null}
        message={successModal?.message ?? ''}
        subMessage={successModal?.subMessage}
        onClose={() => setSuccessModal(null)}
      />
    </div>
  );
}

// ── Extracted so it can be reused in both owner & SSO views ──────────────────

import type { SubscriptionPayment } from '@/types';

function PaymentHistorySection({
  payments,
  paymentsLoading,
  receiptId,
  setReceiptId,
  receipt,
  receiptLoading,
}: {
  payments:       SubscriptionPayment[] | undefined;
  paymentsLoading: boolean;
  receiptId:      number | null;
  setReceiptId:   (id: number | null) => void;
  receipt:        SubscriptionPayment | undefined;
  receiptLoading: boolean;
}) {
  return (
    <>
      <div className="bg-white rounded-2xl border border-gray-200 p-6">
        <h2 className="font-semibold text-gray-900 mb-4">Payment History</h2>

        {paymentsLoading ? (
          <div className="flex items-center gap-2 py-4 text-gray-400 text-sm">
            <span className="w-4 h-4 border-2 border-gray-300 border-t-indigo-500 rounded-full animate-spin" />
            Loading...
          </div>
        ) : !payments?.length ? (
          <p className="text-gray-400 text-sm py-2">No payment records yet.</p>
        ) : (
          <div className="divide-y divide-gray-100">
            {payments.map((p) => {
              const statusColor =
                p.status === 'completed'
                  ? 'text-emerald-600 bg-emerald-50'
                  : p.status === 'failed'
                  ? 'text-red-600 bg-red-50'
                  : 'text-amber-600 bg-amber-50';
              const statusLabel =
                p.status === 'completed' ? '✓ Paid' : p.status === 'failed' ? '✗ Failed' : '⏳ Pending';

              return (
                <div key={p.id} className="flex items-center justify-between py-3 gap-3 flex-wrap">
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-gray-900 truncate">{p.description}</p>
                    <p className="text-xs text-gray-400 mt-0.5">
                      {new Date(p.created_at).toLocaleDateString('en-IN', {
                        day: 'numeric', month: 'short', year: 'numeric',
                      })}
                    </p>
                  </div>
                  <div className="flex items-center gap-3 shrink-0">
                    <span className="text-sm font-semibold text-gray-900">
                      ₹{Number(p.amount).toLocaleString('en-IN')}
                    </span>
                    <span className={`text-xs font-medium px-2 py-0.5 rounded-full ${statusColor}`}>
                      {statusLabel}
                    </span>
                    {p.status === 'completed' && (
                      <button
                        onClick={() => setReceiptId(p.id)}
                        className="text-xs text-indigo-600 hover:text-indigo-800 font-medium underline underline-offset-2"
                      >
                        Receipt
                      </button>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>

      {/* ── Receipt Modal ── */}
      {receiptId !== null && (
        <div
          className="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4"
          onClick={() => setReceiptId(null)}
        >
          <div
            className="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden"
            onClick={(e) => e.stopPropagation()}
          >
            {/* Modal header */}
            <div className="flex items-center justify-between px-6 pt-5 pb-3 border-b border-gray-100">
              <span className="text-sm font-semibold text-gray-700 uppercase tracking-widest">Receipt</span>
              <div className="flex items-center gap-3">
                <button
                  onClick={() => window.print()}
                  className="flex items-center gap-1.5 text-xs text-indigo-600 hover:text-indigo-800 font-medium"
                >
                  <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a1 1 0 001-1v-5H8v5a1 1 0 001 1zm1-9V3a1 1 0 00-1-1H9a1 1 0 00-1 1v5h8z" />
                  </svg>
                  Print
                </button>
                <button
                  onClick={() => setReceiptId(null)}
                  className="text-gray-400 hover:text-gray-600 transition-colors"
                >
                  <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
            </div>

            {/* Receipt body */}
            {receiptLoading || !receipt ? (
              <div className="flex items-center justify-center py-12">
                <span className="w-6 h-6 border-2 border-gray-300 border-t-indigo-500 rounded-full animate-spin" />
              </div>
            ) : (
              <div className="px-6 py-5 space-y-5">
                <p className="text-lg font-bold text-gray-900">LeadBase CRM</p>

                <div className="grid grid-cols-2 gap-1 text-sm">
                  {receipt.organization && (
                    <>
                      <span className="text-gray-400">Billed to</span>
                      <span className="text-gray-900 font-medium text-right">{receipt.organization.name}</span>
                      <span className="text-gray-400">Email</span>
                      <span className="text-gray-900 text-right">{receipt.organization.email}</span>
                    </>
                  )}
                  <span className="text-gray-400">Date</span>
                  <span className="text-gray-900 text-right">
                    {new Date(receipt.created_at).toLocaleDateString('en-IN', {
                      day: 'numeric', month: 'long', year: 'numeric',
                    })}
                  </span>
                  {receipt.gateway_payment_id && (
                    <>
                      <span className="text-gray-400">Payment ID</span>
                      <span className="text-gray-900 text-right font-mono text-xs break-all">{receipt.gateway_payment_id}</span>
                    </>
                  )}
                  {receipt.gateway_order_id && (
                    <>
                      <span className="text-gray-400">Order ID</span>
                      <span className="text-gray-900 text-right font-mono text-xs break-all">{receipt.gateway_order_id}</span>
                    </>
                  )}
                </div>

                <div className="border border-gray-100 rounded-xl overflow-hidden">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="bg-gray-50 text-gray-500 text-xs uppercase">
                        <th className="text-left px-4 py-2.5 font-medium">Description</th>
                        {receipt.quantity && <th className="text-center px-3 py-2.5 font-medium">Qty</th>}
                        <th className="text-right px-4 py-2.5 font-medium">Total</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <td className="px-4 py-3 text-gray-900">{receipt.description}</td>
                        {receipt.quantity && (
                          <td className="px-3 py-3 text-center text-gray-700">{receipt.quantity}</td>
                        )}
                        <td className="px-4 py-3 text-right font-semibold text-gray-900">
                          ₹{Number(receipt.amount).toLocaleString('en-IN')}
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <div className="flex items-center justify-between text-sm pt-1">
                  <div>
                    {receipt.valid_until && (
                      <p className="text-gray-500">
                        Valid through:{' '}
                        <span className="text-gray-900 font-medium">
                          {new Date(receipt.valid_until).toLocaleDateString('en-IN', {
                            day: 'numeric', month: 'short', year: 'numeric',
                          })}
                        </span>
                      </p>
                    )}
                  </div>
                  <span className="text-emerald-600 font-semibold bg-emerald-50 px-3 py-1 rounded-full text-xs">
                    ✓ Paid
                  </span>
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </>
  );
}
