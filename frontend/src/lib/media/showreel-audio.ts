export interface ShowreelGainController {
  setUserState(muted: boolean, volume: number): void;
  fadeOut(duration: number): void;
  hold(): void;
  restore(): void;
  resume(): Promise<void>;
}

interface GainParam {
  cancelScheduledValues(startTime: number): void;
  setValueAtTime(value: number, startTime: number): void;
  linearRampToValueAtTime(value: number, endTime: number): void;
}

interface GainNodePort {
  gain: GainParam;
  connect(destination: unknown): unknown;
}

interface MediaSourcePort {
  connect(destination: unknown): unknown;
}

export interface AudioContextPort {
  currentTime: number;
  state: string;
  destination: unknown;
  createMediaElementSource(media: HTMLMediaElement): MediaSourcePort;
  createGain(): GainNodePort;
  resume(): Promise<void>;
}

type AudioContextFactory = () => AudioContextPort | null;

const graphs = new WeakMap<HTMLMediaElement, ShowreelGainController>();

function browserAudioContext(): AudioContextPort | null {
  if (typeof window === 'undefined') return null;
  const AudioContextConstructor = window.AudioContext ?? (
    window as Window & {
      webkitAudioContext?: typeof AudioContext;
    }
  ).webkitAudioContext;
  return AudioContextConstructor ? new AudioContextConstructor() : null;
}

export function createShowreelGain(
  video: HTMLMediaElement,
  createContext: AudioContextFactory = browserAudioContext,
): ShowreelGainController | null {
  const context = createContext();
  if (!context) return null;
  const source = context.createMediaElementSource(video);
  const gain = context.createGain();
  source.connect(gain);
  gain.connect(context.destination);

  let startValue = 1;
  let startTime = context.currentTime;
  let endTime = startTime;
  let userMuted = true;
  let userVolume = 1;

  const currentValue = (now: number) => {
    if (endTime <= startTime) return startValue;
    if (now >= endTime) return 0;
    const progress = Math.max(0, Math.min(1, (now - startTime) / (endTime - startTime)));
    return startValue * (1 - progress);
  };

  const setGain = (value: number) => {
    const now = context.currentTime;
    gain.gain.cancelScheduledValues(now);
    gain.gain.setValueAtTime(value, now);
    startValue = value;
    startTime = now;
    endTime = now;
  };

  const userGain = () => userMuted ? 0 : userVolume;

  // Start silent. The coordinator synchronously applies the authoritative
  // user state after attaching this one reusable MediaElementSource graph.
  setGain(0);
  return {
    setUserState(muted, volume) {
      userMuted = muted;
      userVolume = Math.max(0, Math.min(1, volume));
      setGain(userGain());
    },
    fadeOut(duration) {
      const now = context.currentTime;
      const value = currentValue(now);
      gain.gain.cancelScheduledValues(now);
      gain.gain.setValueAtTime(value, now);
      gain.gain.linearRampToValueAtTime(0, now + duration / 1000);
      startValue = value;
      startTime = now;
      endTime = now + duration / 1000;
    },
    hold() {
      const now = context.currentTime;
      setGain(currentValue(now));
    },
    restore() {
      setGain(userGain());
    },
    resume() {
      return context.state === 'suspended' ? context.resume() : Promise.resolve();
    },
  };
}

export function prepareShowreelAudio(video: HTMLVideoElement) {
  let graph = graphs.get(video);
  if (!graph) {
    graph = createShowreelGain(video) ?? undefined;
    if (graph) graphs.set(video, graph);
  }
  return graph ?? null;
}
