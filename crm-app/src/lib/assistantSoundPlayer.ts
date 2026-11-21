import type { AssistantSound, AssistantSoundSettings } from './api/assistant';

const notes: Record<AssistantSound, number[]> = {
  alert: [523, 659],
  success: [523, 659, 784],
  celebrate: [659, 784, 880, 1047],
};
const volumes = { low: 0.14, medium: 0.25, high: 0.4 };

export class AssistantSoundPlayer {
  private context?: AudioContext;
  private resuming?: Promise<boolean>;
  private output?: GainNode;
  private busyUntil = 0;

  get running() {
    return this.context?.state === 'running';
  }

  get busy() {
    return !!this.context && this.context.currentTime < this.busyUntil;
  }

  get unlocking() {
    return this.resuming;
  }

  // Call directly from a trusted gesture so the browser can grant audio access.
  unlock(): Promise<boolean> {
    if (this.running) return Promise.resolve(true);
    if (this.resuming) return this.resuming;
    try {
      const context = this.context ??= new AudioContext();
      const resume = context.resume();
      // A browser can leave resume() pending when it denies autoplay.
      const pending = new Promise<boolean>((resolve) => {
        const timeout = window.setTimeout(() => resolve(false), 1200);
        const finish = (running: boolean) => {
          window.clearTimeout(timeout);
          resolve(running);
        };
        resume.then(() => finish(context.state === 'running'), () => finish(false));
      });
      this.resuming = pending;
      void pending.then(() => {
        if (this.resuming === pending) this.resuming = undefined;
      });
      return pending;
    } catch {
      return Promise.resolve(false);
    }
  }

  play(sound: AssistantSound, volume: AssistantSoundSettings['volume']): boolean {
    const context = this.context;
    if (!context || context.state !== 'running') return false;

    this.stop();
    const frequencies = notes[sound];
    const start = context.currentTime + 0.015;

    try {
      const output = context.createGain();
      output.gain.value = volumes[volume];
      output.connect(context.destination);
      this.output = output;
      this.busyUntil = start + (frequencies.length - 1) * 0.13 + 0.23;
      frequencies.forEach((frequency, index) => {
        const oscillator = context.createOscillator();
        const envelope = context.createGain();
        const noteStart = start + index * 0.13;
        oscillator.type = 'sine';
        oscillator.frequency.value = frequency;
        envelope.gain.setValueAtTime(0.001, noteStart);
        envelope.gain.exponentialRampToValueAtTime(0.9, noteStart + 0.02);
        envelope.gain.exponentialRampToValueAtTime(0.35, noteStart + 0.13);
        envelope.gain.exponentialRampToValueAtTime(0.001, noteStart + 0.23);
        oscillator.connect(envelope);
        envelope.connect(output);
        oscillator.onended = () => {
          oscillator.disconnect();
          envelope.disconnect();
          if (index === frequencies.length - 1 && this.output === output) this.stop();
        };
        oscillator.start(noteStart);
        oscillator.stop(noteStart + 0.23);
      });
      return true;
    } catch {
      this.stop();
      return false;
    }
  }

  stop() {
    this.output?.disconnect();
    this.output = undefined;
    this.busyUntil = 0;
  }

  close() {
    this.stop();
    const context = this.context;
    this.context = undefined;
    this.resuming = undefined;
    if (context && context.state !== 'closed') void context.close().catch(() => {});
  }
}
