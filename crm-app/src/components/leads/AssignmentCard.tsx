'use client';

import { useQuery } from '@tanstack/react-query';
import { employeesApi } from '@/lib/api/employees';
import { useAuthStore } from '@/store/authStore';
import type { User } from '@/types';

interface Props {
  assignedTo: User | undefined;
  createdBy:  User | undefined;
  createdAt:  string;
  onAssign:   (userId: number | null, name?: string) => void;
  saving:     boolean;
  canReassign?: boolean;
}

function Avatar({ user, size = 'sm' }: { user: User; size?: 'sm' | 'md' }) {
  const cls = size === 'md' ? 'w-8 h-8 text-sm' : 'w-6 h-6 text-xs';
  if (user.avatar) {
    return <img src={user.avatar} alt={user.name} className={`${cls} rounded-full object-cover`} />;
  }
  const initials = user.name.split(' ').map((n) => n[0]).slice(0, 2).join('').toUpperCase();
  return (
    <div className={`${cls} rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 flex items-center justify-center text-white font-semibold shrink-0`}>
      {initials}
    </div>
  );
}

export function AssignmentCard({ assignedTo, createdBy, createdAt, onAssign, saving, canReassign = true }: Props) {
  const currentUser = useAuthStore((s) => s.user);
  const isBusinessPlan = useAuthStore((s) => s.isBusinessPlan)();

  const { data: employees } = useQuery({
    queryKey: ['employees'],
    queryFn:  () => employeesApi.list(),
    enabled:  isBusinessPlan,
    retry:    false,
    staleTime: 5 * 60_000,
  });

  // Build full user list: current user first (API excludes self), then rest
  const allUsers: User[] = [];
  if (currentUser) allUsers.push(currentUser);
  if (employees)   allUsers.push(...employees.filter((e) => e.id !== currentUser?.id));

  const createdDate = new Date(createdAt).toLocaleDateString('en-IN', {
    day: 'numeric', month: 'short', year: 'numeric',
  });

  return (
    <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 space-y-4">
      <h3 className="text-sm font-semibold text-gray-900">Assignment</h3>

      {/* Assigned to */}
      <div>
        <p className="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-2">Assigned To</p>
        {canReassign && isBusinessPlan && allUsers.length > 0 ? (
          <select
            value={assignedTo?.id ?? ''}
            onChange={(e) => {
              const id = e.target.value ? Number(e.target.value) : null;
              const user = allUsers.find((u) => u.id === id);
              onAssign(id, user?.name);
            }}
            disabled={saving}
            className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white"
          >
            <option value="">— Unassigned —</option>
            {allUsers.map((u) => (
              <option key={u.id} value={u.id}>
                {u.name}{u.id === currentUser?.id ? ' (You)' : ''}
              </option>
            ))}
          </select>
        ) : (
          <div className="flex items-center gap-2">
            {assignedTo ? (
              <>
                <Avatar user={assignedTo} size="md" />
                <div>
                  <p className="text-sm font-medium text-gray-800">{assignedTo.name}</p>
                  <p className="text-xs text-gray-400 capitalize">{assignedTo.role}</p>
                </div>
              </>
            ) : (
              <span className="text-sm text-gray-400 italic">Unassigned</span>
            )}
          </div>
        )}
      </div>

      {/* Created by */}
      {createdBy && (
        <div>
          <p className="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-2">Created By</p>
          <div className="flex items-center gap-2">
            <Avatar user={createdBy} size="md" />
            <div>
              <p className="text-sm font-medium text-gray-800">{createdBy.name}</p>
              <p className="text-xs text-gray-400">{createdDate}</p>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
