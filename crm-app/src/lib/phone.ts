/**
 * Normalize a phone number to WhatsApp-compatible format.
 * Strips non-digits and prepends Indian country code 91 if needed.
 */
export function toWhatsAppNumber(phone: string): string {
  const digits = phone.replace(/\D/g, '');
  if (digits.startsWith('91') && digits.length === 12) return digits;
  if (digits.length === 10) return '91' + digits;
  return digits;
}
