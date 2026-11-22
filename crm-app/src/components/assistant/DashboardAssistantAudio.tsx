'use client';

import { createContext, useCallback, useContext, useEffect, useRef, useSyncExternalStore } from 'react';
import { useQuery } from '@tanstack/react-query';
import { assistantApi, assistantSoundKey, type AssistantSound, type AssistantSoundSettings } from '@/lib/api/assistant';
import { AssistantSoundPlayer } from '@/lib/assistantSoundPlayer';
import { useAuthStore } from '@/store/authStore';

type Robot = 'buddy' | 'coach';
type PreviewResult = 'played' | 'disabled' | 'unavailable' | 'cancelled';

const AudioContextValue = createContext({
  play: (_robot: Robot, _sounds: AssistantSound[]) => {},
  preview: async (_sound: AssistantSound, _settings: AssistantSoundSettings): Promise<PreviewResult> => 'unavailable',
  stopPreview: () => {},
  playWhenHidden: false,
});

export function allowedAssistantSound(
  settings: AssistantSoundSettings | undefined,
  sounds: AssistantSound[],
  playback: { robot: Robot; hidden: boolean; unlocked: boolean; busy: boolean; secondsSinceLast: number },
) {
  if (!settings?.enabled || !playback.unlocked || playback.busy || (playback.hidden && !settings.play_when_hidden)) return;
  const interval = playback.robot === 'buddy' ? settings.buddy_interval_seconds : settings.coach_interval_seconds;
  if (playback.secondsSinceLast < interval) return;
  return sounds.find((sound) => settings[sound]);
}

function subscribeToVisibility(callback: () => void) {
  document.addEventListener('visibilitychange', callback);
  return () => document.removeEventListener('visibilitychange', callback);
}

export function useDashboardVisibility() {
  return useSyncExternalStore(subscribeToVisibility, () => !document.hidden, () => true);
}

export function useDashboardAssistantAudio() {
  return useContext(AudioContextValue);
}

export function DashboardAssistantAudio({ active, children }: { active: boolean; children: React.ReactNode }) {
  const user = useAuthStore((state) => state.user);
  const userId = user?.id;
  const organizationId = user?.organization?.id;
  const canPreview = user?.role === 'owner' || user?.role === 'admin';
  const { data: settings } = useQuery({
    queryKey: assistantSoundKey(organizationId, userId),
    queryFn: ({ signal }) => assistantApi.soundSettings(signal),
    enabled: active && !!userId,
    refetchInterval: 60_000,
    refetchIntervalInBackground: true,
    refetchOnWindowFocus: true,
  });
  const player = useRef<AssistantSoundPlayer>(undefined);
  const playedAt = useRef<Partial<Record<Robot, number>>>({});
  const previewVersion = useRef(0);
  const playingPreview = useRef(false);
  const latest = useRef({ active, settings, canPreview, userId, organizationId });
  latest.current = { active, settings, canPreview, userId, organizationId };

  useEffect(() => () => {
    player.current?.close();
    player.current = undefined;
    playedAt.current = {};
    playingPreview.current = false;
    previewVersion.current++;
  }, [organizationId, userId]);

  useEffect(() => {
    if (!active || !userId) return;
    const unlock = (event: Event) => {
      if (!event.isTrusted || document.hidden || navigator.userActivation?.isActive === false) return;
      if (event.type === 'pointerdown' && (event as PointerEvent).pointerType !== 'mouse') return;
      void (player.current ??= new AssistantSoundPlayer()).unlock();
    };
    const gestures = ['pointerdown', 'click', 'keydown'] as const;
    gestures.forEach((gesture) => window.addEventListener(gesture, unlock, true));
    return () => {
      gestures.forEach((gesture) => window.removeEventListener(gesture, unlock, true));
      if (!playingPreview.current) player.current?.stop();
    };
  }, [active, organizationId, userId]);

  useEffect(() => {
    if (!active) return;
    const stopIfDisabled = () => {
      if (!settings?.enabled || (document.hidden && !settings.play_when_hidden)) player.current?.stop();
    };
    stopIfDisabled();
    return subscribeToVisibility(stopIfDisabled);
  }, [active, settings?.enabled, settings?.play_when_hidden]);

  const play = useCallback((robot: Robot, sounds: AssistantSound[]) => {
    const audio = player.current;
    const { userId, organizationId } = latest.current;
    if (!audio || !latest.current.active) return;

    const schedule = () => {
      const current = latest.current;
      if (!current.active || current.userId !== userId || current.organizationId !== organizationId || player.current !== audio) return;
      const now = performance.now();
      const sound = allowedAssistantSound(current.settings, sounds, {
        robot,
        hidden: document.hidden,
        unlocked: audio.running,
        busy: audio.busy,
        secondsSinceLast: (now - (playedAt.current[robot] ?? -Infinity)) / 1000,
      });
      if (sound && current.settings && audio.play(sound, current.settings.volume)) {
        playingPreview.current = false;
        playedAt.current[robot] = now;
      }
    };

    if (audio.running) schedule();
    else if (audio.unlocking) void audio.unlocking.then((unlocked) => { if (unlocked) schedule(); });
  }, []);

  const preview = useCallback(async (sound: AssistantSound, draft: AssistantSoundSettings): Promise<PreviewResult> => {
    if (!latest.current.canPreview || !draft.enabled || !draft[sound]) return 'disabled';
    const version = ++previewVersion.current;
    const audio = player.current ??= new AssistantSoundPlayer();
    const unlocked = await audio.unlock();
    if (version !== previewVersion.current || !latest.current.canPreview || player.current !== audio) return 'cancelled';
    if (!unlocked || document.hidden || !audio.play(sound, draft.volume)) return 'unavailable';
    playingPreview.current = true;
    return 'played';
  }, []);

  const stopPreview = useCallback(() => {
    previewVersion.current++;
    if (playingPreview.current) {
      playingPreview.current = false;
      player.current?.stop();
    }
  }, []);

  return (
    <AudioContextValue.Provider value={{ play, preview, stopPreview, playWhenHidden: settings?.play_when_hidden ?? false }}>
      {children}
    </AudioContextValue.Provider>
  );
}
