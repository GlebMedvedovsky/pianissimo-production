export interface ShowreelGainController {
  fadeOut(duration: number): void;
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

  const setGain = (value: number) => {
    const now = context.currentTime;
    gain.gain.cancelScheduledValues(now);
    gain.gain.setValueAtTime(value, now);
  };

  setGain(1);
  return {
    fadeOut(duration) {
      const now = context.currentTime;
      gain.gain.cancelScheduledValues(now);
      gain.gain.setValueAtTime(1, now);
      gain.gain.linearRampToValueAtTime(0, now + duration / 1000);
    },
    restore() {
      setGain(1);
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
