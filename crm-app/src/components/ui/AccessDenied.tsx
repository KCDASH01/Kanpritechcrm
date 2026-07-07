'use client';

import Link from 'next/link';

interface AccessDeniedProps {
  reason?:     string;
  /** When provided, shows an "Upgrade Plan" button alongside "Back to Dashboard" */
  upgradeHref?: string;
}

export function AccessDenied({
  reason      = 'You do not have permission to view this page.',
  upgradeHref,
}: AccessDeniedProps) {
  return (
    <div className="flex flex-col items-center justify-center py-28 px-6 text-center">
      <div className="w-16 h-16 bg-red-50 rounded-2xl flex items-center justify-center mb-5 mx-auto">
        <svg className="w-8 h-8 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
            d="M12 15v2m0 0v2m0-2h2m-2 0H10m2-10a4 4 0 00-4 4v1H6a2 2 0 00-2 2v6a2 2 0 002 2h12a2 2 0 002-2v-6a2 2 0 00-2-2h-2v-1a4 4 0 00-4-4z" />
        </svg>
      </div>
      <h2 className="text-lg font-bold text-gray-900 mb-2">Access Denied</h2>
      <p className="text-sm text-gray-500 max-w-sm mb-6">{reason}</p>
      <div className="flex items-center gap-3 flex-wrap justify-center">
        <Link
          href="/dashboard"
          className="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-5 py-2.5 rounded-xl transition-colors"
        >
          ← Back to Dashboard
        </Link>
        {upgradeHref && (
          <Link
            href={upgradeHref}
            className="inline-flex items-center gap-2 bg-violet-600 hover:bg-violet-700 text-white text-sm font-semibold px-5 py-2.5 rounded-xl transition-colors"
          >
            Upgrade Plan →
          </Link>
        )}
      </div>
    </div>
  );
}
