'use client';

import { createContext, useCallback, useContext, useEffect, useRef, useSyncExternalStore } from 'react';
import { useQuery } from '@tanstack/react-query';
import { assistantApi, assistantSoundKey, type AssistantSound, type AssistantSoundSettings } from '@/lib/api/assistant';
import { useAuthStore } from '@/store/authStore';

type Robot = 'buddy' | 'coach';
const AudioContextValue = createContext({
  play: (_robot: Robot, _sounds: AssistantSound[]) => {},
  playWhenHidden: false,
});

const notes: Record<AssistantSound, number[]> = {
  alert: [523, 659],
  success: [523, 659, 784],
  celebrate: [659, 784, 880, 1047],
};
const volumes = { low: 0.045, medium: 0.09, high: 0.16 };

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
  const userId = useAuthStore((state) => state.user?.id);
  const organizationId = useAuthStore((state) => state.user?.organization?.id);
  const { data: settings } = useQuery({
    queryKey: assistantSoundKey(organizationId, userId),
    queryFn: ({ signal }) => assistantApi.soundSettings(signal),
    enabled: active && !!userId,
    refetchInterval: 60_000,
    refetchIntervalInBackground: true,
    refetchOnWindowFocus: true,
  });
  const audio = useRef<AudioContext>();
  const output = useRef<GainNode>();
  const busyUntil = useRef(0);
  const playedAt = useRef<Partial<Record<Robot, number>>>({});

  useEffect(() => {
    if (!active || !userId) return;
    const unlock = (event: Event) => {
      if (!event.isTrusted || document.hidden) return;
      try {
        const context = audio.current ??= new AudioContext();
        if (context.state !== 'running') void context.resume().catch(() => {});
      } catch {
        // Audio is optional when unavailable or blocked by the browser.
      }
    };
    window.addEventListener('pointerup', unlock);
    window.addEventListener('keydown', unlock);
    return () => {
      window.removeEventListener('pointerup', unlock);
      window.removeEventListener('keydown', unlock);
      const context = audio.current;
      audio.current = undefined;
      output.current = undefined;
      busyUntil.current = 0;
      if (context && context.state !== 'closed') void context.close().catch(() => {});
    };
  }, [active, organizationId, userId]);

  useEffect(() => {
    playedAt.current = {};
  }, [organizationId, userId]);

  useEffect(() => {
    if (!active) return;
    const mute = () => {
      const context = audio.current;
      if ((!settings?.enabled || (document.hidden && !settings.play_when_hidden)) && context && output.current) {
        output.current.gain.setValueAtTime(0, context.currentTime);
      }
    };
    mute();
    return subscribeToVisibility(mute);
  }, [active, settings?.enabled, settings?.play_when_hidden]);

  const play = useCallback((robot: Robot, sounds: AssistantSound[]) => {
    const context = audio.current;
    if (!active || !settings || !context) return;
    const now = performance.now();
    const sound = allowedAssistantSound(settings, sounds, {
      robot,
      hidden: document.hidden,
      unlocked: context.state === 'running',
      busy: context.currentTime < busyUntil.current,
      secondsSinceLast: (now - (playedAt.current[robot] ?? -Infinity)) / 1000,
    });
    if (!sound) return;

    const start = context.currentTime;
    const gain = context.createGain();
    gain.gain.value = volumes[settings.volume];
    gain.connect(context.destination);
    output.current = gain;
    notes[sound].forEach((frequency, index) => {
      const oscillator = context.createOscillator();
      const envelope = context.createGain();
      const noteStart = start + index * 0.1;
      oscillator.type = 'sine';
      oscillator.frequency.value = frequency;
      envelope.gain.setValueAtTime(0.001, noteStart);
      envelope.gain.exponentialRampToValueAtTime(0.8, noteStart + 0.025);
      envelope.gain.exponentialRampToValueAtTime(0.001, noteStart + 0.18);
      oscillator.connect(envelope).connect(gain);
      oscillator.start(noteStart);
      oscillator.stop(noteStart + 0.18);
      oscillator.onended = () => {
        oscillator.disconnect();
        envelope.disconnect();
        if (index === notes[sound].length - 1) gain.disconnect();
      };
    });
    busyUntil.current = start + notes[sound].length * 0.1 + 0.1;
    playedAt.current[robot] = now;
  }, [active, settings]);

  return <AudioContextValue.Provider value={{ play, playWhenHidden: settings?.play_when_hidden ?? false }}>{children}</AudioContextValue.Provider>;
}
