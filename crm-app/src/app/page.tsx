'use client';

import Link from 'next/link';
import { useAuthStore } from '@/store/authStore';

/* ─── SVG Icons ────────────────────────────────────────────────────────────── */
function IconChart() {
  return (
    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
      <path strokeLinecap="round" strokeLinejoin="round" d="M3 13.5l5-5 4 4 5-6 4 3" />
    </svg>
  );
}
function IconArrow() {
  return (
    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M13 7l5 5m0 0l-5 5m5-5H6" />
    </svg>
  );
}
function IconCheck() {
  return (
    <svg className="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
      <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
    </svg>
  );
}

/* ─── Data ─────────────────────────────────────────────────────────────────── */
const FEATURES = [
  {
    emoji: '🎯',
    bg: 'bg-indigo-50',
    title: 'Lead Management',
    desc: 'Capture, qualify, and track every lead through your sales process. Never let a prospect slip through the cracks.',
  },
  {
    emoji: '🔀',
    bg: 'bg-violet-50',
    title: 'Deal Pipelines',
    desc: 'Visual Kanban boards customised to your sales stages. Drag, drop, and close deals faster.',
  },
  {
    emoji: '📅',
    bg: 'bg-blue-50',
    title: 'Activity Tracking',
    desc: 'Log calls, emails, and meetings against any lead or deal. Stay on top of every follow-up.',
  },
  {
    emoji: '📊',
    bg: 'bg-emerald-50',
    title: 'Reports & Analytics',
    desc: 'Real-time dashboards on revenue, conversion rates, team performance, and pipeline health.',
  },
  {
    emoji: '👥',
    bg: 'bg-rose-50',
    title: 'Team Collaboration',
    desc: 'Roles, departments, and shared pipelines. Everyone sees exactly what they need.',
  },
  {
    emoji: '📋',
    bg: 'bg-amber-50',
    title: 'Proposal Generator',
    desc: 'Generate polished proposals directly from lead data in one click. Close deals faster.',
  },
];

const PILLARS = [
  { emoji: '⚡', label: 'Up and running in minutes' },
  { emoji: '🎯', label: 'Leads, deals & pipelines in one place' },
  { emoji: '📊', label: 'Real-time reports for every plan' },
  { emoji: '👥', label: 'Built for teams of any size' },
];

const PLANS = [
  {
    name: 'Free',
    price: 0,
    yearlyPrice: 0,
    color: 'border-gray-200',
    badge: null,
    desc: 'Get started at no cost. Perfect for solo reps and small teams exploring the CRM.',
    features: [
      '100 leads',
      '50 deals',
      '1 pipeline',
      'Activity tracking',
      'Basic notes & tasks',
      'Community support',
    ],
    cta: 'Get started free',
    ctaStyle: 'border border-gray-300 text-gray-700 hover:bg-gray-50',
  },
  {
    name: 'Business',
    price: 999,
    yearlyPrice: 9999,
    color: 'border-indigo-500 ring-1 ring-indigo-500',
    badge: 'Most popular',
    desc: 'For growing teams that need advanced pipelines, reports, and collaboration.',
    features: [
      '5,000 leads',
      '2,000 deals',
      '30 custom pipelines',
      '3 team seats included',
      'Advanced reports & targets',
      'Bulk lead import',
      'SSO access',
      'Priority support',
    ],
    cta: 'Start free trial',
    ctaStyle: 'bg-indigo-600 text-white hover:bg-indigo-700 shadow-md shadow-indigo-200',
  },
  {
    name: 'Enterprise',
    price: 1999,
    yearlyPrice: 19999,
    color: 'border-gray-200',
    badge: null,
    desc: 'Maximum capacity for high-volume sales teams with unlimited everything.',
    features: [
      'Unlimited leads & deals',
      'Unlimited pipelines',
      '10 team seats included',
      'All Business features',
      'Extra seats at ₹299/mo',
      'Dedicated support',
    ],
    cta: 'Start free trial',
    ctaStyle: 'bg-gray-900 text-white hover:bg-gray-800',
  },
];

