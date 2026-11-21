'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useAuthStore } from '@/store/authStore';
import { Modal } from '@/components/ui/Modal';
import { billingInvoicingApi, type BillingDocument } from '@/lib/api/billingInvoicing';

const editIcon = <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="m16 4 4 4M4 20l4-.8L20 7a2.8 2.8 0 0 0-4-4L4.8 16z" /></svg>;
const actionClass = 'inline-flex min-h-10 items-center justify-center gap-1.5 rounded-lg border px-3 text-sm font-medium';

export function DocumentActions({ document, onDeleted }: {
  document: BillingDocument;
  onDeleted: (label: string) => void;
}) {
  const canManageDrafts = useAuthStore((state) => state.isAdmin());
  const [confirming, setConfirming] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [error, setError] = useState('');
  const actions = document.draft_actions;
  if (!canManageDrafts || !actions) return null;

  const label = document.document_number || `Draft #${document.id}`;
  const kind = document.document_type === 'invoice' ? 'invoice' : 'Proforma Invoice';
  const close = () => {
    if (!deleting) {
      setConfirming(false);
      setError('');
    }
  };

  async function remove() {
    setDeleting(true);
    setError('');
    try {
      await billingInvoicingApi.deleteDocument(document.id);
      setConfirming(false);
      onDeleted(label);
    } catch (problem) {
      const response = problem as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'The draft could not be deleted.');
    } finally {
      setDeleting(false);
    }
  }

  return (
    <>
      <div className="flex flex-wrap gap-2">
        {actions.edit === null ? (
          <Link href={`/billing-invoicing/documents/${document.id}/edit`} className={`${actionClass} border-indigo-200 text-indigo-700 hover:bg-indigo-50`} aria-label={`Edit ${label}`}>
            {editIcon} Edit
          </Link>
        ) : (
          <button type="button" disabled title={actions.edit} className={`${actionClass} text-gray-400`}>{editIcon} Edit</button>
        )}
        <button type="button" disabled={actions.delete !== null} title={actions.delete ?? undefined} onClick={() => setConfirming(true)}
          className={`${actionClass} border-red-200 text-red-700 hover:bg-red-50 disabled:border-gray-200 disabled:text-gray-400 disabled:hover:bg-white`}
          aria-label={`Delete ${label}`}>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v5m4-5v5" /></svg> Delete
        </button>
      </div>
      {confirming && <Modal open onClose={close} title={`Delete ${kind}?`}>
        <div role="alertdialog" aria-modal="true" aria-label={`Delete ${kind}`} onKeyDown={(event) => { if (event.key === 'Escape') close(); }}>
          <p className="text-sm text-gray-700">Delete <strong>{label}</strong>? This action cannot be undone through the CRM.</p>
          {error && <p role="alert" className="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">{error}</p>}
          <div className="mt-5 flex flex-wrap justify-end gap-2">
            <button type="button" autoFocus disabled={deleting} onClick={close} className={actionClass}>Cancel</button>
            <button type="button" disabled={deleting} onClick={remove} className={`${actionClass} border-red-700 bg-red-700 text-white disabled:opacity-50`}>
              {deleting ? 'Deleting…' : 'Delete draft'}
            </button>
          </div>
        </div>
      </Modal>}
    </>
  );
}
