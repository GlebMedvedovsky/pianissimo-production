export const WAVEFORM_SAMPLE_COUNT = 96;

export function normalizeWaveform(peaks: readonly number[] | null | undefined) {
  if (!peaks?.length || !peaks.some(Number.isFinite)) return null;
  const source = peaks.map(value =>
    Number.isFinite(value) ? Math.max(0, Math.min(1, value)) : 0,
  );
  if (source.length === WAVEFORM_SAMPLE_COUNT) return source;
  if (source.length === 1) return Array(WAVEFORM_SAMPLE_COUNT).fill(source[0]);
  return Array.from({ length: WAVEFORM_SAMPLE_COUNT }, (_, index) => {
    const position = index * (source.length - 1) / (WAVEFORM_SAMPLE_COUNT - 1);
    const left = Math.floor(position);
    const right = Math.min(source.length - 1, Math.ceil(position));
    const mix = position - left;
    return source[left]! * (1 - mix) + source[right]! * mix;
  });
}

export function formatTrackTime(seconds: number | null | undefined) {
  if (!Number.isFinite(seconds) || !seconds || seconds <= 0) return '—';
  const whole = Math.floor(seconds);
  return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
}

export function waveformFraction(clientX: number, left: number, width: number) {
  if (!Number.isFinite(width) || width <= 0) return 0;
  return Math.max(0, Math.min(1, (clientX - left) / width));
}

export function waveformKeyboardFraction(
  key: string,
  current: number,
  step = 0.05,
) {
  if (key === 'Home') return 0;
  if (key === 'End') return 1;
  if (key === 'ArrowLeft' || key === 'ArrowDown') return Math.max(0, current - step);
  if (key === 'ArrowRight' || key === 'ArrowUp') return Math.min(1, current + step);
  return null;
}
