import { siteConfig, type Section } from '../../config/site.config';
import type { ShowreelGainController } from './showreel-audio';

export interface Playable {
  id: string;
  title: string;
  url: string | null;
  projectId?: string;
  duration?: number | null;
  peaks?: number[] | null;
}

export interface MediaPort {
  src: string;
  currentTime: number;
  duration: number;
  volume: number;
  muted: boolean;
  paused: boolean;
  readyState: number;
  play(): Promise<void>;
  pause(): void;
  load(): void;
  addEventListener(type: string, callback: EventListener): void;
  removeEventListener(type: string, callback: EventListener): void;
  removeAttribute(name: string): void;
}

export interface PlayerState {
  track: Playable | null;
  queue: Playable[];
  index: number;
  playing: boolean;
  visible: boolean;
  shuffle: boolean;
  error: string | null;
  kind: 'track' | 'showreel';
  time: number;
  duration: number | null;
}

export interface FadeClock {
  now(): number;
  requestFrame(callback: FrameRequestCallback): number;
  cancelFrame(id: number): void;
}

const browserFadeClock: FadeClock = {
  now: () => performance.now(),
  requestFrame: callback => requestAnimationFrame(callback),
  cancelFrame: id => cancelAnimationFrame(id),
};

export function adjacentIndex(
  length: number,
  current: number,
  direction: 1 | -1,
  shuffle: boolean,
  random = Math.random,
) {
  if (!length) return -1;
  if (shuffle && length > 1) {
    const offset = 1 + Math.floor(random() * (length - 1));
    return (current + offset) % length;
  }
  const index = current + direction;
  return index < 0 ? 0 : index >= length ? -1 : index;
}

export class MediaCoordinator {
  state: PlayerState = {
    track: null,
    queue: [],
    index: -1,
    playing: false,
    visible: false,
    shuffle: false,
    error: null,
    kind: 'track',
    time: 0,
    duration: null,
  };
  showreelPauseReason: 'manual' | 'navigation' | 'source' | null = null;

  private listeners = new Set<() => void>();
  private generation = 0;
  private active: MediaPort;
  private otherSources = new Set<() => void>();
  private played: number[] = [];
  private observed = new WeakSet<MediaPort>();
  private dismissed = false;
  private fading:
    | { video: MediaPort; volume: number; frame: number; generation: number }
    | undefined;
  private pendingShowreelSeek:
    | { video: MediaPort; listener: EventListener }
    | undefined;
  private applyingFade = false;
  private showreelGains = new WeakMap<MediaPort, ShowreelGainController>();
  private showreelPanelAllowed = true;

  constructor(
    private audio: MediaPort,
    private fadeClock: FadeClock = browserFadeClock,
  ) {
    this.active = audio;
    this.observe(audio);
    audio.volume = siteConfig.media.volume;
  }

  subscribe(fn: () => void) {
    this.listeners.add(fn);
    fn();
    return () => this.listeners.delete(fn);
  }

  private emit() {
    this.listeners.forEach(fn => fn());
  }

  private cancelPendingOperation() {
    ++this.generation;
    if (this.fading) {
      this.fadeClock.cancelFrame(this.fading.frame);
      this.restoreFadeOutput(this.fading.video, this.fading.volume);
      this.fading = undefined;
    }
    if (this.pendingShowreelSeek) {
      const { video, listener } = this.pendingShowreelSeek;
      video.removeEventListener('loadedmetadata', listener);
      this.pendingShowreelSeek = undefined;
    }
  }

  cancelShowreelFade() {
    if (!this.fading) return;
    this.cancelPendingOperation();
    this.emit();
  }

  private setFadedVolume(video: MediaPort, volume: number) {
    this.applyingFade = true;
    video.volume = volume;
    this.applyingFade = false;
  }

  private restoreFadeOutput(video: MediaPort, volume: number) {
    const gain = this.showreelGains.get(video);
    if (gain) gain.restore();
    else this.setFadedVolume(video, volume);
  }

  setShowreelGain(video: MediaPort, gain: ShowreelGainController) {
    this.showreelGains.set(video, gain);
  }

  resumeShowreelAudio(video: MediaPort) {
    const gain = this.showreelGains.get(video);
    if (!gain) return;
    void gain.resume().catch(error => {
      console.warn('Showreel audio context could not resume.', error);
    });
  }

