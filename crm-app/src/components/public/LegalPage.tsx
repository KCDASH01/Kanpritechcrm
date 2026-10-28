import PublicFooter from './PublicFooter';
import PublicHeader from './PublicHeader';

export default function LegalPage({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="min-h-screen bg-gray-50 text-gray-800">
      <PublicHeader />
      <main className="px-4 py-10 sm:py-14">
        <article className="mx-auto max-w-4xl rounded-2xl border border-gray-200 bg-white px-5 py-8 shadow-sm sm:px-10 sm:py-12">
          <header className="border-b border-gray-100 pb-7">
            <p className="text-sm font-semibold uppercase tracking-wider text-indigo-600">Growneq CRM</p>
            <h1 className="mt-2 text-3xl font-black tracking-tight text-gray-900 sm:text-4xl">{title}</h1>
            <dl className="mt-5 grid gap-2 text-sm text-gray-600 sm:grid-cols-2">
              <div><dt className="inline font-semibold text-gray-800">Last Updated: </dt><dd className="inline">October 28, 2026</dd></div>
              <div><dt className="inline font-semibold text-gray-800">Operated by: </dt><dd className="inline">Kanpri Technologies LLP</dd></div>
            </dl>
          </header>
          <div className="legal-content mt-8 space-y-8 text-[15px] leading-7 text-gray-700">{children}</div>
        </article>
      </main>
      <PublicFooter />
    </div>
  );
}

export function LegalSection({ title, children }: { title: string; children: React.ReactNode }) {
  return <section><h2 className="mb-3 text-xl font-bold text-gray-900">{title}</h2><div className="space-y-4">{children}</div></section>;
}

export function LegalList({ children, ordered = false }: { children: React.ReactNode; ordered?: boolean }) {
  const Tag = ordered ? 'ol' : 'ul';
  return <Tag className={`${ordered ? 'list-decimal' : 'list-disc'} space-y-1.5 pl-6 marker:text-indigo-500`}>{children}</Tag>;
}

export function LegalContact({ label, urlLabel }: { label: string; urlLabel: string }) {
  return <address className="not-italic"><strong>Kanpri Technologies LLP</strong><br />Growneq CRM – {label}<br /><br />Plot No. 506, Saheed Nagar<br />Bhubaneswar, Odisha – 751007<br />India<br /><br /><strong>Email:</strong> <a href="mailto:contact@kanpritech.com" className="text-indigo-600 hover:underline">contact@kanpritech.com</a><br /><strong>Phone:</strong> <a href="tel:+917751973970" className="text-indigo-600 hover:underline">+91 77519 73970</a><br /><strong>Website:</strong> <a href="https://growneq.com" className="text-indigo-600 hover:underline">https://growneq.com</a><br /><br /><strong>{urlLabel}</strong></address>;
}
