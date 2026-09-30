export type DealCurrency = 'INR' | 'USD';

export function normalizeDealCurrency(currency?: string | null): DealCurrency {
  return currency === 'USD' ? 'USD' : 'INR';
}

export function currencySymbol(currency?: string | null): '₹' | '$' {
  return normalizeDealCurrency(currency) === 'USD' ? '$' : '₹';
}

export function currencyLabel(currency?: string | null): 'INR' | 'Dollar' {
  return normalizeDealCurrency(currency) === 'USD' ? 'Dollar' : 'INR';
}

export function formatDealMoney(
  amount: number | string | null | undefined,
  currency?: string | null,
  opts?: { digits?: number },
): string {
  if (amount == null || amount === '') return '—';
  const n = Number(amount);
  if (Number.isNaN(n)) return '—';
  const isUsd = normalizeDealCurrency(currency) === 'USD';
  const digits = opts?.digits ?? 0;
  return `${isUsd ? '$' : '₹'}${n.toLocaleString(isUsd ? 'en-US' : 'en-IN', {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  })}`;
}
