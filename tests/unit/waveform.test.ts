import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import {
  WAVEFORM_SAMPLE_COUNT,
  formatTrackTime,
  normalizeWaveform,
  waveformFraction,
  waveformKeyboardFraction,
} from '../../frontend/src/lib/media/waveform-model';

test('canonical 96-sample waveform metadata renders directly', () => {
  const peaks = Array.from({ length: WAVEFORM_SAMPLE_COUNT }, (_, index) => index / 95);
  assert.deepEqual(normalizeWaveform(peaks), peaks);
  assert.equal(normalizeWaveform(null), null);
  assert.deepEqual(normalizeWaveform(Array(WAVEFORM_SAMPLE_COUNT).fill(0)), Array(WAVEFORM_SAMPLE_COUNT).fill(0));
  const clamped = normalizeWaveform([2, -1, ...Array(94).fill(.5)]);
  assert.equal(clamped?.[0], 1);
  assert.equal(clamped?.[1], 0);
});

test('legacy peak arrays are deterministically projected to 96 display bars', () => {
  const legacy = Array.from({ length: 180 }, (_, index) => index / 179);
  const first = normalizeWaveform(legacy);
  const second = normalizeWaveform(legacy);
  assert.equal(first?.length, WAVEFORM_SAMPLE_COUNT);
  assert.deepEqual(first, second);
  assert.equal(first?.[0], 0);
  assert.equal(first?.at(-1), 1);
  assert.equal(normalizeWaveform([]), null);
});

test('pointer and keyboard seeking stay bounded and predictable', () => {
  assert.equal(waveformFraction(140, 100, 100), .4);
  assert.equal(waveformFraction(20, 100, 100), 0);
  assert.equal(waveformFraction(300, 100, 100), 1);
  assert.equal(waveformKeyboardFraction('ArrowRight', .4), .45);
  assert.equal(waveformKeyboardFraction('ArrowLeft', 0), 0);
  assert.equal(waveformKeyboardFraction('Home', .6), 0);
  assert.equal(waveformKeyboardFraction('End', .6), 1);
  assert.equal(waveformKeyboardFraction('Escape', .6), null);
});

test('track times are formatted from metadata without inventing a duration', () => {
  assert.equal(formatTrackTime(65.9), '1:05');
  assert.equal(formatTrackTime(null), '—');
  assert.equal(formatTrackTime(Number.NaN), '—');
});

test('waveform/player integration keeps one canonical row, one audio source, and responsive safeguards', async () => {
  const [trackList, waveform, app, styles, library, projectDetail] = await Promise.all([
    readFile(new URL('../../frontend/src/components/TrackList.astro', import.meta.url), 'utf8'),
    readFile(new URL('../../frontend/src/lib/media/waveform.ts', import.meta.url), 'utf8'),
    readFile(new URL('../../frontend/src/lib/app.ts', import.meta.url), 'utf8'),
    readFile(new URL('../../frontend/src/styles/feedback-04a.css', import.meta.url), 'utf8'),
    readFile(new URL('../../frontend/src/components/MusicLibrary.astro', import.meta.url), 'utf8'),
    readFile(new URL('../../frontend/src/components/ProjectDetail.astro', import.meta.url), 'utf8'),
  ]);
  assert.match(trackList, /data-waveform/);
  assert.match(trackList, /data-wave-played/);
  assert.match(trackList, /Waveform pending/);
  assert.doesNotMatch(waveform, /new Audio|<audio/);
  assert.match(waveform, /coordinator\.seek/);
  assert.match(app, /coordinator\.toggle/);
  assert.match(styles, /\.player\.is-idle[^}]+opacity:\s*\.28/s);
  assert.match(styles, /padding-inline-end:\s*72px/);
  assert.match(styles, /\.compact \.track-wave[^}]+display:\s*block/s);
  assert.match(styles, /grid-template-columns:\s*28px[^;]+48px 44px/s);
  assert.match(styles, /\.track-wave\[data-waveform\][^{]*\{[^}]*cursor:\s*pointer/s);
  assert.match(styles, /\[data-scrubbing\][^{]*\{[^}]*cursor:\s*grabbing/s);
  assert.match(trackList, /track-platform-slot/);
  assert.match(trackList, /No streaming platform/);
  assert.match(styles, /prefers-reduced-motion:reduce[^}]+\.player/s);
  for (const consumer of [library, projectDetail]) {
    assert.match(consumer, /import TrackList/);
    assert.match(consumer, /<TrackList/);
    assert.doesNotMatch(consumer, /class="track-row"/);
  }
});
