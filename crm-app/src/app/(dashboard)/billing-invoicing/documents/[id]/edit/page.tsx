'use client';

import { useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import BillingPage from '@/components/billing/BillingPage';
import DocumentEditor from '@/components/billing/DocumentEditor';
import { billingInvoicingApi, BillingDocument } from '@/lib/api/billingInvoicing';

export default function EditBillingDocument() {
  const { id } = useParams<{ id: string }>();
  const canManageDrafts = useAuthStore((state) => state.isAdmin());
  const [doc, setDoc] = useState<BillingDocument | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!canManageDrafts) return;
    let active = true;
    setDoc(null);
    setError('');
    billingInvoicingApi.document(Number(id)).then((value) => {
      if (!active) return;
      if (value.draft_actions?.edit === null) setDoc(value);
      else setError(value.draft_actions?.edit || 'This document cannot be edited.');
    }).catch(() => { if (active) setError('Draft could not be loaded.'); });
    return () => { active = false; };
  }, [id, canManageDrafts]);

  const message = canManageDrafts ? error : 'Billing draft management requires owner or admin permission.';
  return (
    <BillingPage title="Edit billing draft" description="Update customer details, dates and line items. Editing an approved document returns it to draft.">
      {canManageDrafts && doc?.id === Number(id)
        ? <DocumentEditor type={doc.document_type} initial={doc} />
        : <p className={`rounded-xl p-4 ${message ? 'bg-red-50 text-red-700' : ''}`}>{message || 'Loading…'}</p>}
    </BillingPage>
  );
}
