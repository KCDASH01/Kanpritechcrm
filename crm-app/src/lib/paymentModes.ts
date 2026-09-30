export const PAYMENT_MODES = [
  { value: 'upi',           label: 'UPI' },
  { value: 'bank_transfer', label: 'Bank Transfer' },
  { value: 'cash',          label: 'Cash' },
  { value: 'cheque',        label: 'Cheque' },
  { value: 'card',          label: 'Card' },
  { value: 'aggregator',    label: 'Aggregator' },
  { value: 'other',         label: 'Other' },
] as const;

export type PaymentMode = (typeof PAYMENT_MODES)[number]['value'];

export function paymentModeLabel(mode?: string | null): string {
  if (!mode) return '—';
  return PAYMENT_MODES.find((m) => m.value === mode)?.label ?? mode.replace(/_/g, ' ');
}
