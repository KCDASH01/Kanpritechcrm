import BillingNav from './BillingNav';

export default function BillingPage({ title, description, action, children }: { title: string; description: string; action?: React.ReactNode; children: React.ReactNode }) {
  return <main className="min-w-0 p-4 sm:p-6 lg:p-8">
    <div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><h1 className="text-2xl font-bold text-gray-900">{title}</h1><p className="mt-1 text-sm text-gray-500">{description}</p></div>{action}</div>
    <BillingNav />{children}
  </main>;
}
