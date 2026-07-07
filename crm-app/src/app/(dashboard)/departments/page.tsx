'use client';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { departmentsApi, type DepartmentPayload } from '@/lib/api/departments';
import { employeesApi } from '@/lib/api/employees';
import { useAuthStore } from '@/store/authStore';
import { AccessDenied } from '@/components/ui/AccessDenied';
import type { Department } from '@/types';

export default function DepartmentsPage() {
  const qc = useQueryClient();
  const { canManageTeam, isAdmin } = useAuthStore();
  const manageTeam = canManageTeam();
  const [modal, setModal] = useState<Department | null | undefined>(undefined);
  const [form, setForm] = useState<DepartmentPayload>({ name: '', description: '', member_ids: [] });

  const { data: departments, isLoading } = useQuery({
    queryKey: ['departments'],
    queryFn: departmentsApi.list,
    enabled: manageTeam,
  });

  const { data: employees } = useQuery({
    queryKey: ['employees'],
    queryFn: () => employeesApi.list(),
    enabled: manageTeam,
  });

  const createMutation = useMutation({
    mutationFn: (p: DepartmentPayload) => departmentsApi.create(p),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['departments'] }); setModal(undefined); },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, p }: { id: number; p: DepartmentPayload }) => departmentsApi.update(id, p),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['departments'] }); setModal(undefined); },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => departmentsApi.delete(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['departments'] }),
  });

  const openCreate = () => {
    setForm({ name: '', description: '', member_ids: [] });
    setModal(null);
  };

  const openEdit = (dept: Department) => {
    setForm({
      name: dept.name,
      description: dept.description ?? '',
      member_ids: dept.members?.map((m) => m.id) ?? [],
    });
    setModal(dept);
  };

  if (!manageTeam) {
    return <AccessDenied reason="Department management is only available to owners and admins on the Business plan." />;
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold text-gray-900">Departments</h1>
        {isAdmin() && (
          <button onClick={openCreate}
            className="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2 rounded-xl">
            + Add Department
          </button>
        )}
      </div>

      {isLoading ? (
        <div className="flex items-center justify-center h-48">
          <div className="w-6 h-6 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
        </div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {departments?.length ? departments.map((dept) => (
            <div key={dept.id} className="bg-white rounded-2xl border border-gray-200 p-5">
              <div className="flex items-start justify-between mb-3">
                <div>
                  <h3 className="font-semibold text-gray-900">{dept.name}</h3>
                  {dept.description && <p className="text-xs text-gray-400 mt-0.5">{dept.description}</p>}
                </div>
                {isAdmin() && (
                  <div className="flex gap-2">
                    <button onClick={() => openEdit(dept)} className="text-xs text-indigo-600 hover:text-indigo-700">Edit</button>
                    <button onClick={() => { if (confirm('Delete?')) deleteMutation.mutate(dept.id); }}
                      className="text-xs text-red-500 hover:text-red-700">Delete</button>
                  </div>
                )}
              </div>
              <div className="flex items-center gap-2 flex-wrap mt-3">
                {dept.members?.slice(0, 5).map((m) => (
                  <div key={m.id} title={m.name}
                    className="w-7 h-7 bg-indigo-100 rounded-full flex items-center justify-center text-xs text-indigo-700 font-semibold">
                    {m.name[0]?.toUpperCase()}
                  </div>
                ))}
                {(dept.members?.length ?? 0) > 5 && (
                  <span className="text-xs text-gray-400">+{(dept.members?.length ?? 0) - 5} more</span>
                )}
                {!dept.members?.length && <span className="text-xs text-gray-400">No members</span>}
              </div>
            </div>
          )) : (
            <div className="col-span-3 bg-white rounded-2xl border border-gray-200 p-10 text-center text-gray-400 text-sm">
              No departments yet.{' '}
              <button onClick={openCreate} className="text-indigo-600 hover:underline">Create one</button>
            </div>
          )}
        </div>
      )}

      {/* Modal */}
      {modal !== undefined && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
            <h2 className="text-lg font-bold text-gray-900 mb-5">{modal ? 'Edit Department' : 'Create Department'}</h2>
            <div className="space-y-4">
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Name *</label>
                <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })}
                  className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Description</label>
                <input value={form.description ?? ''} onChange={(e) => setForm({ ...form, description: e.target.value })}
                  className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" />
              </div>
              {employees?.length ? (
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-2">Members</label>
                  <div className="space-y-2 max-h-48 overflow-y-auto border border-gray-200 rounded-xl p-3">
                    {employees.map((emp) => (
                      <label key={emp.id} className="flex items-center gap-2 cursor-pointer">
                        <input
                          type="checkbox"
                          checked={form.member_ids?.includes(emp.id) ?? false}
                          onChange={(e) => {
                            const ids = form.member_ids ?? [];
                            setForm({
                              ...form,
                              member_ids: e.target.checked
                                ? [...ids, emp.id]
                                : ids.filter((id) => id !== emp.id),
                            });
                          }}
                          className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                        />
                        <span className="text-sm text-gray-700">{emp.name}</span>
                        <span className="text-xs text-gray-400">{emp.email}</span>
                      </label>
                    ))}
                  </div>
                </div>
              ) : null}
            </div>
            <div className="flex gap-3 mt-6">
              <button
                onClick={() => modal?.id ? updateMutation.mutate({ id: modal.id, p: form }) : createMutation.mutate(form)}
                className="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2.5 rounded-xl text-sm">
                {modal ? 'Save' : 'Create'}
              </button>
              <button onClick={() => setModal(undefined)}
                className="px-4 text-sm text-gray-500 hover:text-gray-700 border border-gray-200 rounded-xl">Cancel</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
