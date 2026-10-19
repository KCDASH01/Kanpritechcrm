'use client';

import { useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import BillingPage from '@/components/billing/BillingPage';
import DocumentEditor from '@/components/billing/DocumentEditor';
import { billingInvoicingApi, BillingDocument } from '@/lib/api/billingInvoicing';

export default function EditBillingDocument() {
  const { id } = useParams<{ id: string }>(); const [doc, setDoc] = useState<BillingDocument | null>(null); const [error, setError] = useState('');
  useEffect(() => { billingInvoicingApi.document(Number(id)).then((value) => { if (value.issued_at || !['draft', 'pending_approval'].includes(value.status)) setError('Issued or finalized documents cannot be edited.'); else setDoc(value); }).catch(() => setError('Draft could not be loaded.')); }, [id]);
  return <BillingPage title="Edit billing draft" description="Only unissued drafts are editable; server-side calculations and catalogue snapshots remain authoritative.">{doc ? <DocumentEditor type={doc.document_type} initial={doc} /> : <p className={`rounded-xl p-4 ${error ? 'bg-red-50 text-red-700' : ''}`}>{error || 'Loading…'}</p>}</BillingPage>;
}
