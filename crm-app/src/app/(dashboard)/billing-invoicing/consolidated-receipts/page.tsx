import BillingPage from '@/components/billing/BillingPage';
import ReceiptWorkspace from '@/components/billing/ReceiptWorkspace';
export default function Page(){return <BillingPage title="Consolidated Receipts" description="Summarize selected same-client payments without creating additional revenue."><ReceiptWorkspace consolidated /></BillingPage>}