  private observe(media: MediaPort) {
    if (this.observed.has(media)) return;
    this.observed.add(media);
    for (const type of ['timeupdate', 'durationchange', 'loadedmetadata']) {
      media.addEventListener(type, () => {
        if (media !== this.active) return;
        this.state.time = Number.isFinite(media.currentTime) ? media.currentTime : 0;
        this.state.duration =
          Number.isFinite(media.duration) && media.duration > 0
            ? media.duration
            : null;
        this.emit();
      });
    }
    media.addEventListener('play', () => {
      if (media === this.active) {
        this.state.playing = true;
        if (this.state.kind === 'showreel') this.showreelPauseReason = null;
        this.emit();
      }
    });
    media.addEventListener('volumechange', () => {
      if (media === this.active && !this.applyingFade) this.emit();
    });
    media.addEventListener('pause', () => {
      if (media === this.active) {
        this.state.playing = false;
        if (this.state.kind === 'showreel' && !this.showreelPauseReason) {
          this.showreelPauseReason = 'manual';
        }
        this.emit();
      }
    });
    media.addEventListener('ended', () => {
      if (media === this.active) {
        this.state.playing = false;
        if (this.state.kind === 'track') void this.next();
        else this.emit();
      }
    });
    media.addEventListener('error', () => {
      if (media === this.active) {
        this.state.playing = false;
        this.state.error = 'This audio could not be loaded. Please try another track.';
        this.emit();
      }
    });
  }

  registerOther(stop: () => void) {
    this.otherSources.add(stop);
    return () => this.otherSources.delete(stop);
  }

  stopOthers() {
    this.otherSources.forEach(stop => stop());
    if (this.active !== this.audio) this.audio.pause();
  }

  async select(track: Playable, queue: Playable[]) {
    this.pause('source');
    const generation = ++this.generation;
    this.dismissed = false;
    this.stopOthers();
    this.active = this.audio;
    this.state.kind = 'track';
    this.state.track = track;
    this.state.queue = queue.filter(item => !!item.url);
    this.state.index = this.state.queue.findIndex(item => item.id === track.id);
    this.state.visible = true;
    this.state.playing = false;
    this.state.error = null;
    this.state.time = 0;
    this.state.duration = track.duration || null;
    this.played = [];
    if (!track.url) {
      this.audio.removeAttribute('src');
      this.state.error = 'Audio is not available yet.';
      this.emit();
      return;
    }
    this.audio.src = track.url;
    this.audio.load();
    this.emit();
    await this.start(generation);
  }

  private async start(generation = this.generation) {
    try {
      await this.active.play();
      if (generation !== this.generation) return;
      this.state.playing = !this.active.paused;
      this.state.error = null;
    } catch (error) {
      if (generation !== this.generation) return;
      this.state.playing = false;
      this.state.error = 'Playback could not start. Press play to try again.';
      console.warn('Media playback could not start.', error);
    }
    this.emit();
  }

  async toggle() {
    if (!this.state.track?.url && this.state.kind === 'track') return;
    if (this.state.playing) this.pause();
    else {
      this.cancelPendingOperation();
      this.stopOthers();
      this.state.visible = true;
      await this.start();
    }
  }

  pause(reason: 'manual' | 'navigation' | 'source' = 'manual') {
    this.cancelPendingOperation();
    if (
      this.state.kind === 'showreel' &&
      (!this.active.paused || !this.showreelPauseReason || reason === 'manual')
    ) {
      this.showreelPauseReason = reason;
    }
    this.active.pause();
    this.state.playing = false;
    this.emit();
  }

  async next() {
    const next = adjacentIndex(
      this.state.queue.length,
      this.state.index,
      1,
      this.state.shuffle,
    );
    if (next === -1) {
      this.pause();
      return;
    }
    const history = [...this.played, this.state.index];
    await this.select(this.state.queue[next]!, this.state.queue);
    this.played = history;
  }

  async previous() {
    if (this.active.currentTime > 3) {
      this.seek(0);
      return;
    }
    const previous =
      this.played.pop() ??
      adjacentIndex(this.state.queue.length, this.state.index, -1, false);
    if (previous >= 0) {
      const history = [...this.played];
      await this.select(this.state.queue[previous]!, this.state.queue);
      this.played = history;
    }
  }

  toggleShuffle() {
    this.state.shuffle = !this.state.shuffle;
    this.emit();
  }

  seek(fraction: number) {
    const duration = this.active.duration;
    if (Number.isFinite(duration) && duration > 0) {
      this.active.currentTime = Math.max(0, Math.min(1, fraction)) * duration;
      this.state.time = this.active.currentTime;
      this.emit();
    }
  }

  volume(value: number) {
    this.cancelPendingOperation();
    this.active.volume = Math.max(0, Math.min(1, value));
    this.active.muted = value === 0;
    this.emit();
  }

  section(section: Section) {
    const fadeInProgress = this.fading?.video === this.active;
    this.showreelPanelAllowed = section === 'home';
    const hideShowreel =
      this.state.kind === 'showreel' && !this.showreelPanelAllowed;
    const fadeShowreelForAbout =
      section === 'about' &&
      this.state.kind === 'showreel' &&
      !fadeInProgress &&
      this.fadeShowreelForNavigation(this.active);
    if (
      (siteConfig.media.pauseOnSections.includes(section) || hideShowreel) &&
      !fadeInProgress &&
      !fadeShowreelForAbout
    ) {
      this.pause('navigation');
    }
    if (siteConfig.media.hidePanelOnSections.includes(section) || hideShowreel) {
      this.state.visible = false;
    } else if (
      this.state.track &&
      !this.dismissed &&
      (this.state.kind === 'track' || !this.active.muted)
    ) {
      this.state.visible = true;
    }
    this.emit();
  }

