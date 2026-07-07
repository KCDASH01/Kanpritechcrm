'use client';

import { Suspense, useEffect, useRef } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { authApi } from '@/lib/api/auth';
import { useAuthStore } from '@/store/authStore';

function SSOPageContent() {
  const router       = useRouter();
  const params       = useSearchParams();
  const { setAuth }  = useAuthStore();
  const attempted    = useRef(false);

  useEffect(() => {
    // Prevent double-firing in React strict mode
    if (attempted.current) return;
    attempted.current = true;

    const token = params.get('token');

    if (!token) {
      router.replace('/login?error=sso_failed');
      return;
    }

    authApi
      .ssoLogin(token)
      .then(({ token: sanctumToken, user }) => {
        setAuth(user, sanctumToken);
        router.replace('/dashboard');
      })
      .catch(() => {
        router.replace('/login?error=sso_failed');
      });
  }, [params, router, setAuth]);

  return (
    <div className="min-h-screen flex flex-col items-center justify-center bg-gray-50">
      <div className="flex flex-col items-center gap-4">
        {/* Spinner */}
        <div className="w-12 h-12 rounded-full border-4 border-indigo-200 border-t-indigo-600 animate-spin" />
        <p className="text-sm font-medium text-gray-500">Signing you into CRM…</p>
      </div>
    </div>
  );
}

export default function SSOPage() {
  return (
    <Suspense fallback={
      <div className="min-h-screen flex flex-col items-center justify-center bg-gray-50">
        <div className="w-12 h-12 rounded-full border-4 border-indigo-200 border-t-indigo-600 animate-spin" />
      </div>
    }>
      <SSOPageContent />
    </Suspense>
  );
}
