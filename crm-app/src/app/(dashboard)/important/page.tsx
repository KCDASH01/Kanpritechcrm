'use client';

import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import { motion, AnimatePresence } from 'framer-motion';
import { importantApi, type ImportantLead, type ImportantStatus } from '@/lib/api/important';
import { activitiesApi } from '@/lib/api/activities';
import { employeesApi } from '@/lib/api/employees';
import { useAuthStore } from '@/store/authStore';
import { LEAD_TYPES, LEAD_TYPE_LABELS } from '@/lib/leadTypes';
import { SkeletonTable } from '@/components/ui/Skeleton';
import type { PaginatedResponse } from '@/types';

const AVATAR_GRADIENTS = [
  'from-indigo-400 to-violet-500', 'from-emerald-400 to-teal-500',
  'from-blue-400 to-sky-500',      'from-pink-400 to-rose-500',
  'from-amber-400 to-orange-500',  'from-purple-400 to-fuchsia-500',
];

const STATUS_BADGE: Record<ImportantStatus, string> = {
  pending: 'bg-amber-100 text-amber-700',
  done:    'bg-emerald-100 text-emerald-700',
};

function formatMarked(iso?: string) {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('en-IN', {
    day: 'numeric', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
}

function EmptyImportant({ hasFilters, onClear }: { hasFilters: boolean; onClear: () => void }) {
  return (
    <div className="flex flex-col items-center justify-center py-16 px-4">
      <div className="w-16 h-16 bg-amber-50 rounded-2xl flex items-center justify-center mb-4">
        <svg className="w-8 h-8 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
            d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
        </svg>
      </div>
      <p className="text-gray-800 font-semibold text-base mb-1">
        {hasFilters ? 'No important leads match your filters' : 'No important leads'}
      </p>
      <p className="text-gray-400 text-sm mb-4 text-center max-w-sm">
        {hasFilters
          ? 'Try adjusting your search or filters to find what you need.'
          : 'Leads marked as Important will appear here. Change a lead status to Important to flag one.'}
      </p>
      {hasFilters && (
        <button
          onClick={onClear}
          className="text-sm font-semibold text-indigo-600 hover:text-indigo-700 hover:underline"
        >
          Clear all filters
        </button>
      )}
    </div>
  );
}

export default function ImportantPage() {
  const router       = useRouter();
  const searchParams = useSearchParams();
  const qc           = useQueryClient();
  const { isOwner, isAdmin } = useAuthStore();
  const isManager    = isOwner() || isAdmin();

  const search           = searchParams.get('search') ?? '';
  const statusFilter     = (searchParams.get('status') ?? '') as ImportantStatus | '';
  const typeFilter       = searchParams.get('types') ?? '';
  const dateFromFilter   = searchParams.get('date_from') ?? '';
  const dateToFilter     = searchParams.get('date_to') ?? '';
  const teamMemberFilter = searchParams.get('member') ?? '';
  const page             = Math.max(1, parseInt(searchParams.get('page') ?? '1', 10) || 1);

  const updateParams = (patch: Record<string, string | null | undefined>) => {
    const params = new URLSearchParams(searchParams.toString());
    for (const [key, val] of Object.entries(patch)) {
      if (val === null || val === undefined || val === '') params.delete(key);
      else params.set(key, val);
    }
    const qs = params.toString();
    router.replace(qs ? `/important?${qs}` : '/important', { scroll: false });
  };

  const unassignedFilter = isManager && teamMemberFilter === 'unassigned';
  const assignedToFilter = isManager && teamMemberFilter && teamMemberFilter !== 'unassigned'
    ? Number(teamMemberFilter)
    : undefined;

  const listFilters = {
    search:      search || undefined,
    status:      statusFilter || undefined,
    types:       typeFilter || undefined,
    date_from:   dateFromFilter || undefined,
    date_to:     dateToFilter || undefined,
    assigned_to: assignedToFilter,
    unassigned:  unassignedFilter || undefined,
    page,
  };

  const hasActiveFilters = !!(
    search || statusFilter || typeFilter || teamMemberFilter || dateFromFilter || dateToFilter
  );

  const clearAllFilters = () =>
    updateParams({ search: null, status: null, types: null, member: null, date_from: null, date_to: null, page: null });

  const { data: teamMembers } = useQuery({
    queryKey: ['employees', { role: 'employee' }],
    queryFn:  () => employeesApi.list({ role: 'employee' }),
    enabled:  isManager,
    staleTime: 5 * 60_000,
    retry:    false,
  });

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['important', listFilters],
    queryFn:  () => importantApi.list(listFilters),
    placeholderData: keepPreviousData,
  });

  const doneMutation = useMutation({
    mutationFn: (id: number) => activitiesApi.markDone(id),
    onMutate: async (id) => {
      await qc.cancelQueries({ queryKey: ['important'] });
      const prev = qc.getQueryData<PaginatedResponse<ImportantLead>>(['important', listFilters]);
      qc.setQueryData<PaginatedResponse<ImportantLead>>(
        ['important', listFilters],
        (old) => old
          ? { ...old, data: old.data.map((item) => item.activity_id === id ? { ...item, is_done: true, status: 'done' as const } : item) }
          : old,
      );
      return { prev };
    },
    onError: (_err, _id, ctx) => {
      if (ctx?.prev) qc.setQueryData(['important', listFilters], ctx.prev);
    },
    onSettled: () => {
      qc.invalidateQueries({ queryKey: ['important'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      qc.invalidateQueries({ queryKey: ['activities'] });
    },
  });

  const items = data?.data ?? [];
  const meta  = data?.meta;

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-xl font-bold text-gray-900">
          Important{meta ? ` (${meta.total})` : ''}
        </h1>
        <p className="text-xs text-gray-400 mt-0.5">
          {isManager ? 'Leads with Important status' : 'Your leads marked as important'}
        </p>
      </div>

      <div className="flex gap-3 flex-wrap items-center">
        <div className="relative">
          <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
          </svg>
          <input
            type="search"
            placeholder="Search name, company, phone, email…"
            value={search}
            onChange={(e) => updateParams({ search: e.target.value || null, page: null })}
            className="border border-gray-200 rounded-xl pl-9 pr-4 py-2.5 text-sm w-72 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-shadow bg-white"
          />
        </div>

        <select
          value={statusFilter}
          onChange={(e) => updateParams({ status: e.target.value || null, page: null })}
          className="border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-gray-600"
        >
          <option value="">All statuses</option>
          <option value="pending">Pending</option>
          <option value="done">Done</option>
        </select>

        <select
          value={typeFilter}
          onChange={(e) => updateParams({ types: e.target.value || null, page: null })}
          className="border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-gray-600"
        >
          <option value="">All types</option>
          {LEAD_TYPES.map((t) => (
            <option key={t} value={t}>{LEAD_TYPE_LABELS[t]}</option>
          ))}
        </select>

        {isManager && (
          <select
            value={teamMemberFilter}
            onChange={(e) => updateParams({ member: e.target.value || null, page: null })}
            className="border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-gray-600"
          >
            <option value="">All team members</option>
            <option value="unassigned">Unassigned</option>
            {(teamMembers ?? []).map((emp) => (
              <option key={emp.id} value={String(emp.id)}>{emp.name}</option>
            ))}
          </select>
        )}

        <div className="flex items-center gap-3 shrink-0">
          <input
            type="date"
            value={dateFromFilter}
            onChange={(e) => updateParams({ date_from: e.target.value || null, page: null })}
            className="border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-gray-600"
            title="From date"
          />
          <input
            type="date"
            value={dateToFilter}
            onChange={(e) => updateParams({ date_to: e.target.value || null, page: null })}
            className="border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-gray-600"
            title="To date"
          />
          {hasActiveFilters && (
            <button
              type="button"
              onClick={clearAllFilters}
              className="flex items-center gap-1.5 px-3 py-2.5 text-sm font-medium text-gray-600
                         border border-gray-200 rounded-xl bg-white hover:bg-gray-50 hover:text-gray-900
                         transition-colors whitespace-nowrap"
            >
              <svg className="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12"/>
              </svg>
              Clear filters
            </button>
          )}
        </div>

        {isFetching && !isLoading && (
          <span className="text-xs text-gray-400 flex items-center gap-1.5">
            <svg className="w-3.5 h-3.5 animate-spin text-indigo-400" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            Updating…
          </span>
        )}
      </div>

      <div className="bg-white rounded-2xl border border-gray-100 shadow-sm">
        {isLoading ? (
          <SkeletonTable rows={6} cols={8} />
        ) : items.length === 0 ? (
          <EmptyImportant hasFilters={hasActiveFilters} onClear={clearAllFilters} />
        ) : (
          <div className="overflow-x-auto rounded-2xl">
          <table className="min-w-[900px] w-full">
              <thead>
                <tr className="bg-gray-50/80 border-b border-gray-100">
                  {['Lead Name', 'Requirement', 'Company', 'Contact', 'Assigned Member', 'Remark', 'Marked On', 'Status', 'Actions'].map((h, hi, arr) => (
                    <th
                      key={h}
                      className={`px-5 py-3.5 text-left text-[11px] font-semibold text-gray-500 uppercase tracking-wider
                        ${hi === 0 ? 'rounded-tl-2xl' : ''} ${hi === arr.length - 1 ? 'rounded-tr-2xl' : ''}`}
                    >
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                <AnimatePresence>
                  {items.map((item, i) => (
                    <motion.tr
                      key={item.lead_id ?? item.id}
                      initial={{ opacity: 0, y: 4 }}
                      animate={{ opacity: 1, y: 0 }}
                      exit={{ opacity: 0, x: -20 }}
                      transition={{ delay: i * 0.03, duration: 0.2 }}
                      className={`hover:bg-indigo-50/20 transition-colors ${item.is_done ? 'opacity-60' : ''}`}
                    >
                      <td className="px-5 py-3.5">
                        {item.lead_id ? (
                          <Link href={`/leads/${item.lead_id}`} className="flex items-center gap-3 group/name">
                            <div className={`w-8 h-8 rounded-full bg-gradient-to-br ${AVATAR_GRADIENTS[i % AVATAR_GRADIENTS.length]}
                                            flex items-center justify-center shrink-0 shadow-sm`}>
                              <span className="text-white font-bold text-xs">{item.lead_name[0]?.toUpperCase()}</span>
                            </div>
                            <div>
                              <p className="text-sm font-semibold text-gray-900 group-hover/name:text-indigo-600 transition-colors">
                                {item.lead_name}
                              </p>
                              <p className="text-xs text-gray-400 truncate max-w-[180px]">{item.title}</p>
                            </div>
                          </Link>
                        ) : (
                          <span className="text-sm text-gray-600">{item.lead_name}</span>
                        )}
                      </td>
                      <td className="px-5 py-3.5 text-sm text-gray-600">
                        {item.types && item.types in LEAD_TYPE_LABELS
                          ? LEAD_TYPE_LABELS[item.types as keyof typeof LEAD_TYPE_LABELS]
                          : '—'}
                      </td>
                      <td className="px-5 py-3.5 text-sm text-gray-600">{item.company ?? '—'}</td>
                      <td className="px-5 py-3.5">
                        <p className="text-sm font-semibold text-gray-900">{item.phone ?? '—'}</p>
                      </td>
                      <td className="px-5 py-3.5 text-sm text-gray-600">{item.assigned_to?.name ?? '—'}</td>
                      <td className="px-5 py-3.5">
                        <p className="text-sm text-gray-600 max-w-[200px] truncate" title={item.remark ?? undefined}>
                          {item.remark ?? '—'}
                        </p>
                      </td>
                      <td className="px-5 py-3.5 text-sm text-gray-600 whitespace-nowrap">
                        {formatMarked(item.marked_at)}
                      </td>
                      <td className="px-5 py-3.5">
                        <span className={`inline-flex px-2.5 py-1 rounded-lg text-xs font-semibold capitalize ${STATUS_BADGE[item.status]}`}>
                          {item.status}
                        </span>
                      </td>
                      <td className="px-5 py-3.5">
                        <div className="flex items-center gap-2">
                          {!item.is_done && item.activity_id > 0 && (
                            <button
                              onClick={() => doneMutation.mutate(item.activity_id)}
                              disabled={doneMutation.isPending}
                              className="text-xs font-semibold px-3 py-1.5 rounded-lg bg-emerald-50 text-emerald-700
                                         hover:bg-emerald-100 transition-colors disabled:opacity-50"
                            >
                              Mark done
                            </button>
                          )}
                          {item.lead_id && (
                            <Link
                              href={`/leads/${item.lead_id}`}
                              className="text-xs font-semibold px-3 py-1.5 rounded-lg bg-gray-50 text-gray-600
                                         hover:bg-gray-100 transition-colors"
                            >
                              View lead
                            </Link>
                          )}
                        </div>
                      </td>
                    </motion.tr>
                  ))}
                </AnimatePresence>
              </tbody>
            </table>
          </div>
        )}
      </div>

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-gray-500">
          <span>Page {meta.current_page} of {meta.last_page}</span>
          <div className="flex gap-2">
            <button
              onClick={() => updateParams({ page: String(Math.max(1, page - 1)) })}
              disabled={page === 1}
              className="px-3 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-50 disabled:opacity-40 transition-colors"
            >
              Previous
            </button>
            <button
              onClick={() => updateParams({ page: String(Math.min(meta.last_page, page + 1)) })}
              disabled={page === meta.last_page}
              className="px-3 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-50 disabled:opacity-40 transition-colors"
            >
              Next
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
