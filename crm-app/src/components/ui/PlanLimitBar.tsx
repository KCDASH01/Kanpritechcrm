'use client';

import Link from 'next/link';

interface PlanLimitBarProps {
  used:  number;
  limit: number;
  label: string;
}

/**
 * Compact usage progress bar shown on free-plan pages (Leads, Deals, Pipelines).
 * Renders nothing when limit is Infinity (paid plans).
 */
export function PlanLimitBar({ used, limit, label }: PlanLimitBarProps) {
  if (!isFinite(limit)) return null;

  const pct     = Math.min((used / limit) * 100, 100);
  const atLimit = used >= limit;

  const fillColor =
    atLimit        ? 'bg-red-500'   :
    pct >= 70      ? 'bg-amber-400' :
                     'bg-emerald-500';

  const textColor =
    atLimit   ? 'text-red-600'   :
    pct >= 70 ? 'text-amber-600' :
                'text-gray-500';

  return (
    <div className="flex items-center gap-3 text-xs">
      <span className="text-gray-400 font-medium w-16 shrink-0">{label}</span>

      {/* Track */}
      <div className="flex-1 max-w-[140px] h-1.5 bg-gray-100 rounded-full overflow-hidden">
        <div
          className={`h-full rounded-full transition-all duration-500 ${fillColor}`}
          style={{ width: `${Math.max(pct, 2)}%` }}
        />
      </div>

      {/* Count */}
      <span className={`font-semibold tabular-nums shrink-0 ${textColor}`}>
        {used.toLocaleString('en-IN')}/{isFinite(limit) ? limit.toLocaleString('en-IN') : '∞'}
      </span>

      {/* Upgrade link when at limit */}
      {atLimit && (
        <Link
          href="/plans"
          className="shrink-0 text-[11px] font-bold text-violet-600 hover:text-violet-800 underline underline-offset-2"
        >
          Upgrade
        </Link>
      )}
    </div>
  );
}
