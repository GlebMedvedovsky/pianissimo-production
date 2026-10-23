import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import { canonicalWaveformSha256, peaksFromPcm, WAVEFORM_SAMPLES } from "./audio-analysis-worker.mjs";

test("worker creates the canonical deterministic 96-peak contract", () => {
  const pcm = Buffer.alloc(WAVEFORM_SAMPLES * 4);
  for (let index = 0; index < pcm.length / 2; index += 1) pcm.writeInt16LE(index % 2 ? 16000 : -8000, index * 2);
  const first = peaksFromPcm(pcm);
  const second = peaksFromPcm(pcm);
  assert.equal(first.length, 96);
  assert.deepEqual(first, second);
  assert.equal(Math.max(...first), 1);
  assert.match(canonicalWaveformSha256(first), /^[a-f0-9]{64}$/);
});

test("workflow has minimal immutable inputs and read-only permissions", async () => {
  const workflow = await readFile(new URL("../.github/workflows/pianissimo-audio-analysis.yml", import.meta.url), "utf8");
  assert.match(workflow, /permissions:\s*\n\s*contents: read/);
  for (const input of ["asset_id", "expected_audio_sha256", "analysis_job_id"]) assert.match(workflow, new RegExp(`${input}:`));
  assert.doesNotMatch(workflow, /contents: write|ssh|sftp/i);
});
