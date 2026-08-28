'use client';

import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import { motion, AnimatePresence } from 'framer-motion';
import { followUpsApi, type FollowUp, type FollowUpDueFilter, type FollowUpStatus } from '@/lib/api/followUps';
import { activitiesApi } from '@/lib/api/activities';
import { employeesApi } from '@/lib/api/employees';
import { useAuthStore } from '@/store/authStore';
import { SkeletonTable } from '@/components/ui/Skeleton';
import type { PaginatedResponse } from '@/types';

const AVATAR_GRADIENTS = [
  'from-indigo-400 to-violet-500', 'from-emerald-400 to-teal-500',
  'from-blue-400 to-sky-500',      'from-pink-400 to-rose-500',
  'from-amber-400 to-orange-500',  'from-purple-400 to-fuchsia-500',
];

const STATUS_BADGE: Record<FollowUpStatus, string> = {
  pending: 'bg-blue-100 text-blue-700',
  overdue: 'bg-red-100 text-red-700',
  done:    'bg-emerald-100 text-emerald-700',
};

function formatDue(iso?: string) {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('en-IN', {
    day: 'numeric', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
}

function EmptyFollowUps({ hasFilters, onClear }: { hasFilters: boolean; onClear: () => void }) {
  return (
    <div className="flex flex-col items-center justify-center py-16 px-4">
      <div className="w-16 h-16 bg-fuchsia-50 rounded-2xl flex items-center justify-center mb-4">
        <svg className="w-8 h-8 text-fuchsia-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
      </div>
      <p className="text-gray-800 font-semibold text-base mb-1">
        {hasFilters ? 'No follow-ups match your filters' : 'No follow-ups scheduled'}
      </p>
      <p className="text-gray-400 text-sm mb-4 text-center max-w-sm">
        {hasFilters
          ? 'Try adjusting your search or filters to find what you need.'
          : 'Leads marked as Follow-up will appear here. Change a lead status to Follow-up to schedule one.'}
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

export default function FollowUpsPage() {
  const router       = useRouter();
  const searchParams = useSearchParams();
  const qc           = useQueryClient();
  const { isOwner, isAdmin } = useAuthStore();
  const isManager    = isOwner() || isAdmin();

  const search           = searchParams.get('search') ?? '';
  const statusFilter     = (searchParams.get('status') ?? '') as FollowUpStatus | '';
  const dueFilter        = (searchParams.get('due') ?? 'all') as FollowUpDueFilter;
  const teamMemberFilter = searchParams.get('member') ?? '';
  const page             = Math.max(1, parseInt(searchParams.get('page') ?? '1', 10) || 1);

  const updateParams = (patch: Record<string, string | null | undefined>) => {
    const params = new URLSearchParams(searchParams.toString());
    for (const [key, val] of Object.entries(patch)) {
      if (val === null || val === undefined || val === '') params.delete(key);
      else params.set(key, val);
    }
    const qs = params.toString();
    router.replace(qs ? `/follow-ups?${qs}` : '/follow-ups', { scroll: false });
  };

  const unassignedFilter = isManager && teamMemberFilter === 'unassigned';
  const assignedToFilter = isManager && teamMemberFilter && teamMemberFilter !== 'unassigned'
    ? Number(teamMemberFilter)
    : undefined;

  const listFilters = {
    search:      search || undefined,
    status:      statusFilter || undefined,
    due:         dueFilter === 'all' ? undefined : dueFilter,
    assigned_to: assignedToFilter,
    unassigned:  unassignedFilter || undefined,
    page,
  };

  const hasActiveFilters = !!(search || statusFilter || (dueFilter && dueFilter !== 'all') || teamMemberFilter);

  const clearAllFilters = () =>
    updateParams({ search: null, status: null, due: null, member: null, page: null });

  const { data: teamMembers } = useQuery({
    queryKey: ['employees', { role: 'employee' }],
    queryFn:  () => employeesApi.list({ role: 'employee' }),
    enabled:  isManager,
    staleTime: 5 * 60_000,
    retry:    false,
  });

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['follow-ups', listFilters],
    queryFn:  () => followUpsApi.list(listFilters),
    placeholderData: keepPreviousData,
  });

  const doneMutation = useMutation({
    mutationFn: (id: number) => activitiesApi.markDone(id),
    onMutate: async (id) => {
      await qc.cancelQueries({ queryKey: ['follow-ups'] });
      const prev = qc.getQueryData<PaginatedResponse<FollowUp>>(['follow-ups', listFilters]);
      qc.setQueryData<PaginatedResponse<FollowUp>>(
        ['follow-ups', listFilters],
        (old) => old
          ? { ...old, data: old.data.map((f) => f.activity_id === id ? { ...f, is_done: true, status: 'done' as const } : f) }
          : old,
      );
      return { prev };
    },
    onError: (_err, _id, ctx) => {
      if (ctx?.prev) qc.setQueryData(['follow-ups', listFilters], ctx.prev);
    },
    onSettled: () => {
      qc.invalidateQueries({ queryKey: ['follow-ups'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      qc.invalidateQueries({ queryKey: ['activities'] });
    },
  });

  const items = data?.data ?? [];
  const meta  = data?.meta;

  const dueLabel = dueFilter === 'today' ? 'Due today' : dueFilter === 'overdue' ? 'Overdue' : 'All follow-ups';

  return (
    <div className="space-y-5">
      {/* Header */}
      <div>
        <h1 className="text-xl font-bold text-gray-900">
          FollowUp{meta ? ` (${meta.total})` : ''}
        </h1>
        <p className="text-xs text-gray-400 mt-0.5">
          {isManager ? 'Leads with Follow-up status' : 'Your leads marked for follow-up'}
          {dueFilter !== 'all' && (
            <span className="ml-2 px-1.5 py-0.5 bg-fuchsia-100 text-fuchsia-700 text-[10px] font-semibold rounded-md uppercase tracking-wide">
              {dueLabel}
            </span>
          )}
        </p>
      </div>

      {/* Filters */}
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
          <option value="overdue">Overdue</option>
          <option value="done">Done</option>
        </select>

        <select
          value={dueFilter}
          onChange={(e) => updateParams({ due: e.target.value || 'all', page: null })}
          className="border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-gray-600"
        >
          <option value="all">All due dates</option>
          <option value="today">Today</option>
          <option value="overdue">Overdue</option>
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

        {hasActiveFilters && (
          <button
            type="button"
            onClick={clearAllFilters}
            className="flex items-center gap-1.5 px-3 py-2.5 text-sm font-medium text-gray-600
                       border border-gray-200 rounded-xl bg-white hover:bg-gray-50 hover:text-gray-900 transition-colors"
          >
            <svg className="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12"/>
            </svg>
            Clear filters
          </button>
        )}

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

      {/* Table */}
      <div className="bg-white rounded-2xl border border-gray-100 shadow-sm">
        {isLoading ? (
          <SkeletonTable rows={6} cols={6} />
        ) : items.length === 0 ? (
          <EmptyFollowUps hasFilters={hasActiveFilters} onClear={clearAllFilters} />
        ) : (
          <div className="overflow-x-auto rounded-2xl">
            <table className="min-w-full">
              <thead>
                <tr className="bg-gray-50/80 border-b border-gray-100">
                  {['Lead Name', 'Company', 'Assigned Member', 'Follow-up Date & Time', 'Status', 'Actions'].map((h, hi, arr) => (
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
                          <Link
                            href={`/leads/${item.lead_id}`}
                            className="flex items-center gap-3 group/name"
                          >
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
                      <td className="px-5 py-3.5 text-sm text-gray-600">{item.company ?? '—'}</td>
                      <td className="px-5 py-3.5 text-sm text-gray-600">
                        {item.assigned_to?.name ?? '—'}
                      </td>
                      <td className="px-5 py-3.5">
                        <p className={`text-sm font-medium ${item.status === 'overdue' ? 'text-red-600' : 'text-gray-700'}`}>
                          {item.status === 'overdue' && '⚠ '}
                          {formatDue(item.due_at)}
                        </p>
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

      {/* Pagination */}
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
