'use client';

import { useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { motion } from 'framer-motion';
import { subscriptionApi, type SubscribeOrderPayload } from '@/lib/api/subscription';
import { useAuthStore } from '@/store/authStore';
import { PaymentSuccessModal } from '@/components/ui/PaymentSuccessModal';

// Extend Window for Razorpay
declare global {
  interface Window {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    Razorpay: any;
  }
}

// ── Indian states list ────────────────────────────────────────────────────────
const INDIA_STATES = [
  { code: '01', name: 'Jammu & Kashmir' },      { code: '02', name: 'Himachal Pradesh' },
  { code: '03', name: 'Punjab' },               { code: '04', name: 'Chandigarh' },
  { code: '05', name: 'Uttarakhand' },          { code: '06', name: 'Haryana' },
  { code: '07', name: 'Delhi' },                { code: '08', name: 'Rajasthan' },
  { code: '09', name: 'Uttar Pradesh' },        { code: '10', name: 'Bihar' },
  { code: '11', name: 'Sikkim' },               { code: '12', name: 'Arunachal Pradesh' },
  { code: '13', name: 'Nagaland' },             { code: '14', name: 'Manipur' },
  { code: '15', name: 'Mizoram' },              { code: '16', name: 'Tripura' },
  { code: '17', name: 'Meghalaya' },            { code: '18', name: 'Assam' },
  { code: '19', name: 'West Bengal' },          { code: '20', name: 'Jharkhand' },
  { code: '21', name: 'Odisha' },               { code: '22', name: 'Chhattisgarh' },
  { code: '23', name: 'Madhya Pradesh' },       { code: '24', name: 'Gujarat' },
  { code: '26', name: 'Dadra & Nagar Haveli and Daman & Diu' },
  { code: '27', name: 'Maharashtra' },          { code: '28', name: 'Andhra Pradesh' },
  { code: '29', name: 'Karnataka' },            { code: '30', name: 'Goa' },
  { code: '31', name: 'Lakshadweep' },          { code: '32', name: 'Kerala' },
  { code: '33', name: 'Tamil Nadu' },           { code: '34', name: 'Puducherry' },
  { code: '35', name: 'Andaman & Nicobar Islands' },
  { code: '36', name: 'Telangana' },            { code: '37', name: 'Andhra Pradesh (new)' },
  { code: '38', name: 'Ladakh' },
];
const GSTIN_REGEX = /^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/;

// ── Billing form types ────────────────────────────────────────────────────────
interface BillingForm {
  bill_to_name:       string;
  bill_to_email:      string;
  bill_to_company:    string;
  bill_to_gstin:      string;
  bill_to_address:    string;
  bill_to_city:       string;
  bill_to_pincode:    string;
  bill_to_state_code: string;
  bill_to_country:    string;
}
type BillingErrors = Partial<Record<keyof BillingForm, string>>;

// ── Billing form field (module-scope to prevent remount on re-render) ─────────
interface BillingFieldProps {
  label:      string;
  field:      keyof BillingForm;
  form:       BillingForm;
  errors:     BillingErrors;
  onChange:   (field: keyof BillingForm) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => void;
  inputCls:   (field: keyof BillingForm) => string;
  type?:      string;
  placeholder?: string;
  required?:  boolean;
}
function BillingField({ label, field, form, errors, onChange, inputCls, type = 'text', placeholder = '', required = false }: BillingFieldProps) {
  return (
    <div>
      <label className="block text-xs font-medium text-gray-600 mb-1">
        {label}{required && <span className="text-rose-500 ml-0.5">*</span>}
      </label>
      <input
        type={type}
        value={form[field]}
        onChange={onChange(field)}
        placeholder={placeholder}
        className={inputCls(field)}
      />
      {errors[field] && <p className="mt-1 text-xs text-rose-500">{errors[field]}</p>}
    </div>
  );
}

// ── Upgrade billing form modal ────────────────────────────────────────────────
interface UpgradeModalProps {
  open:         boolean;
  planName:     string;
  planKey:      'business' | 'enterprise';
  cycle:        'monthly' | 'yearly';
  price:        number;
  userName:     string;
  userEmail:    string;
  onClose:      () => void;
  onConfirm:    (payload: SubscribeOrderPayload) => void;
  isLoading:    boolean;
}

function CrmUpgradeFormModal({ open, planName, planKey, cycle, price, userName, userEmail, onClose, onConfirm, isLoading }: UpgradeModalProps) {
  const emptyForm = (): BillingForm => ({
    bill_to_name:       userName,
    bill_to_email:      userEmail,
    bill_to_company:    '',
    bill_to_gstin:      '',
    bill_to_address:    '',
    bill_to_city:       '',
    bill_to_pincode:    '',
    bill_to_state_code: '',
    bill_to_country:    'India',
  });

  const [form,   setForm]   = useState<BillingForm>(emptyForm());
  const [errors, setErrors] = useState<BillingErrors>({});

  useEffect(() => {
    if (open) { setForm(emptyForm()); setErrors({}); }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  if (!open) return null;

  const inputCls = (field: keyof BillingForm) =>
    `w-full rounded-xl border px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition ${
      errors[field] ? 'border-rose-400 bg-rose-50 focus:ring-rose-400' : 'border-gray-200 bg-white'
    }`;

  const set = (field: keyof BillingForm) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
      setForm(prev => ({ ...prev, [field]: e.target.value }));
      if (errors[field]) setErrors(prev => ({ ...prev, [field]: undefined }));
    };

  function validate(): boolean {
    const errs: BillingErrors = {};
    if (!form.bill_to_name.trim())       errs.bill_to_name        = 'Full name is required.';
    if (!form.bill_to_email.trim())      errs.bill_to_email       = 'Email is required.';
    if (!form.bill_to_address.trim())    errs.bill_to_address     = 'Address is required.';
    if (!form.bill_to_city.trim())       errs.bill_to_city        = 'City is required.';
    if (!form.bill_to_pincode.trim())    errs.bill_to_pincode     = 'Pincode is required.';
    if (!form.bill_to_state_code)        errs.bill_to_state_code  = 'State is required.';
    if (!form.bill_to_country.trim())    errs.bill_to_country     = 'Country is required.';
    if (form.bill_to_gstin && !GSTIN_REGEX.test(form.bill_to_gstin.toUpperCase())) {
      errs.bill_to_gstin = 'Invalid GSTIN format (e.g. 27AABCU9603R1ZX).';
    }
    setErrors(errs);
    return Object.keys(errs).length === 0;
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!validate()) return;
    const selectedState = INDIA_STATES.find(s => s.code === form.bill_to_state_code);
    onConfirm({
      plan:                planKey,
      cycle,
      bill_to_name:        form.bill_to_name.trim(),
      bill_to_email:       form.bill_to_email.trim(),
      bill_to_company:     form.bill_to_company.trim() || undefined,
      bill_to_gstin:       form.bill_to_gstin.trim()   || undefined,
      bill_to_address:     form.bill_to_address.trim(),
      bill_to_city:        form.bill_to_city.trim(),
      bill_to_pincode:     form.bill_to_pincode.trim(),
      bill_to_state:       selectedState?.name ?? '',
      bill_to_state_code:  form.bill_to_state_code,
      bill_to_country:     form.bill_to_country.trim(),
    });
  }

  const cycleLabel = cycle === 'yearly' ? 'year' : 'month';
  const accentCls  = planKey === 'enterprise' ? 'bg-violet-600' : 'bg-indigo-600';

  return (
    <div
      className="fixed inset-0 bg-black/40 z-50 flex items-start justify-center overflow-y-auto py-10"
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div className="max-w-2xl w-full mx-4 rounded-2xl shadow-2xl bg-white overflow-hidden">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-5 border-b border-gray-100">
          <div>
            <h2 className="text-lg font-bold text-gray-900">Upgrade to {planName}</h2>
            <p className="text-xs text-gray-400 mt-0.5">Enter your billing details before proceeding to payment.</p>
          </div>
          <button onClick={onClose} className="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition">
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        {/* Plan summary */}
        <div className="mx-6 mt-5 rounded-xl bg-indigo-50 border border-indigo-100 px-4 py-3 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className={`h-9 w-9 rounded-full ${accentCls} flex items-center justify-center shrink-0`}>
              <svg className="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" />
              </svg>
            </div>
            <div>
              <p className="text-sm font-semibold text-indigo-900">{planName} Plan</p>
              <p className="text-xs text-indigo-600 capitalize">{cycle} billing</p>
            </div>
          </div>
          <div className="text-right">
            <p className="text-lg font-extrabold text-indigo-900">₹{price.toLocaleString('en-IN')}</p>
            <p className="text-xs text-indigo-500">/ {cycleLabel}</p>
          </div>
        </div>

        {/* Form */}
        <form onSubmit={handleSubmit} noValidate>
          <div className="px-6 py-5 space-y-4">
            <p className="text-xs font-semibold text-gray-400 uppercase tracking-wide">Billing Information</p>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <BillingField label="Full Name"     field="bill_to_name"  form={form} errors={errors} onChange={set} inputCls={inputCls} required placeholder="Rahul Sharma" />
              <BillingField label="Email Address" field="bill_to_email" form={form} errors={errors} onChange={set} inputCls={inputCls} type="email" required placeholder="rahul@example.com" />
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <BillingField label="Company / Organisation" field="bill_to_company" form={form} errors={errors} onChange={set} inputCls={inputCls} placeholder="Acme Pvt Ltd (optional)" />
              <div>
                <label className="block text-xs font-medium text-gray-600 mb-1">GSTIN <span className="text-gray-400">(optional)</span></label>
                <input
                  type="text"
                  value={form.bill_to_gstin}
                  onChange={(e) => { setForm(p => ({ ...p, bill_to_gstin: e.target.value.toUpperCase() })); if (errors.bill_to_gstin) setErrors(p => ({ ...p, bill_to_gstin: undefined })); }}
                  placeholder="27AABCU9603R1ZX"
                  maxLength={15}
                  className={inputCls('bill_to_gstin')}
                />
                {errors.bill_to_gstin && <p className="mt-1 text-xs text-rose-500">{errors.bill_to_gstin}</p>}
              </div>
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">
                Address<span className="text-rose-500 ml-0.5">*</span>
              </label>
              <textarea
                rows={2}
                value={form.bill_to_address}
                onChange={set('bill_to_address')}
                placeholder="Flat / Door No., Street / Area"
                className={inputCls('bill_to_address') + ' resize-none'}
              />
              {errors.bill_to_address && <p className="mt-1 text-xs text-rose-500">{errors.bill_to_address}</p>}
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <BillingField label="City"    field="bill_to_city"    form={form} errors={errors} onChange={set} inputCls={inputCls} required placeholder="Mumbai" />
              <BillingField label="Pincode" field="bill_to_pincode" form={form} errors={errors} onChange={set} inputCls={inputCls} required placeholder="400001" />
              <BillingField label="Country" field="bill_to_country" form={form} errors={errors} onChange={set} inputCls={inputCls} required placeholder="India" />
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">
                State / UT<span className="text-rose-500 ml-0.5">*</span>
              </label>
              <select
                value={form.bill_to_state_code}
                onChange={(e) => { setForm(p => ({ ...p, bill_to_state_code: e.target.value })); if (errors.bill_to_state_code) setErrors(p => ({ ...p, bill_to_state_code: undefined })); }}
                className={inputCls('bill_to_state_code')}
              >
                <option value="">— Select state —</option>
                {INDIA_STATES.map(s => (
                  <option key={s.code} value={s.code}>{s.name}</option>
                ))}
              </select>
              {errors.bill_to_state_code && <p className="mt-1 text-xs text-rose-500">{errors.bill_to_state_code}</p>}
            </div>
          </div>

          <div className="px-6 py-4 border-t border-gray-100 flex items-center justify-between gap-3 bg-gray-50/60">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-xl border border-gray-200 text-sm font-medium text-gray-600 hover:bg-gray-100 transition">
              Cancel
            </button>
            <button
              type="submit"
              disabled={isLoading}
              className={`inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-white text-sm font-semibold ${accentCls} hover:opacity-90 active:scale-[.98] transition disabled:opacity-60`}
            >
              {isLoading ? (
                <><svg className="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" /><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>Opening checkout…</>
              ) : (
                <>Continue to Payment <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg></>
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ── Plan feature data ─────────────────────────────────────────────────────────

type FeatureValue = boolean | string;

const FEATURES: { label: string; free: FeatureValue; business: FeatureValue; enterprise: FeatureValue }[] = [
  { label: 'Leads',             free: '100',       business: '5,000',      enterprise: 'Unlimited'  },
  { label: 'Deals',             free: '50',        business: '2,000',      enterprise: 'Unlimited'  },
  { label: 'Pipelines',         free: '1',         business: '30',         enterprise: 'Unlimited'  },
  { label: 'Free team members', free: false,       business: '3',          enterprise: '10'         },
  { label: 'Extra seats',       free: false,       business: '₹299/mo',    enterprise: '₹299/mo'    },
  { label: 'Departments',       free: false,       business: 'Unlimited',  enterprise: 'Unlimited'  },
  { label: 'Bulk lead import',  free: false,       business: true,         enterprise: true         },
  { label: 'SSO access',        free: false,       business: true,         enterprise: true         },
  { label: 'Advanced reports',  free: false,       business: true,         enterprise: true         },
  { label: 'API access',        free: false,       business: true,         enterprise: true         },
  { label: 'Priority support',  free: false,       business: true,         enterprise: true         },
  { label: 'Custom pipelines',  free: false,       business: true,         enterprise: true         },
  { label: 'Community support', free: true,        business: true,         enterprise: true         },
] as const;

const PLANS = {
  free: {
    name: 'Free',
    monthly: 0,
    yearly: 0,
    description: 'Get started with CRM essentials — no credit card required.',
    highlights: ['Up to 100 leads', 'Up to 50 deals', '1 sales pipeline', 'Basic activities & notes'],
  },
  business: {
    name: 'Business',
    monthly: 999,
    yearly: 9999,
    description: 'For growing teams who need more scale and advanced tools.',
    highlights: ['Up to 5,000 leads & 2,000 deals', '3 team members included', 'Advanced reports', 'Priority support'],
  },
  enterprise: {
    name: 'Enterprise',
    monthly: 1999,
    yearly: 19999,
    description: 'For large teams that need unlimited everything and maximum seats.',
    highlights: ['Unlimited leads, deals & pipelines', '10 team members included', 'Extra seats at ₹299/mo', 'Priority support'],
  },
};

// ── Helpers ───────────────────────────────────────────────────────────────────

function CheckIcon({ className }: { className?: string }) {
  return (
    <svg className={className ?? 'w-4 h-4'} fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 13l4 4L19 7" />
    </svg>
  );
}

function CrossIcon({ className }: { className?: string }) {
  return (
    <svg className={className ?? 'w-4 h-4'} fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
    </svg>
  );
}

function FeatureCell({ value }: { value: FeatureValue }) {
  if (value === false) {
    return (
      <span className="flex items-center justify-center">
        <CrossIcon className="w-4 h-4 text-gray-300" />
      </span>
    );
  }
  if (value === true) {
    return (
      <span className="flex items-center justify-center">
        <CheckIcon className="w-4 h-4 text-emerald-500" />
      </span>
    );
  }
  return <span className="text-sm font-medium text-gray-800 text-center block">{value}</span>;
}

// ── Page ─────────────────────────────────────────────────────────────────────

export default function PlansPage() {
  const router      = useRouter();
  const queryClient = useQueryClient();
  const { user, isBusinessPlan, isEnterprisePlan, canManageBilling, isSsoUser } = useAuthStore();
  const [cycle, setCycle] = useState<'monthly' | 'yearly'>('monthly');

  // SSO Enterprise upgrade state
  const [upgradeLoading, setUpgradeLoading] = useState(false);
  const [upgradeError, setUpgradeError]     = useState<string | null>(null);
  const razorpayScriptRef                   = useRef(false);

  // Non-SSO direct subscription state
  const [subscribeModal, setSubscribeModal] = useState<{ planKey: 'business' | 'enterprise'; planName: string; price: number } | null>(null);
  const [subscribeLoading, setSubscribeLoading] = useState(false);

  // Success modal state (subscription purchase + SSO upgrade)
  const [successModal, setSuccessModal] = useState<{ message: string; subMessage?: string } | null>(null);

  const { data: sub, isLoading } = useQuery({
    queryKey: ['subscription'],
    queryFn: subscriptionApi.get,
  });

  // Load Razorpay script once (needed for SSO upgrade)
  useEffect(() => {
    if (razorpayScriptRef.current) return;
    razorpayScriptRef.current = true;
    const script = document.createElement('script');
    script.src   = 'https://checkout.razorpay.com/v1/checkout.js';
    script.async = true;
    document.body.appendChild(script);
  }, []);

  const currentPlan    = sub?.plan ?? 'free';
  const isOnBusiness   = isBusinessPlan();
  const isOnEnterprise = isEnterprisePlan();
  const isOnPaid       = isOnBusiness || isOnEnterprise;
  const canUpgrade     = canManageBilling();   // non-SSO owner
  const ssoUser        = isSsoUser();
  // SSO Business users can upgrade to Enterprise directly in CRM
  const canSsoUpgrade  = ssoUser && isOnBusiness && !isOnEnterprise;

  const yearlyBusinessDiscount = Math.round(
    (1 - PLANS.business.yearly / (PLANS.business.monthly * 12)) * 100
  );
  const yearlyEnterpriseDiscount = Math.round(
    (1 - PLANS.enterprise.yearly / (PLANS.enterprise.monthly * 12)) * 100
  );

  // ── Current plan badge label ───────────────────────────────────────────────
  function currentPlanLabel() {
    if (isOnEnterprise) return 'Enterprise';
    if (isOnBusiness)   return 'Business';
    return 'Free';
  }

  // ── SSO Enterprise upgrade via Razorpay ───────────────────────────────────
  async function handleSsoEnterpriseUpgrade() {
    setUpgradeLoading(true);
    setUpgradeError(null);
    try {
      const order = await subscriptionApi.createUpgradeOrder(cycle);

      const rzp = new window.Razorpay({
        key:         order.key,
        amount:      order.amount,
        currency:    order.currency,
        name:        'LeadBase CRM',
        description: `Enterprise Plan — ${cycle === 'yearly' ? 'Yearly' : 'Monthly'}`,
        order_id:    order.order_id,
        prefill:     { name: user?.name, email: user?.email },
        handler: async (response: {
          razorpay_payment_id: string;
          razorpay_order_id:   string;
          razorpay_signature:  string;
        }) => {
          try {
            await subscriptionApi.upgrade({
              plan:                 'enterprise',
              razorpay_order_id:    response.razorpay_order_id,
              razorpay_payment_id:  response.razorpay_payment_id,
              razorpay_signature:   response.razorpay_signature,
              amount:               order.amount / 100,
              currency:             order.currency,
              end_date:             order.end_date,
            });
            queryClient.invalidateQueries({ queryKey: ['subscription'] });
            queryClient.invalidateQueries({ queryKey: ['subscription-payments'] });
            setSuccessModal({
              message:    'Your Enterprise plan is now active!',
              subMessage: `Billing: ${cycle === 'yearly' ? 'Yearly' : 'Monthly'} · Payment ID: ${response.razorpay_payment_id}`,
            });
          } catch {
            setUpgradeError('Payment received but verification failed. Please contact support.');
          } finally {
            setUpgradeLoading(false);
          }
        },
        modal: { ondismiss: () => setUpgradeLoading(false) },
        theme: { color: '#7c3aed' },
      });

      rzp.open();
    } catch (err: unknown) {
      const apiErr = err as { response?: { data?: { message?: string } } };
      setUpgradeError(apiErr.response?.data?.message ?? 'Failed to initiate payment. Please try again.');
      setUpgradeLoading(false);
    }
  }

  // ── Non-SSO direct plan purchase via Razorpay ──────────────────────────────
  async function handleDirectUpgrade(payload: SubscribeOrderPayload) {
    setSubscribeLoading(true);
    try {
      const order = await subscriptionApi.subscribeOrder(payload);

      const rzp = new window.Razorpay({
        key:         order.key,
        amount:      order.amount,
        currency:    order.currency,
        name:        'LeadBase CRM',
        description: `${payload.plan.charAt(0).toUpperCase() + payload.plan.slice(1)} Plan — ${cycle === 'yearly' ? 'Yearly' : 'Monthly'}`,
        order_id:    order.order_id,
        prefill:     { name: payload.bill_to_name, email: payload.bill_to_email },
        handler: async (response: {
          razorpay_payment_id: string;
          razorpay_order_id:   string;
          razorpay_signature:  string;
        }) => {
          try {
            await subscriptionApi.subscribeVerify({
              razorpay_order_id:   response.razorpay_order_id,
              razorpay_payment_id: response.razorpay_payment_id,
              razorpay_signature:  response.razorpay_signature,
            });
            queryClient.invalidateQueries({ queryKey: ['subscription'] });
            queryClient.invalidateQueries({ queryKey: ['subscription-payments'] });
            setSubscribeModal(null);
            setSuccessModal({
              message:    `Your ${payload.plan.charAt(0).toUpperCase() + payload.plan.slice(1)} plan is now active!`,
              subMessage: `Billing: ${payload.cycle === 'yearly' ? 'Yearly' : 'Monthly'} · Payment ID: ${response.razorpay_payment_id}`,
            });
          } catch {
            setUpgradeError('Payment received but verification failed. Please contact support.');
          } finally {
            setSubscribeLoading(false);
          }
        },
        modal: { ondismiss: () => setSubscribeLoading(false) },
        theme: { color: '#6366f1' },
      });

      rzp.open();
    } catch (err: unknown) {
      const apiErr = err as { response?: { data?: { message?: string } } };
      setUpgradeError(apiErr.response?.data?.message ?? 'Failed to initiate payment. Please try again.');
      setSubscribeLoading(false);
    }
  }

  // ── CTA helper ────────────────────────────────────────────────────────────
  type Cta = { label: string; disabled: boolean; href?: string; ssoUpgrade?: boolean; directUpgrade?: 'business' | 'enterprise' } | null;

  function getBusinessCta(): Cta {
    // SSO Enterprise users: no button on Business card (they're on a higher plan)
    if (ssoUser && isOnEnterprise) return null;
    if (ssoUser) return { label: 'Managed via Lead Scraping App', disabled: true };
    if (isOnEnterprise) return null;
    if (!canUpgrade) {
      if (!isOnBusiness) return { label: 'Contact owner to upgrade', disabled: true };
      return null;
    }
    if (isOnBusiness) return { label: 'Manage Billing', disabled: false, href: '/billing' };
    // Non-SSO owner on free plan — open billing form modal
    return { label: 'Upgrade to Business', disabled: false, directUpgrade: 'business' };
  }

  function getEnterpriseCta(): Cta {
    // SSO Business users get a real Razorpay upgrade button
    if (canSsoUpgrade) return { label: 'Upgrade to Enterprise', disabled: false, ssoUpgrade: true };
    // SSO Enterprise users: return null so ✓ Active is shown instead
    if (ssoUser && isOnEnterprise) return null;
    if (ssoUser) return { label: 'Managed via Lead Scraping App', disabled: true };
    if (!canUpgrade) {
      if (!isOnEnterprise) return { label: 'Contact owner to upgrade', disabled: true };
      return null;
    }
    if (isOnEnterprise) return { label: 'Manage Billing', disabled: false, href: '/billing' };
    // Non-SSO owner — open billing form modal
    return { label: 'Upgrade to Enterprise', disabled: false, directUpgrade: 'enterprise' };
  }

  const businessCta   = getBusinessCta();
  const enterpriseCta = getEnterpriseCta();

  return (
    <div className="max-w-5xl space-y-8">

      {/* ── Header ── */}
      <motion.div
        initial={{ opacity: 0, y: -8 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3 }}
        className="flex items-start justify-between gap-4 flex-wrap"
      >
        <div>
          <h1 className="text-xl font-bold text-gray-900">Plans &amp; Pricing</h1>
          <p className="text-sm text-gray-500 mt-1">
            Compare plans and choose what&apos;s right for your team.
          </p>
        </div>

        {/* Current plan badge */}
        {!isLoading && (
          <div className={`flex items-center gap-2 px-3 py-1.5 rounded-xl text-sm font-semibold ${
            isOnEnterprise
              ? 'bg-violet-50 text-violet-700 border border-violet-200'
              : isOnBusiness
                ? 'bg-indigo-50 text-indigo-700 border border-indigo-200'
                : 'bg-gray-100 text-gray-600 border border-gray-200'
          }`}>
            <span className={`w-2 h-2 rounded-full ${
              isOnEnterprise ? 'bg-violet-500' : isOnBusiness ? 'bg-indigo-500' : 'bg-gray-400'
            }`} />
            Current plan: {currentPlanLabel()}
            {sub?.end_date && (
              <span className="font-normal text-xs ml-1 opacity-70">
                · expires {new Date(sub.end_date).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })}
              </span>
            )}
          </div>
        )}
      </motion.div>

      {/* ── Billing cycle toggle ── */}
      <motion.div
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        transition={{ duration: 0.3, delay: 0.05 }}
        className="flex items-center gap-3"
      >
        <span className={`text-sm font-medium ${cycle === 'monthly' ? 'text-gray-900' : 'text-gray-400'}`}>
          Monthly
        </span>
        <button
          onClick={() => setCycle((c) => c === 'monthly' ? 'yearly' : 'monthly')}
          className="relative w-12 h-6 rounded-full bg-gray-200 transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-indigo-400"
          style={{ background: cycle === 'yearly' ? '#6366f1' : undefined }}
          aria-label="Toggle billing cycle"
        >
          <span
            className="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform duration-200"
            style={{ transform: cycle === 'yearly' ? 'translateX(24px)' : 'translateX(0)' }}
          />
        </button>
        <span className={`text-sm font-medium ${cycle === 'yearly' ? 'text-gray-900' : 'text-gray-400'}`}>
          Yearly
        </span>
        {cycle === 'yearly' && (
          <motion.span
            initial={{ opacity: 0, scale: 0.8 }}
            animate={{ opacity: 1, scale: 1 }}
            className="text-xs font-semibold bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full"
          >
            Save up to {Math.max(yearlyBusinessDiscount, yearlyEnterpriseDiscount)}%
          </motion.span>
        )}
      </motion.div>

      {/* ── Plan cards ── */}
      <motion.div
        initial={{ opacity: 0, y: 8 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.35, delay: 0.1 }}
        className="grid grid-cols-1 sm:grid-cols-3 gap-5"
      >
        {/* FREE */}
        <div className={`bg-white rounded-2xl border p-6 flex flex-col ${
          currentPlan === 'free' ? 'border-gray-300 ring-2 ring-gray-200' : 'border-gray-200'
        }`}>
          <div className="mb-5">
            <div className="flex items-center justify-between mb-3">
              <span className="text-xs font-bold uppercase tracking-widest text-gray-400">Free</span>
              {currentPlan === 'free' && (
                <span className="text-xs font-semibold bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full">
                  Current
                </span>
              )}
            </div>
            <div className="flex items-end gap-1 mb-2">
              <span className="text-3xl font-extrabold text-gray-900">₹0</span>
              <span className="text-sm text-gray-400 mb-1">/ month</span>
            </div>
            <p className="text-sm text-gray-500 leading-relaxed">{PLANS.free.description}</p>
          </div>
          <ul className="space-y-2 mb-6 flex-1">
            {PLANS.free.highlights.map((h) => (
              <li key={h} className="flex items-center gap-2 text-sm text-gray-600">
                <CheckIcon className="w-4 h-4 text-gray-400 shrink-0" />
                {h}
              </li>
            ))}
          </ul>
          <div className="w-full py-2.5 rounded-xl text-sm font-semibold text-center border border-gray-200 text-gray-400 bg-gray-50">
            {currentPlan === 'free' ? 'Your current plan' : 'Free tier'}
          </div>
        </div>

        {/* BUSINESS */}
        <div className={`rounded-2xl border flex flex-col overflow-hidden ${
          isOnBusiness
            ? 'border-indigo-500 ring-2 ring-indigo-500/20'
            : 'border-indigo-200'
        }`}>
          <div className="bg-gradient-to-br from-indigo-600 to-indigo-700 px-6 pt-6 pb-5">
            <div className="flex items-center justify-between mb-3">
              <span className="text-xs font-bold uppercase tracking-widest text-indigo-200">Business</span>
              {isOnBusiness && (
                <span className="text-xs font-semibold bg-white/20 text-white px-2 py-0.5 rounded-full">
                  Current
                </span>
              )}
            </div>
            <div className="flex items-end gap-1 mb-1">
              <span className="text-3xl font-extrabold text-white">
                ₹{cycle === 'monthly'
                  ? PLANS.business.monthly.toLocaleString('en-IN')
                  : PLANS.business.yearly.toLocaleString('en-IN')}
              </span>
              <span className="text-sm text-indigo-200 mb-1">
                / {cycle === 'monthly' ? 'mo' : 'yr'}
              </span>
            </div>
            {cycle === 'yearly' && (
              <p className="text-xs text-indigo-200">
                ₹{Math.round(PLANS.business.yearly / 12).toLocaleString('en-IN')}/mo billed annually
              </p>
            )}
            <p className="text-sm text-indigo-100 leading-relaxed mt-2">{PLANS.business.description}</p>
          </div>
          <div className="bg-white px-6 pt-5 pb-6 flex flex-col flex-1">
            <ul className="space-y-2 mb-6 flex-1">
              {PLANS.business.highlights.map((h) => (
                <li key={h} className="flex items-center gap-2 text-sm text-gray-700">
                  <CheckIcon className="w-4 h-4 text-indigo-500 shrink-0" />
                  {h}
                </li>
              ))}
            </ul>
            {businessCta ? (
              businessCta.disabled ? (
                <button disabled className="w-full py-2.5 rounded-xl text-sm font-semibold border border-gray-200 text-gray-400 bg-gray-50 cursor-not-allowed">
                  {businessCta.label}
                </button>
              ) : businessCta.directUpgrade ? (
                <button
                  onClick={() => setSubscribeModal({ planKey: 'business', planName: 'Business', price: cycle === 'yearly' ? PLANS.business.yearly : PLANS.business.monthly })}
                  disabled={subscribeLoading}
                  className="w-full py-2.5 rounded-xl text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 transition-colors disabled:opacity-60"
                >
                  {businessCta.label}
                </button>
              ) : (
                <button
                  onClick={() => router.push(businessCta.href!)}
                  className="w-full py-2.5 rounded-xl text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 transition-colors"
                >
                  {businessCta.label}
                </button>
              )
            ) : isOnBusiness ? (
              <div className="w-full py-2.5 rounded-xl text-sm font-semibold text-center bg-indigo-50 text-indigo-600">
                ✓ Active
              </div>
            ) : null}
          </div>
        </div>

        {/* ENTERPRISE */}
        <div className={`rounded-2xl border flex flex-col overflow-hidden ${
          isOnEnterprise
            ? 'border-violet-500 ring-2 ring-violet-500/20'
            : canSsoUpgrade
              ? 'border-violet-400 ring-2 ring-violet-400/30'
              : 'border-violet-200'
        }`}>
          <div className="bg-gradient-to-br from-violet-600 to-purple-700 px-6 pt-6 pb-5">
            <div className="flex items-center justify-between mb-3">
              <span className="text-xs font-bold uppercase tracking-widest text-violet-200">Enterprise</span>
              {isOnEnterprise ? (
                <span className="text-xs font-semibold bg-white/20 text-white px-2 py-0.5 rounded-full">
                  Current
                </span>
              ) : (
                <span className="text-xs font-bold bg-amber-400 text-amber-900 px-2 py-0.5 rounded-full">
                  Best Value
                </span>
              )}
            </div>
            <div className="flex items-end gap-1 mb-1">
              <span className="text-3xl font-extrabold text-white">
                ₹{cycle === 'monthly'
                  ? PLANS.enterprise.monthly.toLocaleString('en-IN')
                  : PLANS.enterprise.yearly.toLocaleString('en-IN')}
              </span>
              <span className="text-sm text-violet-200 mb-1">
                / {cycle === 'monthly' ? 'mo' : 'yr'}
              </span>
            </div>
            {cycle === 'yearly' && (
              <p className="text-xs text-violet-200">
                ₹{Math.round(PLANS.enterprise.yearly / 12).toLocaleString('en-IN')}/mo billed annually
              </p>
            )}
            <p className="text-sm text-violet-100 leading-relaxed mt-2">{PLANS.enterprise.description}</p>
          </div>
          <div className="bg-white px-6 pt-5 pb-6 flex flex-col flex-1">
            <ul className="space-y-2 mb-6 flex-1">
              {PLANS.enterprise.highlights.map((h) => (
                <li key={h} className="flex items-center gap-2 text-sm text-gray-700">
                  <CheckIcon className="w-4 h-4 text-violet-500 shrink-0" />
                  {h}
                </li>
              ))}
            </ul>

            {/* Enterprise CTA */}
            {upgradeError && (
              <p className="mb-2 text-xs text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">
                {upgradeError}
              </p>
            )}
            {enterpriseCta ? (
              enterpriseCta.ssoUpgrade ? (
                // SSO Business → Enterprise Razorpay button
                <button
                  onClick={handleSsoEnterpriseUpgrade}
                  disabled={upgradeLoading}
                  className="w-full py-2.5 rounded-xl text-sm font-bold text-white bg-violet-600 hover:bg-violet-700 transition-colors disabled:opacity-60 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                >
                  {upgradeLoading && <span className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />}
                  {upgradeLoading ? 'Processing…' : `Upgrade · ₹${(cycle === 'yearly' ? PLANS.enterprise.yearly : PLANS.enterprise.monthly).toLocaleString('en-IN')}/${cycle === 'yearly' ? 'yr' : 'mo'}`}
                </button>
              ) : enterpriseCta.directUpgrade ? (
                // Non-SSO owner — open billing form modal
                <button
                  onClick={() => setSubscribeModal({ planKey: 'enterprise', planName: 'Enterprise', price: cycle === 'yearly' ? PLANS.enterprise.yearly : PLANS.enterprise.monthly })}
                  disabled={subscribeLoading}
                  className="w-full py-2.5 rounded-xl text-sm font-bold text-white bg-violet-600 hover:bg-violet-700 transition-colors disabled:opacity-60"
                >
                  {enterpriseCta.label}
                </button>
              ) : enterpriseCta.disabled ? (
                <button disabled className="w-full py-2.5 rounded-xl text-sm font-semibold border border-gray-200 text-gray-400 bg-gray-50 cursor-not-allowed">
                  {enterpriseCta.label}
                </button>
              ) : (
                <button
                  onClick={() => router.push(enterpriseCta.href!)}
                  className="w-full py-2.5 rounded-xl text-sm font-bold text-white bg-violet-600 hover:bg-violet-700 transition-colors"
                >
                  {enterpriseCta.label}
                </button>
              )
            ) : isOnEnterprise ? (
              <div className="w-full py-2.5 rounded-xl text-sm font-semibold text-center bg-violet-50 text-violet-600">
                ✓ Active
              </div>
            ) : null}
          </div>
        </div>
      </motion.div>

      {/* ── Feature comparison ── */}
      <motion.div
        initial={{ opacity: 0, y: 8 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.35, delay: 0.18 }}
        className="bg-white rounded-2xl border border-gray-200 overflow-hidden"
      >
        <div className="px-6 py-4 border-b border-gray-100">
          <h2 className="text-sm font-bold text-gray-900">Feature comparison</h2>
        </div>

        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-gray-100">
              <th className="text-left px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-400 w-2/5">
                Feature
              </th>
              <th className="text-center px-4 py-3 text-xs font-semibold uppercase tracking-wider text-gray-400 w-1/5">
                Free
              </th>
              <th className="text-center px-4 py-3 text-xs font-semibold uppercase tracking-wider text-indigo-500 w-1/5">
                Business
              </th>
              <th className="text-center px-4 py-3 text-xs font-semibold uppercase tracking-wider text-violet-500 w-1/5">
                Enterprise
              </th>
            </tr>
          </thead>
          <tbody>
            {FEATURES.map((f, i) => (
              <tr
                key={f.label}
                className={`border-b border-gray-50 last:border-0 ${
                  i % 2 === 0 ? 'bg-white' : 'bg-gray-50/40'
                }`}
              >
                <td className="px-6 py-3.5 text-sm text-gray-700 font-medium">{f.label}</td>
                <td className="px-4 py-3.5"><FeatureCell value={f.free} /></td>
                <td className="px-4 py-3.5"><FeatureCell value={f.business} /></td>
                <td className="px-4 py-3.5"><FeatureCell value={f.enterprise} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </motion.div>

      {/* ── Contextual notices ── */}
      {(ssoUser || (!canUpgrade && !isOnPaid)) && (
        <motion.div
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          transition={{ duration: 0.3, delay: 0.25 }}
          className="space-y-3"
        >
          {/* SSO Business users: explain they can upgrade Enterprise here */}
          {canSsoUpgrade && (
            <div className="flex items-start gap-3 bg-violet-50 border border-violet-200 rounded-2xl px-5 py-4">
              <svg className="w-5 h-5 text-violet-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <div>
                <p className="text-sm font-semibold text-violet-800">Upgrade to Enterprise available</p>
                <p className="text-sm text-violet-600 mt-0.5">
                  Your Business plan is managed through the Lead Scraping App. You can upgrade to Enterprise directly here — your Enterprise access will be retained even when you log in via SSO again.
                </p>
              </div>
            </div>
          )}

          {/* SSO non-Business users: fully managed externally (skip if already on Enterprise via CRM) */}
          {ssoUser && !canSsoUpgrade && !isOnEnterprise && (
            <div className="flex items-start gap-3 bg-violet-50 border border-violet-200 rounded-2xl px-5 py-4">
              <svg className="w-5 h-5 text-violet-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <div>
                <p className="text-sm font-semibold text-violet-800">Subscription managed externally</p>
                <p className="text-sm text-violet-600 mt-0.5">
                  Your plan is controlled through the Lead Scraping App. Visit your Lead Scraping account to upgrade or change your plan.
                </p>
              </div>
            </div>
          )}

          {!ssoUser && !canUpgrade && !isOnPaid && (
            <div className="flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-2xl px-5 py-4">
              <svg className="w-5 h-5 text-amber-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                  d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
              <div>
                <p className="text-sm font-semibold text-amber-800">Upgrade requires owner access</p>
                <p className="text-sm text-amber-600 mt-0.5">
                  Contact your organization owner to upgrade and unlock all features.
                </p>
              </div>
            </div>
          )}
        </motion.div>
      )}

      {/* ── Payment success modal ── */}
      <PaymentSuccessModal
        open={successModal !== null}
        message={successModal?.message ?? ''}
        subMessage={successModal?.subMessage}
        onClose={() => setSuccessModal(null)}
      />

      {/* ── Direct upgrade billing form modal (non-SSO owners) ── */}
      <CrmUpgradeFormModal
        open={subscribeModal !== null}
        planName={subscribeModal?.planName ?? ''}
        planKey={subscribeModal?.planKey ?? 'business'}
        cycle={cycle}
        price={subscribeModal?.price ?? 0}
        userName={user?.name ?? ''}
        userEmail={user?.email ?? ''}
        onClose={() => setSubscribeModal(null)}
        onConfirm={handleDirectUpgrade}
        isLoading={subscribeLoading}
      />
    </div>
  );
}
