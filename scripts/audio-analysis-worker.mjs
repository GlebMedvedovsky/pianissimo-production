import { createHash } from "node:crypto";
import { createWriteStream } from "node:fs";
import { mkdtemp, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { execFileSync } from "node:child_process";
import { Readable, Transform } from "node:stream";
import { pipeline } from "node:stream/promises";
import { pathToFileURL } from "node:url";

export const WAVEFORM_SAMPLES = 96;

export function canonicalWaveformSha256(peaks) {
  if (!Array.isArray(peaks) || peaks.length !== WAVEFORM_SAMPLES) throw new Error("invalid peak count");
  return createHash("sha256").update(peaks.map((peak) => Number(peak).toFixed(6)).join(",")).digest("hex");
}

export function peaksFromPcm(pcm, bars = WAVEFORM_SAMPLES) {
  if (!Buffer.isBuffer(pcm) || pcm.length === 0 || pcm.length % 2 !== 0 || bars !== WAVEFORM_SAMPLES) throw new Error("invalid PCM");
  const sampleCount = pcm.length / 2;
  const peaks = Array(bars).fill(0);
  for (let index = 0; index < sampleCount; index += 1) {
    const value = Math.abs(pcm.readInt16LE(index * 2)) / 32768;
    const bucket = Math.min(bars - 1, Math.floor((index * bars) / sampleCount));
    peaks[bucket] = Math.max(peaks[bucket], value);
  }
  const maximum = Math.max(...peaks);
  return maximum === 0 ? peaks : peaks.map((peak) => Math.round((peak / maximum) * 1e6) / 1e6);
}

export function analyzeAudio(file) {
  const durationText = execFileSync("ffprobe", ["-v", "error", "-select_streams", "a:0", "-show_entries", "format=duration", "-of", "default=noprint_wrappers=1:nokey=1", file], { encoding: "utf8", timeout: 120000 }).trim();
  const duration = Math.round(Number(durationText) * 1e6) / 1e6;
  if (!Number.isFinite(duration) || duration <= 0 || duration > 86400) throw new Error("invalid duration");
  const pcm = execFileSync("ffmpeg", ["-v", "error", "-nostdin", "-i", file, "-map", "0:a:0", "-ac", "1", "-ar", "8000", "-f", "s16le", "pipe:1"], { encoding: "buffer", timeout: 120000, maxBuffer: 512 * 1024 * 1024 });
  const peaks = peaksFromPcm(pcm);
  return { duration, peaks, waveformSha256: canonicalWaveformSha256(peaks) };
}

async function download(url, token, target, expectedSha) {
  const response = await fetch(url, { headers: { Authorization: `Bearer ${token}`, Accept: "application/octet-stream" } });
  if (!response.ok || !response.body) throw new Error(`source request failed (${response.status})`);
  const length = Number(response.headers.get("content-length"));
  if (!Number.isSafeInteger(length) || length < 1 || length > 512 * 1024 * 1024) throw new Error("invalid source length");
  const hash = createHash("sha256");
  let bytes = 0;
  const verifier = new Transform({ transform(chunk, _encoding, callback) { bytes += chunk.length; hash.update(chunk); callback(null, chunk); } });
  await pipeline(Readable.fromWeb(response.body), verifier, createWriteStream(target, { flags: "wx", mode: 0o600 }));
  const actualSha = hash.digest("hex");
  if (bytes !== length || actualSha !== expectedSha) throw new Error("source identity mismatch");
}

async function postResult(url, token, payload) {
  const response = await fetch(url, { method: "POST", headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify(payload) });
  if (!response.ok) throw new Error(`result request failed (${response.status})`);
}

export async function runWorker({ baseUrl, token, assetId, expectedSha, jobId }) {
  if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(assetId) || !/^[a-f0-9]{64}$/.test(expectedSha) || !/^[a-f0-9]{32}$/.test(jobId)) throw new Error("invalid immutable identity");
  const origin = new URL(baseUrl);
  if (origin.protocol !== "https:" || origin.username || origin.password || origin.search || origin.hash) throw new Error("invalid CMS origin");
  const root = origin.href.replace(/\/$/, "");
  const endpoint = `${root}/api/audio-analysis/v1/assets/${encodeURIComponent(assetId)}`;
  const temporary = await mkdtemp(join(tmpdir(), "pianissimo-audio-"));
  try {
    const source = join(temporary, "source.audio");
    await download(`${endpoint}/source?expectedSha=${expectedSha}&analysisJobId=${jobId}`, token, source, expectedSha);
    const metadata = analyzeAudio(source);
    await postResult(`${endpoint}/result`, token, { status: "success", assetId, audioSha256: expectedSha, analysisJobId: jobId, ...metadata });
  } catch (error) {
    try { await postResult(`${endpoint}/result`, token, { status: "failed", assetId, audioSha256: expectedSha, analysisJobId: jobId, failureCode: "worker_failed" }); } catch {}
    throw error;
  } finally {
    await rm(temporary, { recursive: true, force: true });
  }
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? "").href) {
  const [assetId, expectedSha, jobId] = process.argv.slice(2);
  await runWorker({ baseUrl: process.env.AUDIO_ANALYSIS_CMS_BASE_URL, token: process.env.AUDIO_ANALYSIS_API_TOKEN, assetId, expectedSha, jobId });
}
