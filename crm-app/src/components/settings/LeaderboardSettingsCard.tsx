'use client';

import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { salesTargetsApi } from '@/lib/api/salesTargets';
import type { LeaderboardSettings } from '@/types';

const defaults: LeaderboardSettings = { visibility: 'management', data_visibility: 'names_percentages', sales_weight: 60, collection_weight: 40 };

export function LeaderboardSettingsCard() {
  const queryClient = useQueryClient();
  const settings = useQuery({ queryKey: ['leaderboard-settings'], queryFn: salesTargetsApi.leaderboardSettings });
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

  return <div className="rounded-2xl border border-gray-200 bg-white p-6">
    <div className="mb-5"><h2 className="font-semibold text-gray-900">Performance Leaderboard</h2><p className="mt-0.5 text-xs text-gray-400">Control who can see rankings and exactly which data is shared</p></div>
    {message && <div className="mb-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">{message}</div>}
    {error && <div className="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>}
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
      <label className="text-sm font-medium text-gray-700">Visibility<select value={form.visibility} onChange={(e) => setForm({ ...form, visibility: e.target.value as LeaderboardSettings['visibility'] })} className="mt-1.5 w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm"><option value="everyone">Everyone</option><option value="management">Management only</option><option value="disabled">Disabled</option></select></label>
      <label className="text-sm font-medium text-gray-700">Employee data detail<select value={form.data_visibility} onChange={(e) => setForm({ ...form, data_visibility: e.target.value as LeaderboardSettings['data_visibility'] })} className="mt-1.5 w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm"><option value="names_percentages">Names + percentages</option><option value="amounts">Names + amounts</option><option value="anonymous">Anonymous ranking</option></select></label>
      <label className="text-sm font-medium text-gray-700">Sales weight (%)<input type="number" min="0" max="100" value={form.sales_weight} onChange={(e) => setForm({ ...form, sales_weight: Number(e.target.value) })} className="mt-1.5 w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm" /></label>
      <label className="text-sm font-medium text-gray-700">Collection weight (%)<input type="number" min="0" max="100" value={form.collection_weight} onChange={(e) => setForm({ ...form, collection_weight: Number(e.target.value) })} className="mt-1.5 w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm" /></label>
    </div>
    <div className="mt-4 flex flex-wrap items-center justify-between gap-3"><p className={`text-xs ${total === 100 ? 'text-emerald-600' : 'text-red-600'}`}>Combined weight: {total}% {total === 100 ? '✓' : '— must equal 100%'}</p><button disabled={mutation.isPending || total !== 100} onClick={() => mutation.mutate(form)} className="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">{mutation.isPending ? 'Saving…' : 'Save Leaderboard Settings'}</button></div>
  </div>;
}