  hideShowreelPanelForNavigation() {
    if (this.state.kind !== 'showreel') return;
    this.state.visible = false;
    this.emit();
  }

  close() {
    this.pause();
    this.active.currentTime = 0;
    this.state.time = 0;
    this.dismissed = true;
    this.state.visible = false;
    this.emit();
  }

  activateExternal() {
    this.pause('source');
    this.state.visible = false;
    this.stopOthers();
    this.emit();
  }

  fadeShowreelForNavigation(video: MediaPort) {
    if (
      this.active !== video ||
      this.state.kind !== 'showreel' ||
      video.paused ||
      video.muted ||
      video.volume <= 0
    ) {
      return false;
    }
    this.cancelPendingOperation();
    const generation = this.generation;
    const startVolume = video.volume;
    const startedAt = this.fadeClock.now();
    const gain = this.showreelGains.get(video);
    if (gain) gain.fadeOut(siteConfig.media.showreelFadeDuration);
    const step: FrameRequestCallback = now => {
      if (!this.fading || this.fading.generation !== generation) return;
      const progress = Math.min(
        1,
        Math.max(0, (now - startedAt) / siteConfig.media.showreelFadeDuration),
      );
      if (!gain) this.setFadedVolume(video, startVolume * (1 - progress));
      if (progress < 1) {
        this.fading.frame = this.fadeClock.requestFrame(step);
        return;
      }
      this.fading = undefined;
      this.showreelPauseReason = 'navigation';
      video.pause();
      this.restoreFadeOutput(video, startVolume);
      this.state.playing = false;
      this.emit();
    };
    this.fading = {
      video,
      volume: startVolume,
      frame: this.fadeClock.requestFrame(step),
      generation,
    };
    return true;
  }

  pauseShowreelForNavigation(video: MediaPort) {
    if (this.fading?.video === video) return;
    if (this.active === video) this.pause('navigation');
    else video.pause();
  }

  resumeShowreelFromNavigation(video: MediaPort) {
    if (
      this.active === video &&
      this.showreelPauseReason === 'navigation' &&
      video.paused &&
      this.showreelPanelAllowed
    ) {
      return this.useShowreel(video, false, !video.muted);
    }
    return Promise.resolve();
  }

  mute() {
    this.cancelPendingOperation();
    this.active.muted = !this.active.muted;
    this.emit();
  }

  async useShowreel(video: MediaPort, unmute = true, showPanel = true) {
    if (this.active !== video) {
      this.pause('source');
      this.stopOthers();
      this.active = video;
    } else {
      this.cancelPendingOperation();
    }
    this.observe(video);
    if (unmute) video.muted = false;
    if (!video.muted) this.resumeShowreelAudio(video);
    this.state.kind = 'showreel';
    this.state.track = { id: 'showreel', title: 'Showreel', url: video.src };
    this.state.visible = showPanel && this.showreelPanelAllowed;
    this.state.time = video.currentTime;
    this.state.duration = Number.isFinite(video.duration) ? video.duration : null;
    await this.start();
  }

  restartShowreel(video: MediaPort) {
    this.pause('source');
    this.stopOthers();
    this.active = video;
    this.observe(video);
    this.state.kind = 'showreel';
    this.state.track = { id: 'showreel', title: 'Showreel', url: video.src };
    this.state.visible = !video.muted && this.showreelPanelAllowed;
    this.state.time = 0;
    this.state.duration = Number.isFinite(video.duration) ? video.duration : null;
    this.state.playing = false;
    this.state.error = null;
    if (!video.muted) this.resumeShowreelAudio(video);
    const generation = this.generation;
    const seekAndStart = () => {
      if (generation !== this.generation) return;
      try {
        video.currentTime = 0;
      } catch (error) {
        this.state.error = 'Showreel could not be restarted. Press play to try again.';
        console.warn('Showreel seek could not start.', error);
        this.emit();
        return;
      }
      void this.start(generation);
    };
    if (video.readyState >= 1) {
      seekAndStart();
    } else {
      const listener: EventListener = () => {
        video.removeEventListener('loadedmetadata', listener);
        if (this.pendingShowreelSeek?.listener === listener) {
          this.pendingShowreelSeek = undefined;
        }
        seekAndStart();
      };
      this.pendingShowreelSeek = { video, listener };
      video.addEventListener('loadedmetadata', listener);
    }
    this.emit();
  }

  exposePanel() {
    if (
      this.state.track &&
      (this.state.kind === 'track' || this.showreelPanelAllowed)
    ) {
      this.state.visible = true;
      this.emit();
    }
  }

  get currentMedia() {
    return this.active;
  }
}
