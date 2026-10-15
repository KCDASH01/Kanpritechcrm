'use client';

import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { salesTargetsApi } from '@/lib/api/salesTargets';
import { employeesApi } from '@/lib/api/employees';
import type { LeaderboardSettings } from '@/types';

const defaults: LeaderboardSettings = { visibility: 'management', data_visibility: 'names_percentages', sales_weight: 60, collection_weight: 40, selected_user_ids: null };

export function LeaderboardSettingsCard() {
  const queryClient = useQueryClient();
  const settings = useQuery({ queryKey: ['leaderboard-settings'], queryFn: salesTargetsApi.leaderboardSettings });
  const members = useQuery({ queryKey: ['employees', 'leaderboard-settings'], queryFn: () => employeesApi.list() });
  const [form, setForm] = useState(defaults);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  useEffect(() => { if (settings.data) setForm(settings.data); }, [settings.data]);
  const mutation = useMutation({
    mutationFn: salesTargetsApi.updateLeaderboardSettings,
    onSuccess: (saved) => { setForm(saved); setError(''); setMessage('Leaderboard settings saved.'); queryClient.invalidateQueries({ queryKey: ['performance-leaderboard'] }); },
    onError: () => { setMessage(''); setError('Could not save the settings. Please check the weights and try again.'); },
  });
  const total = Number(form.sales_weight) + Number(form.collection_weight);
  const memberIds = (members.data ?? []).map((member) => member.id);
  const selectedIds = form.selected_user_ids ?? memberIds;
  const toggleMember = (memberId: number) => {
    const next = selectedIds.includes(memberId)
      ? selectedIds.filter((id) => id !== memberId)
      : [...selectedIds, memberId];
    setForm({ ...form, selected_user_ids: next });
  };

  return <div className="rounded-2xl border border-gray-200 bg-white p-6">
    <div className="mb-5"><h2 className="font-semibold text-gray-900">Performance Leaderboard</h2><p className="mt-0.5 text-xs text-gray-400">Control who can see rankings and exactly which data is shared</p></div>
    {message && <div className="mb-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">{message}</div>}
    {error && <div className="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>}
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
      <label className="text-sm font-medium text-gray-700">Visibility<select value={form.visibility} onChange={(e) => setForm({ ...form, visibility: e.target.value as LeaderboardSettings['visibility'] })} className="mt-1.5 w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm"><option value="everyone">Visible to Everyone</option><option value="management">Admin/Owner Only</option>{form.visibility === 'disabled' && <option value="disabled">Disabled (legacy setting)</option>}</select></label>
      <label className="text-sm font-medium text-gray-700">Employee data detail<select value={form.data_visibility} onChange={(e) => setForm({ ...form, data_visibility: e.target.value as LeaderboardSettings['data_visibility'] })} className="mt-1.5 w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm"><option value="names_percentages">Names + percentages</option><option value="amounts">Names + amounts</option><option value="anonymous">Anonymous ranking</option></select></label>
      <label className="text-sm font-medium text-gray-700">Sales weight (%)<input type="number" min="0" max="100" value={form.sales_weight} onChange={(e) => setForm({ ...form, sales_weight: Number(e.target.value) })} className="mt-1.5 w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm" /></label>
      <label className="text-sm font-medium text-gray-700">Collection weight (%)<input type="number" min="0" max="100" value={form.collection_weight} onChange={(e) => setForm({ ...form, collection_weight: Number(e.target.value) })} className="mt-1.5 w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm" /></label>
    </div>
    <div className="mt-5 rounded-xl border border-gray-200 p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div><h3 className="text-sm font-semibold text-gray-800">Members shown in leaderboard</h3><p className="mt-0.5 text-xs text-gray-400">Only checked members will appear. Select all members to include future team additions automatically.</p></div>
        <div className="flex gap-2 text-xs font-semibold"><button type="button" onClick={() => setForm({ ...form, selected_user_ids: null })} className="text-indigo-600 hover:text-indigo-800">Select all</button><button type="button" onClick={() => setForm({ ...form, selected_user_ids: [] })} className="text-gray-500 hover:text-gray-700">Clear</button></div>
      </div>
      {members.isLoading ? <p className="mt-4 text-sm text-gray-400">Loading team members…</p> : memberIds.length === 0 ? <p className="mt-4 text-sm text-gray-400">No active team members are available.</p> : <div className="mt-4 grid gap-2 sm:grid-cols-2">{(members.data ?? []).map((member) => <label key={member.id} className="flex min-h-11 cursor-pointer items-center gap-3 rounded-xl border border-gray-200 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"><input type="checkbox" checked={selectedIds.includes(member.id)} onChange={() => toggleMember(member.id)} className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" /><span className="min-w-0"><span className="block truncate font-medium">{member.name}</span><span className="block truncate text-xs text-gray-400">{member.email}</span></span></label>)}</div>}
    </div>
    <div className="mt-4 flex flex-wrap items-center justify-between gap-3"><p className={`text-xs ${total === 100 ? 'text-emerald-600' : 'text-red-600'}`}>Combined weight: {total}% {total === 100 ? '✓' : '— must equal 100%'}</p><button disabled={mutation.isPending || total !== 100} onClick={() => mutation.mutate(form)} className="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">{mutation.isPending ? 'Saving…' : 'Save Leaderboard Settings'}</button></div>
  </div>;
}
