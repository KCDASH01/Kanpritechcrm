'use client';

import { useMemo, useState } from 'react';
import Link from 'next/link';
import { useQuery } from '@tanstack/react-query';
import { calendarApi, type CollectionAmount, type CollectionCalendarTransaction } from '@/lib/api/calendar';
import { employeesApi } from '@/lib/api/employees';

const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const WEEKDAYS = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

function dateKey(date: Date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function monthKey(date: Date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

function buildMonthGrid(year: number, month: number) {
  const first = new Date(year, month, 1);
  const offset = (first.getDay() + 6) % 7;
  const cells: Date[] = [];
  for (let index = 0; index < offset; index++) cells.push(new Date(year, month, 1 - offset + index));
  const count = new Date(year, month + 1, 0).getDate();
  for (let day = 1; day <= count; day++) cells.push(new Date(year, month, day));
  while (cells.length % 7) cells.push(new Date(year, month + 1, cells.length - offset - count + 1));
  return cells;
}

function formatAmount(item: CollectionAmount | Pick<CollectionCalendarTransaction, 'currency' | 'amount'>) {
  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: item.currency || 'INR',
    maximumFractionDigits: 2,
  }).format(item.amount);
}

function displayDate(value: string) {
  const [year, month, day] = value.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

export function CollectionCalendar({ canManage }: { canManage: boolean }) {
  const [currentDate, setCurrentDate] = useState(new Date());
  const [employeeId, setEmployeeId] = useState('');
  const [selectedDate, setSelectedDate] = useState<string | null>(null);
  const month = monthKey(currentDate);
  const collectionQuery = useQuery({
    queryKey: ['collection-calendar', month, employeeId],
    queryFn: () => calendarApi.collections({ month, assigned_to: employeeId ? Number(employeeId) : undefined }),
  });
  const employees = useQuery({ queryKey: ['employees', 'collection-calendar'], queryFn: () => employeesApi.list(), enabled: canManage, retry: false });
  const cells = useMemo(() => buildMonthGrid(currentDate.getFullYear(), currentDate.getMonth()), [currentDate]);
  const daily = useMemo(() => new Map((collectionQuery.data?.daily ?? []).map((item) => [item.date, item])), [collectionQuery.data]);
  const transactions = useMemo(() => (collectionQuery.data?.transactions ?? []).filter((item) => item.payment_date === selectedDate), [collectionQuery.data, selectedDate]);
  const selectedTotals = selectedDate ? daily.get(selectedDate)?.totals ?? [] : [];
  const today = dateKey(new Date());

  const navigate = (direction: -1 | 1) => {
    setCurrentDate((value) => new Date(value.getFullYear(), value.getMonth() + direction, 1));
    setSelectedDate(null);
  };

  return <div className="flex min-h-[560px] gap-4 lg:h-[calc(100dvh-9.5rem)]">
    <section className="flex min-w-0 flex-1 flex-col overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-3 py-3.5 sm:px-5">
        <div className="flex items-center gap-2">
          <button onClick={() => navigate(-1)} className="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100" aria-label="Previous month">‹</button>
          <h2 className="min-w-[150px] text-center text-sm font-bold text-gray-900">{MONTHS[currentDate.getMonth()]} {currentDate.getFullYear()}</h2>
          <button onClick={() => navigate(1)} className="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100" aria-label="Next month">›</button>
          <button onClick={() => { setCurrentDate(new Date()); setSelectedDate(null); }} className="rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-100">Today</button>
        </div>
        {canManage && <select value={employeeId} onChange={(event) => { setEmployeeId(event.target.value); setSelectedDate(null); }} className="max-w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-700">
          <option value="">All employees</option>{(employees.data ?? []).map((employee) => <option key={employee.id} value={employee.id}>{employee.name}</option>)}
        </select>}
      </div>

      <div className="grid grid-cols-1 gap-3 border-b border-gray-100 bg-gray-50/50 p-3 sm:grid-cols-3 sm:p-4">
        <div className="rounded-xl border border-emerald-100 bg-white p-3"><p className="text-[10px] font-semibold uppercase text-gray-400">Collected this month</p><div className="mt-1 flex flex-wrap gap-x-3">{collectionQuery.data?.summary.totals.length ? collectionQuery.data.summary.totals.map((total) => <span key={total.currency} className="text-lg font-bold text-emerald-700">{formatAmount(total)}</span>) : <span className="text-lg font-bold text-gray-500">₹0</span>}</div></div>
        <div className="rounded-xl border border-emerald-100 bg-white p-3"><p className="text-[10px] font-semibold uppercase text-gray-400">Collection days</p><p className="mt-1 text-lg font-bold text-gray-900">{collectionQuery.data?.summary.collection_days ?? 0}</p></div>
        <div className="rounded-xl border border-emerald-100 bg-white p-3"><p className="text-[10px] font-semibold uppercase text-gray-400">Received transactions</p><p className="mt-1 text-lg font-bold text-gray-900">{collectionQuery.data?.summary.transaction_count ?? 0}</p></div>
      </div>

      {collectionQuery.isLoading ? <div className="flex flex-1 items-center justify-center text-sm text-gray-400">Loading collections…</div> : <div className="flex flex-1 flex-col overflow-hidden">
        <div className="grid grid-cols-7 border-b border-gray-100">{WEEKDAYS.map((weekday) => <div key={weekday} className="py-2 text-center text-[10px] font-semibold uppercase tracking-wide text-gray-400">{weekday}</div>)}</div>
        <div className="grid flex-1 auto-rows-fr grid-cols-7 overflow-hidden">{cells.map((date) => {
          const key = dateKey(date);
          const day = daily.get(key);
          const hasCollections = !!day?.transaction_count;
          const isOtherMonth = date.getMonth() !== currentDate.getMonth();
          const isSelected = selectedDate === key;
          return <button key={key} type="button" disabled={!hasCollections} onClick={() => hasCollections && setSelectedDate(isSelected ? null : key)} className={`relative min-h-[72px] border-b border-r border-gray-100 p-1.5 text-left transition-colors sm:min-h-[88px] sm:p-2 ${isOtherMonth ? 'bg-gray-50/60 text-gray-300' : hasCollections ? 'bg-[#DCFCE7] hover:bg-[#CFF8DC]' : 'bg-white'} ${isSelected ? '!bg-[#BBF7D0] ring-2 ring-inset ring-emerald-500' : ''} ${hasCollections ? 'cursor-pointer' : 'cursor-default'}`}>
            <span className={`flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold ${key === today ? 'bg-indigo-600 text-white' : isOtherMonth ? 'text-gray-300' : 'text-gray-700'}`}>{date.getDate()}</span>
            {hasCollections && <div className="mt-1 space-y-0.5">{day.totals.map((total) => <p key={total.currency} className="truncate text-[9px] font-bold text-[#15803D] sm:text-[11px]">{formatAmount(total)}</p>)}<p className="text-[8px] font-medium text-emerald-700 sm:text-[9px]">{day.transaction_count} received</p></div>}
          </button>;
        })}</div>
      </div>}
    </section>

    {selectedDate && <aside className="fixed inset-x-3 bottom-3 z-40 flex max-h-[68dvh] flex-col overflow-hidden rounded-2xl border border-emerald-100 bg-white shadow-2xl lg:static lg:z-auto lg:max-h-none lg:w-80 lg:shrink-0 lg:shadow-sm">
      <div className="flex items-start justify-between border-b border-gray-100 px-4 py-3.5"><div><p className="text-xs font-semibold uppercase tracking-wide text-emerald-700">Collections received</p><p className="mt-0.5 text-sm font-bold text-gray-900">{displayDate(selectedDate)}</p><div className="mt-1 flex flex-wrap gap-2">{selectedTotals.map((total) => <span key={total.currency} className="text-xs font-bold text-emerald-700">{formatAmount(total)}</span>)}</div></div><button onClick={() => setSelectedDate(null)} className="flex h-7 w-7 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100">✕</button></div>
      <div className="flex-1 divide-y divide-gray-100 overflow-y-auto">{transactions.map((payment) => <div key={payment.id} className="p-4">
        <div className="flex items-start justify-between gap-3"><div className="min-w-0"><p className="truncate text-sm font-semibold text-gray-900">{payment.client_name || 'Unknown client'}</p><Link href={`/deals?search=${encodeURIComponent(payment.deal_reference ?? '')}`} className="mt-0.5 block truncate text-xs font-medium text-indigo-600 hover:underline">{payment.deal_reference || `Deal #${payment.deal_id}`}</Link></div><span className="shrink-0 text-sm font-bold text-emerald-700">{formatAmount(payment)}</span></div>
        <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-[10px]"><div><dt className="uppercase text-gray-400">Payment date</dt><dd className="mt-0.5 font-medium text-gray-700">{payment.payment_date}</dd></div><div><dt className="uppercase text-gray-400">Method</dt><dd className="mt-0.5 font-medium capitalize text-gray-700">{payment.payment_method.replaceAll('_', ' ')}</dd></div><div><dt className="uppercase text-gray-400">Employee</dt><dd className="mt-0.5 font-medium text-gray-700">{payment.responsible_employee || 'Unassigned'}</dd></div><div><dt className="uppercase text-gray-400">Status</dt><dd className="mt-0.5 font-semibold capitalize text-emerald-700">{payment.payment_status}</dd></div></dl>
      </div>)}</div>
    </aside>}
  </div>;
}
