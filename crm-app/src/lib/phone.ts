/**
 * Normalize a phone number for duplicate checks and WhatsApp.
 * Strips non-digits, removes +91/leading 0, and stores as 91 + local digits.
 */
export function normalizePhoneNumber(phone: string): string {
  let digits = phone.replace(/\D/g, '').replace(/^0+/, '');
  if (!digits) return '';

  if (digits.startsWith('91')) {
    digits = digits.slice(2).replace(/^0+/, '');
  }

  if (digits.length > 10) {
    digits = digits.slice(-10);
  }

  return digits ? '91' + digits : '';
}

export function toWhatsAppNumber(phone: string): string {
  return normalizePhoneNumber(phone);
}
