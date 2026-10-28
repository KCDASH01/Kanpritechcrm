import Link from 'next/link';
import { BrandMark } from './PublicBrand';

export default function PublicFooter() {
  return (
    <footer className="border-t border-gray-100 bg-white py-10">
      <div className="mx-auto flex max-w-6xl flex-col items-center justify-between gap-5 px-4 lg:flex-row">
        <BrandMark compact />
        <p className="text-center text-xs text-gray-500">© 2026 Growneq CRM. Operated by Kanpri Technologies LLP.</p>
        <nav className="flex flex-wrap justify-center gap-x-5 gap-y-2 text-xs text-gray-500" aria-label="Legal navigation">
          <Link href="/privacy-policy" className="transition-colors hover:text-gray-900">Privacy</Link>
          <Link href="/terms-of-service" className="transition-colors hover:text-gray-900">Terms</Link>
          <Link href="/data-deletion" className="transition-colors hover:text-gray-900">Data Deletion</Link>
        </nav>
      </div>
    </footer>
  );
}
