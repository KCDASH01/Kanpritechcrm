import Link from 'next/link';

export function BrandMark({ compact = false }: { compact?: boolean }) {
  return (
    <Link href="/" className="flex shrink-0 items-center gap-2.5" aria-label="Growneq CRM home">
      <span className={`${compact ? 'h-7 w-7 rounded-lg' : 'h-8 w-8 rounded-xl'} flex items-center justify-center bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-sm shadow-indigo-300/40`}>
        <svg className={compact ? 'h-4 w-4' : 'h-5 w-5'} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8} aria-hidden="true">
          <path strokeLinecap="round" strokeLinejoin="round" d="M3 13.5l5-5 4 4 5-6 4 3" />
        </svg>
      </span>
      <span className={`${compact ? 'text-sm' : 'text-lg'} font-black tracking-tight text-gray-900`}>
        Growneq <span className="text-indigo-600">CRM</span>
      </span>
    </Link>
  );
}
