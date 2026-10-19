'use client';

import { useSearchParams } from 'next/navigation';
import BillingPage from '@/components/billing/BillingPage';
import DocumentEditor from '@/components/billing/DocumentEditor';

export default function NewBillingDocument() {
  const params = useSearchParams(); const type = params.get('type') === 'invoice' ? 'invoice' : 'proforma';
  return <BillingPage title={`New ${type === 'invoice' ? 'Invoice' : 'Proforma Invoice'}`} description="Catalogue selections are snapshotted into the draft; issued documents remain immutable."><DocumentEditor type={type} links={{ lead_id: params.get('lead_id') ? Number(params.get('lead_id')) : null, client_id: params.get('client_id') ? Number(params.get('client_id')) : null, deal_id: params.get('deal_id') ? Number(params.get('deal_id')) : null }} /></BillingPage>;
}