/* ─── Page ─────────────────────────────────────────────────────────────────── */
export default function MarketingHome() {
  const { user } = useAuthStore();
  const isLoggedIn = !!user;

  return (
    <div className="min-h-screen flex flex-col bg-white overflow-x-hidden">

        {/* ══════════════════════════════════════════════════════════════ */}
        {/* HEADER                                                        */}
        {/* ══════════════════════════════════════════════════════════════ */}
        <header className="sticky top-0 z-40 bg-white/80 backdrop-blur-md border-b border-gray-100 shadow-sm shadow-gray-100/60">
          <div className="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between gap-4">
            <Link href="/" className="flex items-center gap-2.5 shrink-0">
              <div className="w-8 h-8 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center shadow-sm shadow-indigo-300/40">
                <IconChart />
              </div>
              <span className="text-lg font-black text-gray-900 tracking-tight">
                LeadBase <span className="text-indigo-600">CRM</span>
              </span>
            </Link>
            <nav className="hidden md:flex items-center gap-6 text-sm font-medium text-gray-500">
              <Link href="/"         className="hover:text-gray-900 transition-colors">Home</Link>
              <Link href="#features" className="hover:text-gray-900 transition-colors">Features</Link>
              <Link href="#pricing"  className="hover:text-gray-900 transition-colors">Pricing</Link>
              <Link href="/contact"  className="hover:text-gray-900 transition-colors">Contact Us</Link>
              <Link href="/blog"     className="hover:text-gray-900 transition-colors">Blog</Link>
            </nav>
            <div className="flex items-center gap-3 shrink-0">
              {isLoggedIn ? (
                <Link href="/dashboard"
                  className="mkt-btn-primary inline-flex items-center gap-2 text-sm font-semibold px-5 py-2 rounded-xl bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm">
                  Go to Dashboard
                  <IconArrow />
                </Link>
              ) : (
                <>
                  <Link href="/login"
                    className="hidden sm:block text-sm font-medium text-gray-600 hover:text-gray-900 transition-colors">
                    Sign in
                  </Link>
                  <Link href="/register"
                    className="mkt-btn-primary text-sm font-semibold px-4 py-2 rounded-xl bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm">
                    Get started
                  </Link>
                </>
              )}
            </div>
          </div>
        </header>

        <main className="flex-1">

          {/* ══════════════════════════════════════════════════════════════ */}
          {/* HERO                                                          */}
          {/* ══════════════════════════════════════════════════════════════ */}
          <section className="relative mkt-hero-mesh min-h-[calc(100vh-64px)] flex items-center justify-center py-28 px-4 overflow-hidden">

            {/* Blobs */}
            <div className="mkt-blob pointer-events-none absolute top-[8%] left-[6%] w-72 h-72 bg-indigo-300/20 rounded-full blur-3xl" />
            <div className="mkt-blob2 pointer-events-none absolute bottom-[6%] right-[6%] w-96 h-96 bg-violet-300/15 rounded-full blur-3xl" />
            <div className="mkt-blob pointer-events-none absolute top-[50%] left-[50%] -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-indigo-100/20 rounded-full blur-3xl" />

            {/* Centre content */}
            <div className="relative z-10 max-w-3xl mx-auto text-center">

              {/* Pill badge */}
              <div className="mkt-li-1 inline-flex items-center gap-2 mb-7">
                <span className="inline-flex items-center gap-2.5 px-4 py-1.5 rounded-full bg-white/90 border border-indigo-100 shadow-md shadow-indigo-100/50 text-sm font-medium backdrop-blur-sm">
                  <span className="relative flex h-2 w-2">
                    <span className="mkt-pulse-dot relative inline-flex rounded-full h-2 w-2 bg-emerald-500" />
                  </span>
                  <span className="mkt-shimmer-badge font-semibold">All-in-one CRM for modern sales teams</span>
                </span>
              </div>

              {/* Headline — layered type */}
              <h1 className="mkt-li-2 tracking-tight text-balance">
                <span className="block text-[2.6rem] sm:text-[3.8rem] lg:text-[5rem] font-black text-gray-900 leading-[1.02]">
                  Manage leads.
                </span>
                <span className="block text-[2.6rem] sm:text-[3.8rem] lg:text-[5rem] font-black leading-[1.02] mkt-gradient-text">
                  Close deals faster.
                </span>
                <span className="block mt-3 text-xl sm:text-2xl font-semibold text-gray-400 tracking-normal leading-snug">
                  Grow your revenue.
                </span>
              </h1>

              {/* Sub */}
              <p className="mkt-li-3 mt-6 text-base sm:text-lg text-gray-500 max-w-xl mx-auto leading-relaxed">
                LeadBase CRM brings your leads, deals, pipelines, and team into one beautiful workspace.
                Start free — upgrade when you grow.
              </p>

              {/* CTAs */}
              <div className="mkt-li-4 mt-10 flex flex-col sm:flex-row items-center justify-center gap-3">
                <Link href="/register"
                  className="mkt-btn-primary group inline-flex items-center gap-2 px-8 py-4 rounded-2xl bg-indigo-600 text-white font-bold text-base shadow-lg shadow-indigo-300/50">
                  Start for free
                  <span className="group-hover:translate-x-1 transition-transform"><IconArrow /></span>
                </Link>
                <Link href="#pricing"
                  className="inline-flex items-center gap-2 px-8 py-4 rounded-2xl border border-gray-200 bg-white/80 text-gray-700 font-semibold text-base hover:border-indigo-300 hover:text-indigo-700 hover:bg-indigo-50/60 transition-all backdrop-blur-sm">
                  View pricing
                </Link>
              </div>

              {/* Trust */}
              <p className="mkt-li-5 mt-6 text-xs text-gray-400 tracking-wide">
                No credit card required &nbsp;·&nbsp; Free plan available &nbsp;·&nbsp; Cancel anytime
              </p>
            </div>

            {/* ── LEFT floating cards ── */}
            <div className="mkt-li-5 hidden xl:flex flex-col gap-4 absolute left-[max(1rem,calc(50%-44rem))] top-1/2 -translate-y-1/2">

              {/* Deal Won */}
              <div className="mkt-float-a bg-white/95 backdrop-blur-sm rounded-2xl shadow-xl border border-gray-100 p-4 w-56">
                <div className="flex items-center gap-2.5 mb-3">
                  <div className="w-9 h-9 rounded-xl bg-emerald-100 flex items-center justify-center text-lg shrink-0">🏆</div>
                  <div>
                    <p className="text-xs font-bold text-gray-800">Deal Won</p>
                    <p className="text-[11px] text-gray-400">just now</p>
                  </div>
                </div>
                <p className="text-xs text-gray-500">TechCorp Ltd. · Enterprise</p>
                <p className="text-lg font-black text-emerald-600 mt-0.5">₹2,50,000</p>
                <div className="mt-2.5 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                  <div className="h-full bg-emerald-500 rounded-full w-full" />
                </div>
                <p className="text-[10px] text-emerald-600 font-medium mt-1">Stage: Closed ✓</p>
              </div>

              {/* New Lead */}
              <div className="mkt-float-b bg-white/95 backdrop-blur-sm rounded-2xl shadow-xl border border-gray-100 p-4 w-56">
                <div className="flex items-center gap-2.5 mb-3">
                  <div className="w-9 h-9 rounded-xl bg-indigo-100 flex items-center justify-center text-lg shrink-0">🎯</div>
                  <div>
                    <p className="text-xs font-bold text-gray-800">New Lead</p>
                    <p className="text-[11px] text-gray-400">3 mins ago</p>
                  </div>
                </div>
                <p className="text-xs font-semibold text-gray-800">Priya Sharma</p>
                <p className="text-[11px] text-gray-500">VP Sales · FinEdge Pvt. Ltd.</p>
                <div className="mt-2.5 flex gap-1.5 flex-wrap">
                  {['SaaS', 'Mumbai', 'Hot 🔥'].map((tag) => (
                    <span key={tag} className="text-[10px] bg-indigo-50 text-indigo-600 px-2 py-0.5 rounded-full font-medium">{tag}</span>
                  ))}
                </div>
              </div>
            </div>

            {/* ── RIGHT floating cards ── */}
            <div className="mkt-li-5 hidden xl:flex flex-col gap-4 absolute right-[max(1rem,calc(50%-44rem))] top-1/2 -translate-y-1/2">

              {/* Pipeline Health */}
              <div className="mkt-float-b bg-white/95 backdrop-blur-sm rounded-2xl shadow-xl border border-gray-100 p-4 w-52">
                <div className="flex items-center gap-2.5 mb-3">
                  <div className="w-9 h-9 rounded-xl bg-violet-100 flex items-center justify-center text-lg shrink-0">📊</div>
                  <div>
                    <p className="text-xs font-bold text-gray-800">Pipeline Health</p>
                    <p className="text-[11px] text-gray-400">this month</p>
                  </div>
                </div>
                <div className="space-y-2">
                  {[
                    { label: 'Qualified', w: '75%', color: 'bg-indigo-500' },
                    { label: 'Proposal',  w: '52%', color: 'bg-violet-500' },
                    { label: 'Closed',    w: '38%', color: 'bg-emerald-500' },
                  ].map((r) => (
                    <div key={r.label} className="flex items-center gap-2">
                      <span className="text-[10px] text-gray-400 w-14 shrink-0">{r.label}</span>
                      <div className="flex-1 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                        <div className={`h-full ${r.color} rounded-full`} style={{ width: r.w }} />
                      </div>
                      <span className="text-[10px] text-gray-400 w-6 text-right">{r.w}</span>
                    </div>
                  ))}
                </div>
              </div>

              {/* Conversion Rate */}
              <div className="mkt-float-a bg-white/95 backdrop-blur-sm rounded-2xl shadow-xl border border-gray-100 p-4 w-52">
                <div className="flex items-center gap-2.5 mb-2">
                  <div className="w-9 h-9 rounded-xl bg-amber-100 flex items-center justify-center text-lg shrink-0">⚡</div>
                  <p className="text-xs font-bold text-gray-800">Conversion Rate</p>
                </div>
                <div className="flex items-end gap-1.5">
                  <span className="text-3xl font-black text-gray-900">68</span>
                  <span className="text-sm text-gray-400 mb-1">%</span>
                  <span className="text-xs text-emerald-600 font-bold mb-1 ml-1">↑ 12%</span>
                </div>
                <div className="mt-2 h-2 bg-gray-100 rounded-full overflow-hidden">
                  <div className="h-full rounded-full" style={{ width: '68%', background: 'linear-gradient(to right,#6366f1,#8b5cf6)' }} />
                </div>
                <p className="text-[10px] text-gray-400 mt-1.5">vs last month</p>
              </div>

              {/* Team Activity */}
              <div className="mkt-float-c bg-white/95 backdrop-blur-sm rounded-2xl shadow-xl border border-gray-100 p-4 w-52">
                <div className="flex items-center gap-2 mb-2.5">
                  <span className="text-base">👥</span>
                  <span className="text-xs font-bold text-gray-700">Team Activity</span>
                  <span className="ml-auto text-[10px] text-gray-400">Today</span>
                </div>
                <div className="space-y-1.5">
                  {[
                    { icon: '🔀', text: '3 deals moved to Closed' },
                    { icon: '➕', text: '12 new leads added' },
                    { icon: '📞', text: '5 calls logged' },
                    { icon: '📋', text: '2 proposals sent' },
                  ].map((a) => (
                    <div key={a.text} className="flex items-center gap-2">
                      <span className="text-xs">{a.icon}</span>
                      <span className="text-[11px] text-gray-600">{a.text}</span>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </section>

          {/* ══════════════════════════════════════════════════════════════ */}
          {/* PILLARS BAR                                                   */}
          {/* ══════════════════════════════════════════════════════════════ */}
          <section className="border-y border-gray-100 bg-gray-50/70">
            <div className="max-w-5xl mx-auto px-4 py-8">
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-0 md:divide-x md:divide-gray-200">
                {PILLARS.map((p) => (
                  <div key={p.label} className="flex items-center justify-center gap-2.5 px-4 py-1">
                    <span className="text-xl">{p.emoji}</span>
                    <span className="text-sm font-medium text-gray-600">{p.label}</span>
                  </div>
                ))}
              </div>
            </div>
          </section>

          {/* ══════════════════════════════════════════════════════════════ */}
          {/* FEATURES                                                      */}
          {/* ══════════════════════════════════════════════════════════════ */}
          <section id="features" className="py-28 px-4">
            <div className="max-w-5xl mx-auto">
              <div className="text-center mb-16">
                <span className="inline-block px-3 py-1 rounded-full bg-indigo-50 text-indigo-600 text-xs font-bold uppercase tracking-widest border border-indigo-100">
                  Features
                </span>
                <h2 className="mt-4 text-3xl sm:text-4xl font-extrabold text-gray-900 tracking-tight">
                  Everything your sales team needs
                </h2>
                <p className="mt-3 text-gray-500 max-w-lg mx-auto text-sm sm:text-base">
                  From first contact to closed deal — LeadBase CRM has every stage covered.
                </p>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                {FEATURES.map((f) => (
                  <div key={f.title} className="mkt-card-lift group bg-white rounded-2xl border border-gray-100 p-6 shadow-sm">
                    <div className={`w-12 h-12 rounded-xl ${f.bg} flex items-center justify-center text-2xl mb-4 transition-transform group-hover:scale-110`}>
                      {f.emoji}
                    </div>
                    <h3 className="text-[15px] font-bold text-gray-900 mb-2">{f.title}</h3>
                    <p className="text-sm text-gray-500 leading-relaxed">{f.desc}</p>
                  </div>
                ))}
              </div>
            </div>
          </section>

          {/* ══════════════════════════════════════════════════════════════ */}
          {/* HOW IT WORKS                                                  */}
          {/* ══════════════════════════════════════════════════════════════ */}
          <section className="py-24 bg-gray-50/80 px-4">
            <div className="max-w-5xl mx-auto">
              <div className="text-center mb-16">
                <span className="inline-block px-3 py-1 rounded-full bg-indigo-50 text-indigo-600 text-xs font-bold uppercase tracking-widest border border-indigo-100">
                  How it works
                </span>
                <h2 className="mt-4 text-3xl sm:text-4xl font-extrabold text-gray-900 tracking-tight">
                  From signup to first closed deal in minutes
                </h2>
              </div>

              <div className="relative grid grid-cols-1 md:grid-cols-3 gap-10 md:gap-6">
                <div className="hidden md:block absolute top-8 h-px bg-gradient-to-r from-indigo-200 via-violet-200 to-indigo-200 z-0"
                  style={{ left: 'calc(16.67% + 32px)', right: 'calc(16.67% + 32px)' }} />

                {[
                  { n: '1', emoji: '✍️', title: 'Create your account', desc: 'Sign up free in 30 seconds. No credit card. No setup fees.' },
                  { n: '2', emoji: '🗂️', title: 'Add leads & build pipeline', desc: 'Import leads, set up your pipeline stages, and invite your team.' },
                  { n: '3', emoji: '🏆', title: 'Track & close deals', desc: 'Move deals through your pipeline, log activities, and celebrate wins.' },
                ].map((s) => (
                  <div key={s.n} className="relative z-10 flex flex-col items-center text-center">
                    <div className="relative w-16 h-16 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center text-2xl shadow-lg shadow-indigo-200 mb-5">
                      {s.emoji}
                      <span className="absolute -top-2 -right-2 w-6 h-6 rounded-full bg-white border-2 border-indigo-100 flex items-center justify-center text-[11px] font-black text-indigo-600 shadow-sm">
                        {s.n}
                      </span>
                    </div>
                    <h3 className="text-base font-bold text-gray-900 mb-2">{s.title}</h3>
                    <p className="text-sm text-gray-500 max-w-[220px] leading-relaxed">{s.desc}</p>
                  </div>
                ))}
              </div>
            </div>
          </section>

          {/* ══════════════════════════════════════════════════════════════ */}
          {/* PRICING                                                       */}
          {/* ══════════════════════════════════════════════════════════════ */}
          <section id="pricing" className="py-28 px-4">
            <div className="max-w-5xl mx-auto">
              <div className="text-center mb-16">
                <span className="inline-block px-3 py-1 rounded-full bg-indigo-50 text-indigo-600 text-xs font-bold uppercase tracking-widest border border-indigo-100">
                  Pricing
                </span>
                <h2 className="mt-4 text-3xl sm:text-4xl font-extrabold text-gray-900 tracking-tight">
                  Simple, transparent pricing
                </h2>
                <p className="mt-3 text-gray-500 max-w-lg mx-auto">
                  Start free. Upgrade as you grow. No hidden fees.
                </p>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-3 gap-6 items-stretch">
                {PLANS.map((plan) => (
                  <div key={plan.name}
                    className={`mkt-plan-card relative flex flex-col rounded-2xl border ${plan.color} p-8 bg-white`}>
                    {plan.badge && (
                      <span className="absolute -top-4 left-1/2 -translate-x-1/2 inline-block bg-indigo-600 text-white text-xs font-bold px-4 py-1.5 rounded-full uppercase tracking-wide">
                        {plan.badge}
                      </span>
                    )}
                    <h3 className="text-xl font-bold text-gray-900">{plan.name}</h3>
                    <div className="mt-4 flex items-end gap-1">
                      <span className="text-4xl font-extrabold text-gray-900">
                        {plan.price === 0 ? '₹0' : `₹${plan.price.toLocaleString('en-IN')}`}
                      </span>
                      <span className="text-gray-500 pb-1">/mo</span>
                    </div>
                    {plan.price > 0 && (
                      <p className="text-xs text-gray-400 mt-1">
                        ₹{plan.yearlyPrice.toLocaleString('en-IN')}/yr · save 17%
                      </p>
                    )}
                    <p className="mt-3 text-sm text-gray-500 leading-relaxed">{plan.desc}</p>

                    <ul className="mt-6 space-y-3 flex-1">
                      {plan.features.map((f) => (
                        <li key={f} className="flex items-start gap-2.5 text-sm text-gray-700">
                          <span className="mt-0.5 text-indigo-500"><IconCheck /></span>
                          {f}
                        </li>
                      ))}
                    </ul>

                    <Link href="/register"
                      className={`mt-8 block text-center py-3 px-6 rounded-xl font-semibold text-sm transition-colors ${plan.ctaStyle}`}>
                      {plan.cta}
                    </Link>
                  </div>
                ))}
              </div>

              <p className="mt-8 text-center text-sm text-gray-400">
                Extra team seats available on paid plans at ₹299/seat/month
              </p>
            </div>
          </section>

          {/* ══════════════════════════════════════════════════════════════ */}
          {/* CTA                                                           */}
          {/* ══════════════════════════════════════════════════════════════ */}
          <section className="py-24 px-4">
            <div className="max-w-4xl mx-auto">
              <div className="relative rounded-3xl overflow-hidden px-8 sm:px-16 py-20 text-center shadow-2xl shadow-indigo-300/25"
                style={{ background: 'linear-gradient(135deg, #4f46e5 0%, #6d28d9 50%, #7c3aed 100%)' }}>

                <div className="absolute inset-0 opacity-[0.07] pointer-events-none"
                  style={{ backgroundImage: 'radial-gradient(circle, white 1px, transparent 1px)', backgroundSize: '28px 28px' }} />
                <div className="absolute top-[-40px] right-[-40px] w-64 h-64 bg-violet-400/30 rounded-full blur-3xl pointer-events-none" />
                <div className="absolute bottom-[-40px] left-[-40px] w-64 h-64 bg-indigo-400/30 rounded-full blur-3xl pointer-events-none" />

                <div className="relative z-10">
                  <span className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white/10 border border-white/20 text-white/80 text-xs font-semibold uppercase tracking-widest mb-6">
                    Get started today
                  </span>
                  <h2 className="text-3xl sm:text-5xl font-black text-white leading-tight text-balance">
                    Ready to grow your<br />sales pipeline?
                  </h2>
                  <p className="mt-5 text-indigo-200 text-base sm:text-lg max-w-xl mx-auto leading-relaxed">
                    Join sales teams using LeadBase CRM to organise their pipeline and close more deals — faster.
                  </p>
                  <div className="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4">
                    <Link href="/register"
                      className="mkt-btn-primary group inline-flex items-center gap-2 px-8 py-4 rounded-2xl bg-white text-indigo-700 font-bold text-base shadow-lg">
                      Get started free
                      <span className="group-hover:translate-x-1 transition-transform"><IconArrow /></span>
                    </Link>
                    <Link href="#pricing"
                      className="inline-flex items-center gap-2 px-8 py-4 rounded-2xl border border-white/25 text-white font-semibold text-base hover:bg-white/10 hover:border-white/40 transition-all">
                      See plans &amp; pricing
                    </Link>
                  </div>
                  <p className="mt-6 text-xs text-indigo-300 tracking-wide">
                    No credit card required &nbsp;·&nbsp; Free plan included &nbsp;·&nbsp; Cancel anytime
                  </p>
                </div>
              </div>
            </div>
          </section>

        </main>

        {/* ══════════════════════════════════════════════════════════════ */}
        {/* FOOTER                                                        */}
        {/* ══════════════════════════════════════════════════════════════ */}
        <footer className="border-t border-gray-100 py-10">
          <div className="max-w-6xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-5">
            <Link href="/" className="flex items-center gap-2">
              <div className="w-7 h-7 rounded-lg bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center">
                <IconChart />
              </div>
              <span className="text-sm font-black text-gray-800">
                LeadBase <span className="text-indigo-600">CRM</span>
              </span>
            </Link>
            <p className="text-xs text-gray-400">© {new Date().getFullYear()} LeadBase CRM. All rights reserved.</p>
            <div className="flex gap-6 text-xs text-gray-400">
              <a href="#" className="hover:text-gray-700 transition-colors">Privacy</a>
              <a href="#" className="hover:text-gray-700 transition-colors">Terms</a>
            </div>
          </div>
        </footer>

      </div>
  );
}
