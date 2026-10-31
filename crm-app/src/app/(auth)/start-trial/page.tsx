'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { subscriptionApi, type TrialAuthorization } from '@/lib/api/subscription';
import { authApi } from '@/lib/api/auth';
import { useAuthStore } from '@/store/authStore';
import { AuthBrand } from '@/components/brand/GrowneqLogo';

export default function StartTrialPage() {
  const router = useRouter();
  const { user, setUser, isAuthenticated } = useAuthStore();
  const [authorization, setAuthorization] = useState<TrialAuthorization | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

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

  async function begin() {
    setLoading(true);
    setError(null);
    try {
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
        {error && <p className="mb-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">{error}</p>}
        <button onClick={begin} disabled={loading} className="w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
          {loading ? 'Opening secure authorization…' : 'Authorize payment method and start trial'}
        </button>
        <p className="mt-4 text-center text-xs text-gray-500">The subscription fee is not charged during the trial. Authorization is verified by the server before access is granted.</p>
      </div>
    </div>
  );
}
