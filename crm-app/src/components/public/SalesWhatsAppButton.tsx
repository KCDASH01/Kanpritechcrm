type SalesWhatsAppButtonProps = {
  href?: string;
  label?: string;
  className?: string;
  floating?: boolean;
};

export const DEFAULT_SALES_WHATSAPP_URL =
  'https://wa.me/917751973970?text=Hello%20Growneq%20CRM%20Sales%2C%20I%20would%20like%20to%20know%20more.';

export default function SalesWhatsAppButton({
  href = DEFAULT_SALES_WHATSAPP_URL,
  label = 'WhatsApp sales',
  className = '',
  floating = false,
}: SalesWhatsAppButtonProps) {
  return (
    <a
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      aria-label={`${label} (opens WhatsApp)`}
      className={`${floating ? 'fixed bottom-5 right-5 z-40 shadow-xl sm:bottom-7 sm:right-7' : ''} inline-flex items-center justify-center gap-2 rounded-xl bg-[#25D366] px-5 py-3 text-sm font-bold text-white transition-all hover:-translate-y-0.5 hover:bg-[#20bd5a] hover:shadow-lg focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200 ${className}`}
    >
      <svg aria-hidden="true" viewBox="0 0 32 32" className="h-5 w-5 fill-current">
        <path d="M16.04 3C9.42 3 4.03 8.34 4.03 14.9c0 2.1.56 4.16 1.62 5.96L3.93 27l6.32-1.65a12.1 12.1 0 0 0 5.78 1.46h.01c6.62 0 12.01-5.34 12.01-11.9C28.05 8.34 22.67 3 16.04 3Zm7.07 16.82c-.3.85-1.79 1.63-2.49 1.72-.65.09-1.47.13-2.37-.15-.55-.17-1.25-.4-2.15-.79-3.78-1.62-6.24-5.39-6.43-5.64-.18-.25-1.54-2.03-1.54-3.88 0-1.85.97-2.76 1.31-3.14.34-.38.74-.47.99-.47.25 0 .5 0 .72.01.23.01.54-.09.84.63.31.74 1.05 2.56 1.14 2.75.09.19.15.41.03.66-.12.25-.18.41-.37.63-.18.22-.39.49-.55.66-.19.19-.38.39-.16.77.22.38.97 1.58 2.09 2.56 1.43 1.27 2.64 1.66 3.01 1.85.37.19.59.16.81-.09.22-.25.93-1.08 1.18-1.45.25-.38.5-.31.84-.19.34.13 2.15 1.01 2.52 1.19.37.19.62.28.71.44.09.15.09.88-.22 1.73Z" />
      </svg>
      {label}
    </a>
  );
}
