'use client';

import Link from 'next/link';
import { useAuthStore } from '@/store/authStore';
import { BrandMark } from './PublicBrand';

export default function PublicHeader() {
  const isLoggedIn = Boolean(useAuthStore((state) => state.user));

  return (
    <header className="sticky top-0 z-40 border-b border-gray-100 bg-white/90 shadow-sm shadow-gray-100/60 backdrop-blur-md">
      <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4">
        <BrandMark />
        <nav className="hidden items-center gap-6 text-sm font-medium text-gray-500 md:flex" aria-label="Public navigation">
          <Link href="/" className="transition-colors hover:text-gray-900">Home</Link>
          <Link href="/#features" className="transition-colors hover:text-gray-900">Features</Link>
          <Link href="/#pricing" className="transition-colors hover:text-gray-900">Pricing</Link>
        </nav>
        <div className="flex shrink-0 items-center gap-3">
          {isLoggedIn ? (
            <Link href="/dashboard" className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700">
              Dashboard
            </Link>
          ) : (
            <>
              <Link href="/login" className="hidden text-sm font-medium text-gray-600 transition-colors hover:text-gray-900 sm:block">Sign in</Link>
              <Link href="/register" className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700">Get started</Link>
            </>
          )}
        </div>
      </div>
    </header>
  );
}
