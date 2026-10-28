'use client';

import { useEffect, useRef, useState } from 'react';
import { leadIntegrationsApi, type WhatsAppEmbeddedSignupStart } from '@/lib/api/leadIntegrations';
import { loadFacebookSdk, META_MESSAGE_ORIGINS, type FacebookLoginResponse } from '@/lib/meta/facebookSdk';

type SignupAssets = { waba_id: string; phone_number_id: string };
type Notice = { kind: 'success' | 'error'; text: string };

function parseSignupEvent(event: MessageEvent): { event?: string; data?: Record<string, unknown> } | null {
  if (!META_MESSAGE_ORIGINS.has(event.origin)) return null;
  let payload: unknown = event.data;
  if (typeof payload === 'string') {
    try { payload = JSON.parse(payload); } catch { return null; }
  }
  if (!payload || typeof payload !== 'object') return null;
  const message = payload as { type?: string; event?: string; data?: Record<string, unknown> };
  if (message.type !== 'WA_EMBEDDED_SIGNUP') return null;
  return message;
}

function assetsFrom(data?: Record<string, unknown>): SignupAssets | null {
  const wabaId = String(data?.waba_id || data?.wabaId || '');
  const phoneId = String(data?.phone_number_id || data?.phoneNumberId || '');
  return /^\d+$/.test(wabaId) && /^\d+$/.test(phoneId) ? { waba_id: wabaId, phone_number_id: phoneId } : null;
}

export function WhatsAppEmbeddedSignup({ ready, canManage, onConnected, onNotice }: {
  ready: boolean;
  canManage: boolean;
  onConnected: () => void;
  onNotice: (notice: Notice) => void;
}) {
  const [busy, setBusy] = useState(false);
  const startRef = useRef<WhatsAppEmbeddedSignupStart | null>(null);
  const codeRef = useRef<string | null>(null);
  const assetsRef = useRef<SignupAssets | null>(null);
  const submittedRef = useRef(false);

  const reset = () => {
    startRef.current = null;
    codeRef.current = null;
    assetsRef.current = null;
    submittedRef.current = false;
  };

  const completeIfReady = async () => {
    const start = startRef.current;
    const code = codeRef.current;
    const assets = assetsRef.current;
    if (!start || !code || !assets || submittedRef.current) return;
    submittedRef.current = true;
    try {
      await leadIntegrationsApi.completeWhatsAppEmbeddedSignup({
        session_id: start.session_id,
        code,
        ...assets,
      });
      onNotice({ kind: 'success', text: 'WhatsApp Business is connected. Growneq verified the selected number and subscribed its official webhook.' });
      onConnected();
    } catch {
      onNotice({ kind: 'error', text: 'Meta authorization could not be verified. No active WhatsApp connection was created; start again and confirm the selected assets.' });
    } finally {
      setBusy(false);
      reset();
    }
  };

  useEffect(() => {
    const receive = (event: MessageEvent) => {
      const message = parseSignupEvent(event);
      if (!message || !startRef.current) return;
      if (message.event === 'FINISH') {
        const assets = assetsFrom(message.data);
        if (!assets) {
          setBusy(false);
          reset();
          onNotice({ kind: 'error', text: 'Meta completed signup without returning a verifiable business account and phone number. Please start again.' });
          return;
        }
        assetsRef.current = assets;
        void completeIfReady();
      } else if (message.event === 'CANCEL') {
        setBusy(false);
        reset();
        onNotice({ kind: 'error', text: 'WhatsApp Embedded Signup was cancelled. No connection was changed.' });
      } else if (message.event === 'ERROR') {
        setBusy(false);
        reset();
        onNotice({ kind: 'error', text: 'Meta reported an Embedded Signup error. No connection was changed.' });
      }
    };
    window.addEventListener('message', receive);
    return () => window.removeEventListener('message', receive);
  });

  const connect = async () => {
    setBusy(true);
    reset();
    try {
      const start = await leadIntegrationsApi.startWhatsAppEmbeddedSignup();
      startRef.current = start;
      const sdk = await loadFacebookSdk(start.app_id);
      sdk.login((response: FacebookLoginResponse) => {
        const code = response.authResponse?.code;
        if (!code) {
          setBusy(false);
          reset();
          onNotice({ kind: 'error', text: 'Meta sign-in was cancelled or did not return an authorization code. No connection was changed.' });
          return;
        }
        codeRef.current = code;
        void completeIfReady();
      }, {
        config_id: start.config_id,
        response_type: 'code',
        override_default_response_type: true,
        extras: {
          featureType: start.feature_type,
          sessionInfoVersion: start.session_info_version,
          version: start.signup_version,
        },
      });
    } catch {
      setBusy(false);
      reset();
      onNotice({ kind: 'error', text: 'WhatsApp Embedded Signup is not available. Ask the CRM administrator to verify the Meta app configuration.' });
    }
  };

  return <section className="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm sm:p-5">
    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div className="flex min-w-0 gap-3">
        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-600 text-lg font-bold uppercase text-white">w</span>
        <div>
          <h2 className="font-bold text-gray-900">WhatsApp Business</h2>
          <p className="mt-1 max-w-2xl text-sm leading-5 text-gray-600">Connect through Meta&apos;s official Embedded Signup and choose the authorized business account and phone number. Growneq never asks for a WhatsApp password or QR-code session.</p>
          <p className={`mt-2 text-xs ${ready ? 'text-emerald-700' : 'text-amber-700'}`}>{ready ? 'Ready for official Meta authorization' : 'Meta App ID, App Secret, Graph version and Embedded Signup Config ID are required on the server.'}</p>
        </div>
      </div>
      {canManage && <button type="button" onClick={connect} disabled={!ready || busy} className="min-h-11 shrink-0 rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">{busy ? 'Waiting for Meta…' : 'Connect WhatsApp Business'}</button>}
    </div>
  </section>;
}
