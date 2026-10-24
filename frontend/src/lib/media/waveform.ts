import { coordinator, playable } from './player';
import {
  formatTrackTime,
  waveformFraction,
  waveformKeyboardFraction,
} from './waveform-model';

const pendingSelections = new WeakMap<HTMLElement, Promise<void>>();

function queueFor(element: HTMLElement) {
  const list = element.closest('.track-list');
  return Array.from(
    list?.querySelectorAll<HTMLElement>('[data-track-row]:not([hidden])') ?? [],
  ).map(row => playable(row.dataset.trackRow!));
}

async function ensureSelected(element: HTMLElement) {
  const id = element.dataset.trackId!;
  if (coordinator.state.kind === 'track' && coordinator.state.track?.id === id) return;
  const pending = pendingSelections.get(element);
  if (pending) return pending;
  const selection = coordinator.select(playable(id), queueFor(element)).finally(() => {
    pendingSelections.delete(element);
  });
  pendingSelections.set(element, selection);
  return selection;
}

async function seek(element: HTMLElement, fraction: number) {
  await ensureSelected(element);
  coordinator.seek(fraction);
}

function renderWaveforms() {
  const state = coordinator.state;
  document.querySelectorAll<HTMLElement>('[data-track-row]').forEach(row => {
    const current = state.kind === 'track' && state.track?.id === row.dataset.trackRow;
    const playing = current && state.playing;
    row.classList.toggle('is-current', current);
    row.classList.toggle('is-playing', playing);
    row.querySelectorAll<HTMLElement>('[data-play-track]').forEach(button => {
      const title = row.querySelector<HTMLElement>('.track-title')?.textContent?.trim() || 'track';
      button.setAttribute('aria-label', `${playing ? 'Pause' : 'Play'} ${title}`);
      button.querySelector<HTMLElement>('[data-track-play-icon]')!.hidden = playing;
      button.querySelector<HTMLElement>('[data-track-pause-icon]')!.hidden = !playing;
    });

    const waveform = row.querySelector<HTMLElement>('[data-waveform]');
    if (!waveform) return;
    const duration = current ? state.duration ?? state.track?.duration : null;
    const progress = current && duration ? Math.max(0, Math.min(1, state.time / duration)) : 0;
    const percent = progress * 100;
    const played = waveform.querySelector<SVGGElement>('[data-wave-played]');
    if (played) played.style.clipPath = `inset(0 ${100 - percent}% 0 0)`;
    waveform.setAttribute('aria-valuenow', String(Math.round(percent)));
    if (current) {
      waveform.setAttribute(
        'aria-valuetext',
        `${formatTrackTime(state.time)} of ${formatTrackTime(duration)}`,
      );
    }
  });
}

export function initTrackWaveforms(signal: AbortSignal) {
  const unsubscribe = coordinator.subscribe(renderWaveforms);
  signal.addEventListener('abort', unsubscribe, { once: true });

  document.querySelectorAll<HTMLElement>('[data-waveform]').forEach(waveform => {
    const fractionAt = (clientX: number) => {
      const bounds = waveform.getBoundingClientRect();
      return waveformFraction(clientX, bounds.left, bounds.width);
    };

    waveform.addEventListener('pointerdown', event => {
      if (event.button !== 0) return;
      event.preventDefault();
      waveform.dataset.scrubbing = String(event.pointerId);
      waveform.setPointerCapture(event.pointerId);
      void seek(waveform, fractionAt(event.clientX));
    }, { signal });
    waveform.addEventListener('pointermove', event => {
      if (waveform.dataset.scrubbing !== String(event.pointerId)) return;
      event.preventDefault();
      void seek(waveform, fractionAt(event.clientX));
    }, { signal });
    const finishPointer = (event: PointerEvent) => {
      if (waveform.dataset.scrubbing !== String(event.pointerId)) return;
      delete waveform.dataset.scrubbing;
      if (waveform.hasPointerCapture(event.pointerId)) waveform.releasePointerCapture(event.pointerId);
    };
    waveform.addEventListener('pointerup', finishPointer, { signal });
    waveform.addEventListener('pointercancel', finishPointer, { signal });
    waveform.addEventListener('keydown', event => {
      const state = coordinator.state;
      const current = state.kind === 'track' && state.track?.id === waveform.dataset.trackId;
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        if (current) void coordinator.toggle();
        else void ensureSelected(waveform);
        return;
      }
      const duration = current ? state.duration ?? state.track?.duration : 0;
      const fraction = current && duration ? state.time / duration : 0;
      const next = waveformKeyboardFraction(event.key, fraction);
      if (next === null) return;
      event.preventDefault();
      void seek(waveform, next);
    }, { signal });
  });
}
