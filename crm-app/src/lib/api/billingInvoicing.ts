import client from './client';

export type BillingRole = 'owner' | 'admin' | 'employee';
export type BillingPermission = 'view' | 'create_draft' | 'edit_draft' | 'issue' | 'void' | 'send' | 'allocate_payments' | 'generate_receipts' | 'manage_recurring' | 'manage_settings' | 'manage_sequences' | 'view_audit';

export interface BillingBankAccount {
  id: number;
  account_name: string;
  bank_name: string;
  account_number: string;
  ifsc?: string | null;
  swift?: string | null;
  upi_id?: string | null;
  branch_name?: string | null;
  currency: string;
  is_default: boolean;
  is_active: boolean;
}

export interface BillingProfile {
  id: number;
  legal_name?: string | null;
  trade_name?: string | null;
  logo_path?: string | null;
  signature_path?: string | null;
  registered_address?: string | null;
  billing_address?: string | null;
  city?: string | null;
  state?: string | null;
  postal_code?: string | null;
  country: string;
  contact_email?: string | null;
  contact_phone?: string | null;
  website?: string | null;
  gstin?: string | null;
  pan?: string | null;
  tax_registrations?: Record<string, string> | null;
  default_currency: string;
  supported_currencies: string[];
  payment_terms_days: number;
  approval_required?: boolean;
  default_notes?: string | null;
  default_terms?: string | null;
  default_payment_link?: string | null;
  document_footer?: string | null;
  default_template: string;
  financial_year_start_month: number;
  tax_settings?: { gst_enabled?: boolean; default_gst_rate?: number; rounding_scale?: 0 | 2 } | null;
  permissions: Record<BillingRole, Record<BillingPermission, boolean>>;
  bank_accounts: BillingBankAccount[];
}

export interface CatalogueItem {
  id: number; type: 'product' | 'service'; code: string; name: string; category?: string | null;
  description?: string | null; unit?: string | null; hsn_sac?: string | null; default_tax_rate: string;
  prices: Record<string, number>; is_recurring: boolean; is_active: boolean;
}

export interface BillingLineItem {
  id?: number; catalogue_item_id?: number | null; description: string; hsn_sac?: string | null;
  quantity: string | number; unit?: string | null; unit_price: string | number;
  discount_type?: 'fixed' | 'percentage' | null; discount_value?: string | number; gst_rate?: string | number;
  line_total?: string; catalogue_snapshot?: Partial<CatalogueItem> | null;
}

export interface BillingDocument {
  id: number; uuid: string; document_type: 'proforma' | 'invoice'; status: string; payment_status: string;
  delivery_status: string; document_number?: string | null; lead_id?: number | null; client_id?: number | null;
  deal_id?: number | null; assigned_to?: number | null; currency: string; due_date?: string | null;
  grand_total: string; subtotal: string; discount_total: string; cgst_total: string; sgst_total: string;
  igst_total: string; template: string; notes?: string | null; terms?: string | null; issued_at?: string | null;
  tax_context?: { payment_link?: string | null; [key: string]: unknown } | null;
  items?: BillingLineItem[]; customer_snapshot?: Record<string, string | null>; created_at: string;
  converted_to_document_id?: number | null; source_document_id?: number | null;
  client?: { id: number; full_name?: string; company?: string | null } | null;
  deal?: { id: number; title: string } | null;
  lead?: { id: number; full_name?: string; company?: string | null } | null;
  allocations?: PaymentAllocation[];
}

export interface DealPaymentRecord {
  id: number; deal_id: number; organization_id: number; amount: string; payment_date: string;
  payment_mode: string; txn_or_utr_number?: string | null; notes?: string | null;
  deal?: { id: number; title: string; client_id?: number | null; currency?: string } | null;
  billing_allocations?: PaymentAllocation[];
}

export interface PaymentAllocation {
  id: number; billing_document_id: number; deal_payment_id: number; amount: string;
  reversed_at?: string | null; reversal_reason?: string | null; payment?: DealPaymentRecord;
  document?: Pick<BillingDocument, 'id' | 'document_number' | 'grand_total' | 'payment_status'>;
}

