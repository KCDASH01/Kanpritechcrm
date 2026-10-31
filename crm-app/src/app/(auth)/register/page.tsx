'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { authApi } from '@/lib/api/auth';
import { useAuthStore } from '@/store/authStore';
import { AuthBrand } from '@/components/brand/GrowneqLogo';

export default function RegisterPage() {
  const router = useRouter();
  const { setAuth } = useAuthStore();
  const [form, setForm] = useState({
    name: '',
    email: '',
    organization_name: '',
    password: '',
    password_confirmation: '',
    plan: 'growth' as 'growth' | 'business',
    billing_cycle: 'monthly' as 'monthly' | 'yearly',
    currency: 'INR' as 'INR' | 'USD',
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrors({});
    setLoading(true);
    try {
      const { token, user } = await authApi.register(form);
      setAuth(user, token);
      router.push('/start-trial');
    } catch (err: unknown) {
      const data = (err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } })?.response?.data;
      if (data?.errors) {
        const flat: Record<string, string> = {};
        for (const [k, v] of Object.entries(data.errors)) {
          flat[k] = Array.isArray(v) ? v[0] : v;
        }
        setErrors(flat);
      } else {
        setErrors({ general: data?.message ?? 'Registration failed.' });
      }
    } finally {
      setLoading(false);
    }
  };

  const field = (key: keyof typeof form, label: string, type = 'text') => (
    <div>
      <label className="block text-sm font-medium text-gray-700 mb-1.5">{label}</label>
      <input
        type={type} autoComplete={key}
        value={form[key]}
        onChange={(e) => setForm({ ...form, [key]: e.target.value })}
        className={`w-full border rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 ${errors[key] ? 'border-red-300' : 'border-gray-300'}`}
      />
      {errors[key] && <p className="text-red-500 text-xs mt-1">{errors[key]}</p>}
    </div>
  );

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
      <div className="w-full max-w-sm">
        <div className="text-center mb-8">
          <AuthBrand />
          <h1 className="text-2xl font-bold text-gray-900">Create your account</h1>
          <p className="text-gray-500 text-sm mt-1">Start managing leads and deals with Growneq CRM</p>
        </div>

        <div className="bg-white rounded-2xl shadow-sm border border-gray-200 p-8">
          <form onSubmit={handleSubmit} className="space-y-4">
            {errors.general && (
              <div className="px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm">
                {errors.general}
              </div>
            )}

            {field('name', 'Full Name')}
            {field('email', 'Email', 'email')}
            {field('organization_name', 'Organization Name')}
            {field('password', 'Password', 'password')}
            {field('password_confirmation', 'Confirm Password', 'password')}

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1.5">Plan</label>
                <select value={form.plan} onChange={(e) => setForm({ ...form, plan: e.target.value as 'growth' | 'business' })} className="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm">
                  <option value="growth">Growth</option>
                  <option value="business">Business</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1.5">Billing</label>
                <select value={form.billing_cycle} onChange={(e) => setForm({ ...form, billing_cycle: e.target.value as 'monthly' | 'yearly' })} className="w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm">
                  <option value="monthly">Monthly</option>
                  <option value="yearly">Yearly</option>
                </select>
              </div>
            </div>
            <p className="rounded-xl bg-indigo-50 px-3 py-2 text-xs leading-5 text-indigo-800">
              Start your 7-day free trial. Payment method required. Cancel anytime before your trial ends to avoid the subscription charge. Prices exclude applicable taxes.
            </p>

            <button
              type="submit" disabled={loading}
              className="w-full bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-semibold py-2.5 px-4 rounded-xl text-sm transition-colors mt-2"
            >
              {loading ? 'Creating account…' : 'Continue to secure authorization'}
            </button>
          </form>

          <p className="text-center text-sm text-gray-500 mt-6">
            Already have an account?{' '}
            <Link href="/login" className="text-indigo-600 hover:text-indigo-700 font-medium">Sign in</Link>
          </p>
        </div>
      </div>
    </div>
  );
}
