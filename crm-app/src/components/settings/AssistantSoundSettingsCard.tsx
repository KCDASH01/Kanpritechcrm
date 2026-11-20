'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { assistantApi, assistantSoundKey, type AssistantSoundSettings } from '@/lib/api/assistant';
import { useAuthStore } from '@/store/authStore';

const toggles = [
  ['enabled', 'Master sound effects'],
  ['success', 'Success sound'],
  ['alert', 'Important alert sound'],
  ['celebrate', 'Celebration sound'],
  ['play_when_hidden', 'Allow sounds while the Dashboard tab is hidden'],
] as const;
const intervals = [
  ['buddy_interval_seconds', 'Follow-up Buddy'],
  ['coach_interval_seconds', 'Target Coach'],
] as const;

export function AssistantSoundSettingsCard() {
  const user = useAuthStore((state) => state.user);
  const queryKey = assistantSoundKey(user?.organization?.id, user?.id);
  const queryClient = useQueryClient();
  const { data, isError } = useQuery({ queryKey, queryFn: ({ signal }) => assistantApi.soundSettings(signal) });
  const [draft, setDraft] = useState<AssistantSoundSettings>();
  const settings = draft ?? data;
  const save = useMutation({
    mutationFn: assistantApi.updateSoundSettings,
    onSuccess: (saved) => {
      queryClient.setQueryData(queryKey, saved);
      setDraft(undefined);
    },
  });
  const change = (patch: Partial<AssistantSoundSettings>) => {
    if (!settings) return;
    save.reset();
    setDraft({ ...settings, ...patch });
  };

  return (
    <section className="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="assistant-sound-heading">
      <h2 id="assistant-sound-heading" className="font-bold text-gray-900">Assistant Sound Settings</h2>
      {!settings ? <p className="mt-3 text-sm text-gray-600">{isError ? 'Sound settings are temporarily unavailable.' : 'Loading sound settings…'}</p> : (
        <form className="mt-4 space-y-4" onSubmit={(event) => { event.preventDefault(); save.mutate(settings); }}>
          <fieldset disabled={save.isPending} className="space-y-4">
            <div className="flex flex-col gap-3">
              {toggles.map(([key, label]) => <label key={key} className="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" checked={settings[key]} onChange={(event) => change({ [key]: event.target.checked })} className="h-4 w-4 accent-indigo-600" />
                {label}
              </label>)}
            </div>
            <label className="block max-w-44 text-sm font-medium text-gray-700">Volume
              <select value={settings.volume} onChange={(event) => change({ volume: event.target.value as AssistantSoundSettings['volume'] })} className="mt-1 block w-full rounded-xl border border-gray-300 bg-white p-2">
                <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option>
              </select>
            </label>
            <div>
              <p id="assistant-sound-interval-label" className="text-sm font-semibold text-gray-900">Minimum time between sounds</p>
              <p className="mt-1 text-xs text-gray-500">Enter 15–3,600 seconds for each robot. Sounds still require a new alert or a confirmed achievement.</p>
              <div className="mt-2 grid gap-3 sm:grid-cols-2" role="group" aria-labelledby="assistant-sound-interval-label">
                {intervals.map(([key, label]) => <label key={key} className="block text-sm text-gray-700">{label} (seconds)
                  <input type="number" min={15} max={3600} required value={Number.isNaN(settings[key]) ? '' : settings[key]} onChange={(event) => change({ [key]: event.target.valueAsNumber })} className="mt-1 block w-full rounded-xl border border-gray-300 p-2" />
                </label>)}
              </div>
            </div>
          </fieldset>
          <button type="submit" disabled={!draft || save.isPending} className="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">{save.isPending ? 'Saving…' : 'Save changes'}</button>
          {save.isSuccess && <p role="status" className="text-sm text-green-700">Assistant sound settings saved.</p>}
          {save.isError && <p role="alert" className="text-sm text-red-700">Could not save assistant sound settings. Try again.</p>}
        </form>
      )}
    </section>
  );
}
