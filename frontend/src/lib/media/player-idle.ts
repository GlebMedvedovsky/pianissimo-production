import { coordinator } from './player';

const IDLE_DELAY = 3800;

export function initPlayerIdle(signal: AbortSignal) {
  const player = document.querySelector<HTMLElement>('.player');
  if (!player) return;
  let timer = 0;
  let previous = '';

  const schedule = () => {
    player.classList.remove('is-idle');
    window.clearTimeout(timer);
    timer = window.setTimeout(() => {
      if (!player.matches(':hover') && !player.matches(':focus-within')) {
        player.classList.add('is-idle');
      }
    }, IDLE_DELAY);
  };
  const unsubscribe = coordinator.subscribe(() => {
    const state = coordinator.state;
    const meaningfulState = `${state.kind}:${state.track?.id ?? ''}:${state.playing}:${state.visible}`;
    if (meaningfulState !== previous) {
      previous = meaningfulState;
      schedule();
    }
  });
  const wake = (event: Event) => {
    const target = event.target as Element | null;
    if (player.contains(target) || target?.closest('[data-player],[data-play-track],[data-waveform]')) schedule();
  };
  for (const type of ['pointerdown', 'pointermove', 'focusin', 'focusout', 'keydown']) {
    document.addEventListener(type, wake, { signal, passive: type.startsWith('pointer') });
  }
  player.addEventListener('pointerleave', schedule, { signal });
  signal.addEventListener('abort', () => {
    window.clearTimeout(timer);
    unsubscribe();
  }, { once: true });
  schedule();
}