export interface BillingReceiptRecord {
  id: number; receipt_number: string; client_id?: number | null; deal_payment_id: number;
  issued_at: string; payment_snapshot?: Record<string, unknown>; payment?: DealPaymentRecord;
}

export interface ConsolidatedReceiptRecord {
  id: number; receipt_number: string; client_id: number; represented_total: string; issued_at: string;
  period_start?: string | null; period_end?: string | null;
  billing_document_id?: number | null; invoice_snapshot?: { currency?: string; document_number?: string; grand_total?: string } | null;
  client?: { id: number; full_name?: string; company?: string | null };
  payments?: Array<{ id: number; deal_payment_id: number; amount_snapshot: string; payment?: DealPaymentRecord }>;
}

export interface InvoiceReceiptSummary {
  invoice: BillingDocument;
  payments: Array<{ deal_payment_id: number; payment_date: string; payment_method: string; reference?: string | null; product_details?: string | null; allocated_amount: string; receipt_number?: string | null }>;
  total_billed: string; total_received: string; balance_due: string; payment_status: 'unpaid' | 'partially_paid' | 'paid';
}

export interface BillingOverview {
  issued_invoices: number; invoiced_total: string; allocated_total: string; outstanding_total: string;
  overdue_invoices: number; drafts: number; pending_approvals: number; recent_documents: BillingDocument[];
}

export interface Paginated<T> { data: T[]; current_page: number; last_page: number; total: number }

export interface BillingSequence {
  id: number;
  document_type: 'proforma' | 'invoice' | 'payment_receipt' | 'consolidated_receipt';
  prefix: string;
  suffix: string;
  starting_number: number;
  next_number: number;
  padding: number;
  numbering_period: 'continuous' | 'calendar_year' | 'financial_year';
  reset_on_period_change: boolean;
  preview: string;
}

