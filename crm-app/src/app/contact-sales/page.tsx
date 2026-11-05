'use client';

import { FormEvent, useState } from 'react';
import client from '@/lib/api/client';
import PublicFooter from '@/components/public/PublicFooter';
import PublicHeader from '@/components/public/PublicHeader';
import SalesWhatsAppButton, { DEFAULT_SALES_WHATSAPP_URL } from '@/components/public/SalesWhatsAppButton';

const initialForm = {
  name: '', email: '', phone: '', company: '', team_size: '', plan: 'enterprise', message: '', website: '',
};

export default function ContactSalesPage() {
  const [form, setForm] = useState(initialForm);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const [whatsAppUrl, setWhatsAppUrl] = useState(DEFAULT_SALES_WHATSAPP_URL);

  const update = (key: keyof typeof form, value: string) => setForm((current) => ({ ...current, [key]: value }));

  async function submit(event: FormEvent) {
    event.preventDefault();
    setSubmitting(true);
    setErrors({});
    try {
      const response = await client.post<{ message: string; whatsapp_url: string }>('/contact-sales', form);
      setWhatsAppUrl(response.data.whatsapp_url);
      setSubmitted(true);
    } catch (error: unknown) {
      const data = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response?.data;
      const nextErrors: Record<string, string> = {};
      Object.entries(data?.errors ?? {}).forEach(([key, messages]) => { nextErrors[key] = messages[0]; });
      if (!Object.keys(nextErrors).length) nextErrors.general = data?.message ?? 'We could not send your enquiry. Please use WhatsApp or email sales directly.';
      setErrors(nextErrors);
    } finally {
      setSubmitting(false);
    }
  }

  const inputClass = 'mt-1.5 w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100';

  return (
    <div className="min-h-screen bg-slate-50 text-gray-900">
      <PublicHeader />
      <main className="px-4 py-12 sm:py-16">
        <div className="mx-auto grid max-w-6xl gap-8 lg:grid-cols-[0.9fr_1.1fr] lg:items-start">
          <section className="rounded-3xl bg-gradient-to-br from-indigo-700 via-violet-700 to-purple-700 p-8 text-white shadow-xl sm:p-10">
            <p className="text-xs font-bold uppercase tracking-[0.2em] text-indigo-200">Growneq CRM Sales</p>
            <h1 className="mt-4 text-3xl font-black leading-tight sm:text-4xl">Let&apos;s build the right CRM plan for your team.</h1>
            <p className="mt-5 text-sm leading-7 text-indigo-100">Tell us about your team and requirements. Your enquiry will be emailed to our sales desk, and you can send the same details through WhatsApp.</p>
            <div className="mt-8 space-y-3 text-sm text-indigo-50">
              <p>✓ Tailored Enterprise pricing and entitlements</p>
              <p>✓ Implementation and onboarding planning</p>
              <p>✓ Billing, integration, and security discussions</p>
            </div>
            <SalesWhatsAppButton className="mt-8 w-full sm:w-auto" />
          </section>

          <section className="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm sm:p-8">
            {submitted ? (
              <div className="flex min-h-[460px] flex-col items-center justify-center text-center">
                <div className="flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-2xl text-emerald-700">✓</div>
                <h2 className="mt-5 text-2xl font-bold">Enquiry emailed successfully</h2>
                <p className="mt-2 max-w-md text-sm leading-6 text-gray-600">Send the same details to our WhatsApp sales number for a faster response.</p>
                <SalesWhatsAppButton href={whatsAppUrl} label="Send details on WhatsApp" className="mt-6" />
                <button type="button" onClick={() => { setSubmitted(false); setForm(initialForm); }} className="mt-4 text-sm font-semibold text-indigo-600 hover:underline">Send another enquiry</button>
              </div>
            ) : (
              <form onSubmit={submit} className="space-y-5">
                <div><h2 className="text-2xl font-bold">Contact sales</h2><p className="mt-1 text-sm text-gray-500">Fields marked * are required.</p></div>
                {errors.general && <p role="alert" className="rounded-xl bg-red-50 p-3 text-sm text-red-700">{errors.general}</p>}
                <div className="grid gap-4 sm:grid-cols-2">
                  <label className="text-sm font-semibold">Full name *<input required value={form.name} onChange={(e) => update('name', e.target.value)} className={inputClass} />{errors.name && <span className="mt-1 block text-xs text-red-600">{errors.name}</span>}</label>
                  <label className="text-sm font-semibold">Work email *<input required type="email" value={form.email} onChange={(e) => update('email', e.target.value)} className={inputClass} />{errors.email && <span className="mt-1 block text-xs text-red-600">{errors.email}</span>}</label>
                  <label className="text-sm font-semibold">Phone / WhatsApp *<input required inputMode="tel" placeholder="+919876543210" value={form.phone} onChange={(e) => update('phone', e.target.value)} className={inputClass} />{errors.phone && <span className="mt-1 block text-xs text-red-600">{errors.phone}</span>}</label>
                  <label className="text-sm font-semibold">Company *<input required value={form.company} onChange={(e) => update('company', e.target.value)} className={inputClass} />{errors.company && <span className="mt-1 block text-xs text-red-600">{errors.company}</span>}</label>
                  <label className="text-sm font-semibold">Team size<select value={form.team_size} onChange={(e) => update('team_size', e.target.value)} className={inputClass}><option value="">Select</option><option>1-10</option><option>11-25</option><option>26-50</option><option>51-100</option><option>100+</option></select></label>
                  <label className="text-sm font-semibold">Interested plan<select value={form.plan} onChange={(e) => update('plan', e.target.value)} className={inputClass}><option value="growth">Growth</option><option value="business">Business</option><option value="enterprise">Enterprise</option><option value="not_sure">Not sure</option></select></label>
                </div>
                <label className="block text-sm font-semibold">Requirements *<textarea required rows={5} value={form.message} onChange={(e) => update('message', e.target.value)} className={inputClass} placeholder="Tell us about your sales process, team, integrations, and expected timeline." />{errors.message && <span className="mt-1 block text-xs text-red-600">{errors.message}</span>}</label>
                <label className="hidden" aria-hidden="true">Website<input tabIndex={-1} autoComplete="off" value={form.website} onChange={(e) => update('website', e.target.value)} /></label>
                <button disabled={submitting} className="w-full rounded-xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60">{submitting ? 'Sending enquiry…' : 'Send enquiry'}</button>
                <p className="text-center text-xs leading-5 text-gray-500">Prefer WhatsApp? Message us directly at +91 77519 73970.</p>
              </form>
            )}
          </section>
        </div>
      </main>
      <PublicFooter />
    </div>
  );
}
