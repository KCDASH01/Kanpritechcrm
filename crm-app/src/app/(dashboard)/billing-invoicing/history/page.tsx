'use client';
import { useSearchParams } from 'next/navigation';
import BillingPage from '@/components/billing/BillingPage';
import BillingHistory from '@/components/billing/BillingHistory';
export default function Page(){const params=useSearchParams();const clientId=params.get('client_id')?Number(params.get('client_id')):undefined;const dealId=params.get('deal_id')?Number(params.get('deal_id')):undefined;return <BillingPage title="Billing History" description="Organization-scoped Proformas, Invoices, existing payments and immutable receipts."><BillingHistory clientId={clientId} dealId={dealId}/></BillingPage>}