export const billingInvoicingApi = {
  overview: () => client.get<{ data: BillingOverview }>('/billing-invoicing/overview').then((r) => r.data.data),
  documents: (params?: Record<string, string | number | undefined>) => client.get<Paginated<BillingDocument>>('/billing-invoicing/documents', { params }).then((r) => r.data),
  document: (id: number) => client.get<{ data: BillingDocument }>(`/billing-invoicing/documents/${id}`).then((r) => r.data.data),
  createDocument: (payload: Record<string, unknown>) => client.post<{ data: BillingDocument }>('/billing-invoicing/documents', payload).then((r) => r.data.data),
  updateDocument: (id: number, payload: Record<string, unknown>) => client.put<{ data: BillingDocument }>(`/billing-invoicing/documents/${id}`, payload).then((r) => r.data.data),
  submitDocument: (id: number) => client.post<{ data: BillingDocument }>(`/billing-invoicing/documents/${id}/submit`).then((r) => r.data.data),
  approveDocument: (id: number) => client.post<{ data: BillingDocument }>(`/billing-invoicing/documents/${id}/approve`).then((r) => r.data.data),
  issueDocument: (id: number) => client.post<{ data: BillingDocument }>(`/billing-invoicing/documents/${id}/issue`).then((r) => r.data.data),
  convertDocument: (id: number, confirm = false) => client.post<{ data: BillingDocument }>(`/billing-invoicing/documents/${id}/convert-to-invoice`, { confirm_deal_value_difference: confirm }).then((r) => r.data.data),
  linkCommercial: (id: number, clientId: number, dealId: number) => client.post<{ data: BillingDocument }>(`/billing-invoicing/documents/${id}/link-commercial`, { client_id: clientId, deal_id: dealId }).then((r) => r.data.data),
  catalogue: (params?: Record<string, string | number | boolean | undefined>) => client.get<Paginated<CatalogueItem>>('/billing-invoicing/catalogue', { params }).then((r) => r.data),
  createCatalogueItem: (payload: Omit<CatalogueItem, 'id'>) => client.post<{ data: CatalogueItem }>('/billing-invoicing/catalogue', payload).then((r) => r.data.data),
  updateCatalogueItem: (id: number, payload: Omit<CatalogueItem, 'id'>) => client.put<{ data: CatalogueItem }>(`/billing-invoicing/catalogue/${id}`, payload).then((r) => r.data.data),
  archiveCatalogueItem: (id: number) => client.delete(`/billing-invoicing/catalogue/${id}`),
  payments: (params?: Record<string, string | number | undefined>) => client.get<Paginated<DealPaymentRecord>>('/billing-invoicing/payments', { params }).then((r) => r.data),
  allocatePayment: (documentId: number, paymentId: number, amount: number) => client.post<{ data: PaymentAllocation }>(`/billing-invoicing/documents/${documentId}/allocations`, { deal_payment_id: paymentId, amount }).then((r) => r.data.data),
  reverseAllocation: (allocationId: number, reason: string) => client.post<{ data: PaymentAllocation }>(`/billing-invoicing/allocations/${allocationId}/reverse`, { reason }).then((r) => r.data.data),
  receipts: (params?: Record<string, string | number | undefined>) => client.get<Paginated<BillingReceiptRecord>>('/billing-invoicing/receipts', { params }).then((r) => r.data),
  createReceipt: (paymentId: number) => client.post<{ data: BillingReceiptRecord }>('/billing-invoicing/receipts', { deal_payment_id: paymentId }).then((r) => r.data.data),
  invoiceReceiptSummary: (documentId: number) => client.get<{ data: InvoiceReceiptSummary }>(`/billing-invoicing/documents/${documentId}/receipt-allocations`).then((r) => r.data.data),
  consolidatedReceipts: (params?: Record<string, string | number | undefined>) => client.get<Paginated<ConsolidatedReceiptRecord>>('/billing-invoicing/consolidated-receipts', { params }).then((r) => r.data),
  createConsolidatedReceipt: (payload: { billing_document_id: number }) => client.post<{ data: ConsolidatedReceiptRecord }>('/billing-invoicing/consolidated-receipts', payload).then((r) => r.data.data),
  pdf: (path: string) => client.get<Blob>(path, { responseType: 'blob' }).then((r) => r.data),
  settings: () => client.get<{ data: BillingProfile }>('/billing-invoicing/settings').then((r) => r.data.data),
  updateSettings: (payload: Partial<BillingProfile>) => client.put<{ data: BillingProfile }>('/billing-invoicing/settings', payload).then((r) => r.data.data),
  sequences: () => client.get<{ data: BillingSequence[] }>('/billing-invoicing/sequences').then((r) => r.data.data),
  updateSequence: (type: BillingSequence['document_type'], payload: Omit<BillingSequence, 'id' | 'document_type' | 'preview'>) => client.put<{ data: BillingSequence }>(`/billing-invoicing/sequences/${type}`, payload).then((r) => r.data.data),
  permissions: () => client.get<{ data: { matrix: BillingProfile['permissions']; effective: Record<BillingPermission, boolean> } }>('/billing-invoicing/permissions').then((r) => r.data.data),
  updatePermissions: (permissions: Pick<BillingProfile['permissions'], 'admin' | 'employee'>) => client.put('/billing-invoicing/permissions', { permissions }).then((r) => r.data.data),
  addBank: (payload: Omit<BillingBankAccount, 'id'>) => client.post('/billing-invoicing/bank-accounts', payload).then((r) => r.data.data),
  updateBank: (id: number, payload: Omit<BillingBankAccount, 'id'>) => client.put(`/billing-invoicing/bank-accounts/${id}`, payload).then((r) => r.data.data),
  deleteBank: (id: number) => client.delete(`/billing-invoicing/bank-accounts/${id}`),
  uploadAsset: (type: 'logo' | 'signature', asset: File) => {
    const form = new FormData();
    form.append('asset', asset);
    return client.post<{ data: BillingProfile }>(`/billing-invoicing/settings/assets/${type}`, form, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data.data);
  },
  templatePreview: (template: string) => client.get<Blob>(`/billing-invoicing/templates/${template}/preview`, { responseType: 'blob' }).then((r) => r.data),
};
