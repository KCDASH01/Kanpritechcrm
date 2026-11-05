'use client';

import Link from 'next/link';
import type { Subscription } from '@/types';
import { getSubscriptionStatusPresentation } from '@/lib/subscriptionStatus';

const tones = {
  info: 'border-blue-200 bg-blue-50 text-blue-800',
  success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
  warning: 'border-amber-200 bg-amber-50 text-amber-900',
  danger: 'border-red-200 bg-red-50 text-red-800',
} as const;

const actionTones = {
  info: 'bg-blue-700 hover:bg-blue-800',
  success: 'bg-emerald-700 hover:bg-emerald-800',
  warning: 'bg-amber-700 hover:bg-amber-800',
  danger: 'bg-red-700 hover:bg-red-800',
} as const;

export function SubscriptionStatusNotice({
  subscription,
  surface = 'dashboard',
}: {
  subscription: Subscription;
  surface?: 'dashboard' | 'billing';
}) {
  const presentation = getSubscriptionStatusPresentation(subscription, { surface });
  if (!presentation) return null;

  return (
    <div className={`flex flex-col items-stretch gap-3 rounded-xl border px-4 py-3 sm:flex-row sm:items-center ${tones[presentation.tone]}`}>
      <div className="flex-1 min-w-0">
        <p className="text-sm font-semibold">{presentation.title}</p>
        <p className="mt-0.5 text-xs opacity-90">{presentation.description}</p>
      </div>
      {presentation.action && (
        <Link
          href={presentation.action.href}
          className={`shrink-0 rounded-lg px-3 py-2 text-center text-xs font-bold text-white transition-colors sm:py-1.5 ${actionTones[presentation.tone]}`}
        >
          {presentation.action.label}
        </Link>
      )}
    </div>
  );
}
