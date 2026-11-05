'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { subscriptionApi, type TrialAuthorization } from '@/lib/api/subscription';
import { authApi } from '@/lib/api/auth';
import { useAuthStore } from '@/store/authStore';
import { AuthBrand } from '@/components/brand/GrowneqLogo';
import SalesWhatsAppButton from '@/components/public/SalesWhatsAppButton';

export default function StartTrialPage() {
  const router = useRouter();
  const { user, setUser, isAuthenticated } = useAuthStore();
  const [authorization, setAuthorization] = useState<TrialAuthorization | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [plan, setPlan] = useState<'growth' | 'business'>('growth');
  const [billingCycle, setBillingCycle] = useState<'monthly' | 'yearly'>('monthly');
  const [storedSelection, setStoredSelection] = useState<{ plan: string; billing_cycle: string } | null>(null);
  const [selectionReady, setSelectionReady] = useState(false);

  useEffect(() => {
    const script = document.createElement('script');
    script.src = 'https://checkout.razorpay.com/v1/checkout.js';
    script.async = true;
    document.body.appendChild(script);
    return () => { script.remove(); };
  }, []);

  useEffect(() => {
    if (!isAuthenticated) router.replace('/register');
  }, [isAuthenticated, router]);

  useEffect(() => {
    if (!isAuthenticated) return;
    const params = new URLSearchParams(window.location.search);
    const requestedPlan = params.get('plan');
    const requestedCycle = params.get('billing_cycle');
    const hasRequestedPlan = requestedPlan === 'growth' || requestedPlan === 'business';
    const hasRequestedCycle = requestedCycle === 'monthly' || requestedCycle === 'yearly';
    if (hasRequestedPlan) setPlan(requestedPlan);
    if (hasRequestedCycle) setBillingCycle(requestedCycle);

    subscriptionApi.get()
      .then((subscription) => {
        if (subscription) {
          setStoredSelection({ plan: subscription.plan, billing_cycle: subscription.billing_cycle ?? 'monthly' });
          if (!hasRequestedPlan && (subscription.plan === 'growth' || subscription.plan === 'business')) setPlan(subscription.plan);
          if (!hasRequestedCycle && (subscription.billing_cycle === 'monthly' || subscription.billing_cycle === 'yearly')) setBillingCycle(subscription.billing_cycle);
        }
      })
      .finally(() => setSelectionReady(true));
  }, [isAuthenticated]);

  async function begin() {
    setLoading(true);
    setError(null);
    try {
      if (!storedSelection || storedSelection.plan !== plan || storedSelection.billing_cycle !== billingCycle) {
        await subscriptionApi.selectTrial({ plan, billing_cycle: billingCycle, currency: 'INR' });
        setStoredSelection({ plan, billing_cycle: billingCycle });
      }
      const details = await subscriptionApi.authorizeTrial();
      setAuthorization(details);
      const RazorpayCheckout = (window as unknown as { Razorpay: new (options: Record<string, unknown>) => { open: () => void } }).Razorpay;
      const checkout = new RazorpayCheckout({
        key: details.key,
        subscription_id: details.subscription_id,
        name: 'Growneq CRM',
        description: `7-day ${details.plan === 'growth' ? 'Growth' : 'Business'} trial`,
        prefill: { name: user?.name, email: user?.email },
        theme: { color: '#4f46e5' },
        handler: async (response: { razorpay_subscription_id: string; razorpay_payment_id: string; razorpay_signature: string }) => {
          try {
            await subscriptionApi.verifyTrial(response);
            const refreshed = await authApi.me();
            setUser(refreshed);
            router.replace('/dashboard');
          } catch (verifyError: unknown) {
            const apiError = verifyError as { response?: { data?: { message?: string } } };
            setError(apiError.response?.data?.message ?? 'Authorization could not be verified. Your trial was not activated.');
            setLoading(false);
          }
        },
        modal: { ondismiss: () => setLoading(false) },
      });
      checkout.open();
    } catch (requestError: unknown) {
      const apiError = requestError as { response?: { data?: { message?: string } } };
      setError(apiError.response?.data?.message ?? 'Unable to start secure authorization.');
      setLoading(false);
    }
  }

  const currencySymbol = authorization?.currency === 'INR' ? '₹' : '$';

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
      <div className="w-full max-w-lg rounded-2xl border border-gray-200 bg-white p-8 shadow-sm">
        <div className="text-center"><AuthBrand /></div>
        <h1 className="text-2xl font-bold text-gray-900">Start Your 7-Day Free Trial</h1>
        <p className="mt-2 text-sm text-gray-600">Payment method required. Cancel anytime before your trial ends to avoid the subscription charge.</p>
        <div className="mt-6 grid grid-cols-2 gap-3">
          <label className="text-xs font-semibold text-gray-700">Plan
            <select value={plan} onChange={(event) => { setPlan(event.target.value as 'growth' | 'business'); setAuthorization(null); }} className="mt-1.5 w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
              <option value="growth">Growth</option><option value="business">Business</option>
            </select>
          </label>
          <label className="text-xs font-semibold text-gray-700">Billing
            <select value={billingCycle} onChange={(event) => { setBillingCycle(event.target.value as 'monthly' | 'yearly'); setAuthorization(null); }} className="mt-1.5 w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
              <option value="monthly">Monthly</option><option value="yearly">Yearly</option>
            </select>
          </label>
        </div>
        <div className="my-6 rounded-xl bg-indigo-50 p-4 text-sm text-indigo-950">
          {authorization ? (
            <>
              <p className="font-semibold capitalize">{authorization.plan} · {authorization.billing_cycle}</p>
              <p className="mt-1">First charge: {currencySymbol}{authorization.amount.toLocaleString()} plus applicable taxes</p>
              <p>First billing date: {new Date(authorization.first_billing_at).toLocaleString('en-IN')}</p>
              <p className="mt-2 text-xs">{authorization.authorization_disclosure}</p>
            </>
          ) : (
            <p>Your exact plan price, billing frequency, first billing date, and authorization disclosure will be shown before Razorpay authorization.</p>
          )}
        </div>
        {error && <div className="mb-4 space-y-3 rounded-xl bg-red-50 p-3 text-sm text-red-700"><p>{error}</p><div className="flex flex-col gap-2 sm:flex-row"><Link href="/contact-sales" className="rounded-lg bg-white px-3 py-2 text-center font-semibold text-indigo-700 ring-1 ring-indigo-200">Contact sales</Link><SalesWhatsAppButton className="px-3 py-2" /></div></div>}
        <button onClick={begin} disabled={loading || !selectionReady} className="w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
          {loading ? 'Opening secure authorization…' : 'Authorize payment method and start trial'}
        </button>
        <p className="mt-4 text-center text-xs text-gray-500">The subscription fee is not charged during the trial. Authorization is verified by the server before access is granted.</p>
      </div>
    </div>
  );
}
