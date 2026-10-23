import test from "node:test";
import assert from "node:assert/strict";
import { mkdir, mkdtemp, readFile, rm, symlink, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import releaseFixture from "../../backend/tests/Fixtures/valid-release-manifest.json";
import { canonicalJson, sha256, type PublicationMedia } from "../../scripts/publication-package";
import {
  runSyncProductionContentCli,
  syncProductionContent,
  type SyncProductionContentOptions,
} from "../../scripts/sync-production-content";

const buildRoot = resolve(".local-builds");
const token = "content-sync-test-token-00000000000000000000";
const env = {
  CMS_CONTENT_SYNC_URL: "https://cms.example.test",
  CMS_CONTENT_SYNC_API_TOKEN: token,
};

type Generation = {
  active: Record<string, unknown>;
  publication: Record<string, unknown>;
  publicationBytes: string;
  manifestBytes: string;
  mediaBytes: Map<string, Buffer>;
};

function generation(sequence: number, contents: Buffer[]): Generation {
  const publicationId = `12345678-1234-4234-8234-${String(sequence).padStart(12, "0")}`;
  const paths = contents.map((_, index) => index === 0 ? "media/release-image.webp" : `media/secondary-${index}.webp`);
  const assets = contents.map((bytes, index) => ({
    ...structuredClone(releaseFixture.assets[0]),
    id: index === 0 ? "release-image" : `secondary-${index}`,
    path: paths[index]!,
    sha256: sha256(bytes),
  }));
  const manifest = structuredClone(releaseFixture) as any;
  manifest.assets = assets;
  manifest.site.mediaSlots.hero.image = "release-image";
  const manifestBytes = `${canonicalJson(manifest)}\n`;
  const manifestSha256 = sha256(manifestBytes);
  const media: PublicationMedia[] = contents.map((bytes, index) => ({
    assetId: assets[index]!.id,
    publicPath: paths[index]!,
    sha256: sha256(bytes),
    size: bytes.length,
    type: "image",
  }));
  const sourceRevision = sequence.toString(16).slice(-1).repeat(40);
  const candidateHash = sha256(`candidate-${sequence}`);
  const planHash = sha256(`plan-${sequence}`);
  const packageSha256 = sha256(canonicalJson({
    contractVersion: 1,
    candidateHash,
    planHash,
    sourceRevision,
    manifestSha256,
    media,
  }));
  const publication = {
    candidateHash,
    contractVersion: 1,
    createdAt: `2026-10-${String(sequence).padStart(2, "0")}T12:00:00Z`,
    manifestSha256,
    media,
    packageSha256,
    planHash,
    publicationId,
    sequence,
    sourceRevision,
  };
  const active = {
    contractVersion: 1,
    publicationId,
    sequence,
    activatedAt: `2026-10-${String(sequence).padStart(2, "0")}T12:05:00Z`,
    sourceRevision,
    packageSha256,
    manifestSha256,
    releaseSha256: sha256(`release-${sequence}`),
    publicationUrl: `/api/content-sync/v1/publications/${publicationId}/publication.json`,
    manifestUrl: `/api/content-sync/v1/publications/${publicationId}/manifest.json`,
    mediaUrlTemplate: `/api/content-sync/v1/publications/${publicationId}/media/{sha256}`,
  };
  return {
    active,
    publication,
    publicationBytes: `${canonicalJson(publication)}\n`,
    manifestBytes,
    mediaBytes: new Map(media.map((entry, index) => [entry.sha256, contents[index]!])),
  };
}

function mockApi(
  generations: Generation[],
  activePlan: Generation[] = [generations[0]!],
  mediaResponse?: (sha: string, bytes: Buffer) => Response,
) {
  let discovery = 0;
  const downloads = new Map<string, number>();
  const byId = new Map(generations.map((item) => [item.active.publicationId, item]));
  const fetchMock: typeof fetch = async (input) => {
    const url = new URL(String(input));
    if (url.pathname.endsWith("/production/active")) {
      const current = activePlan[Math.min(discovery, activePlan.length - 1)]!;
      discovery += 1;
      return Response.json(current.active);
    }
    const publicationMatch = /\/publications\/([^/]+)\/(publication\.json|manifest\.json)$/.exec(url.pathname);
    if (publicationMatch) {
      const current = byId.get(publicationMatch[1]!);
      if (!current) return new Response(null, { status: 404 });
      const bytes = publicationMatch[2] === "publication.json" ? current.publicationBytes : current.manifestBytes;
      return new Response(bytes, { headers: { "Content-Type": "application/json" } });
    }
    const mediaMatch = /\/publications\/[^/]+\/media\/([a-f0-9]{64})$/.exec(url.pathname);
    if (mediaMatch) {
      const digest = mediaMatch[1]!;
      const bytes = generations.map((item) => item.mediaBytes.get(digest)).find(Boolean);
      if (!bytes) return new Response(null, { status: 404 });
      downloads.set(digest, (downloads.get(digest) ?? 0) + 1);
      if (mediaResponse) return mediaResponse(digest, bytes);
      return new Response(new Uint8Array(bytes), { headers: {
        "Content-Type": "image/webp",
        "Content-Length": String(bytes.length),
        ETag: `"${digest}"`,
      } });
    }
    return new Response(null, { status: 404 });
  };
  return { fetchMock, downloads, discoveries: () => discovery };
}

async function withRoot(run: (root: string) => Promise<void>) {
  await mkdir(buildRoot, { recursive: true });
  const root = await mkdtemp(resolve(buildRoot, "content-sync-test-"));
  try { await run(root); } finally {
    await rm(root, { recursive: true, force: true, maxRetries: 5, retryDelay: 50 });
  }
}

const options = (root: string, fetchMock: typeof fetch, overrides: Partial<SyncProductionContentOptions> = {}) => ({
  repoRoot: root,
  storeRoot: resolve(root, ".local-content"),
  env,
  fetch: fetchMock,
  getGitHead: async () => "1".repeat(40),
  ...overrides,
});

test("first sync downloads all media", async () => withRoot(async (root) => {
  const current = generation(1, [Buffer.from("first-image"), Buffer.from("second-image")]);
  const api = mockApi([current]);
  const result = await syncProductionContent(options(root, api.fetchMock));
  assert.equal(result.downloadedMedia, 2);
  assert.equal(result.reusedMedia, 0);
  assert.equal([...api.downloads.values()].reduce((sum, value) => sum + value, 0), 2);
  assert.equal(JSON.parse(await readFile(resolve(root, ".local-content/active.json"), "utf8")).packageSha256, current.active.packageSha256);
}));

test("second identical sync downloads zero media", async () => withRoot(async (root) => {
  const current = generation(1, [Buffer.from("first-image"), Buffer.from("second-image")]);
  const first = mockApi([current]);
  await syncProductionContent(options(root, first.fetchMock));
  const second = mockApi([current]);
  const result = await syncProductionContent(options(root, second.fetchMock));
  assert.equal(result.downloadedMedia, 0);
  assert.equal(result.reusedMedia, 2);
  assert.equal(second.downloads.size, 0);
}));

test("one changed media hash downloads only one blob", async () => withRoot(async (root) => {
  const first = generation(1, [Buffer.from("same-image"), Buffer.from("old-second")]);
  const second = generation(2, [Buffer.from("same-image"), Buffer.from("new-second")]);
  await syncProductionContent(options(root, mockApi([first]).fetchMock));
  const api = mockApi([second]);
  const result = await syncProductionContent(options(root, api.fetchMock));
  assert.deepEqual([result.reusedMedia, result.downloadedMedia], [1, 1]);
  assert.equal(api.downloads.size, 1);
}));

test("corrupt cached blob is re-downloaded", async () => withRoot(async (root) => {
  const current = generation(1, [Buffer.from("cache-integrity")]);
  await syncProductionContent(options(root, mockApi([current]).fetchMock));
  const digest = (current.publication.media as PublicationMedia[])[0]!.sha256;
  await writeFile(resolve(root, ".local-content/blobs/sha256", digest), "corrupt-cache");
  const api = mockApi([current]);
  const result = await syncProductionContent(options(root, api.fetchMock));
  assert.equal(result.downloadedMedia, 1);
  assert.equal(sha256(await readFile(resolve(root, ".local-content/blobs/sha256", digest))), digest);
}));

test("interrupted media download leaves previous active snapshot untouched", async () => withRoot(async (root) => {
  const first = generation(1, [Buffer.from("stable-media")]);
  const second = generation(2, [Buffer.from("interrupted-media")]);
  await syncProductionContent(options(root, mockApi([first]).fetchMock));
  const before = await readFile(resolve(root, ".local-content/active.json"), "utf8");
  const api = mockApi([second], [second], (_sha, bytes) => new Response(new ReadableStream({
    start(controller) {
      controller.enqueue(bytes.subarray(0, 2));
      controller.error(new Error("interrupted"));
    },
  }), { headers: { "Content-Type": "image/webp", "Content-Length": String(bytes.length) } }));
  await assert.rejects(syncProductionContent(options(root, api.fetchMock)));
  assert.equal(await readFile(resolve(root, ".local-content/active.json"), "utf8"), before);
}));

test("downloaded media hash mismatch fails closed", async () => withRoot(async (root) => {
  const current = generation(1, [Buffer.from("expected-bytes")]);
  const api = mockApi([current], [current], (digest, bytes) => new Response(new Uint8Array(Buffer.alloc(bytes.length, 120)), { headers: {
    "Content-Type": "image/webp", "Content-Length": String(bytes.length), ETag: `"${digest}"`,
  } }));
  await assert.rejects(syncProductionContent(options(root, api.fetchMock)), /SHA-256 verification/);
  await assert.rejects(readFile(resolve(root, ".local-content/active.json")));
}));

test("active publication changing mid-sync triggers one bounded retry", async () => withRoot(async (root) => {
  const first = generation(1, [Buffer.from("first")]);
  const second = generation(2, [Buffer.from("second")]);
  const api = mockApi([first, second], [first, second, second, second]);
  const result = await syncProductionContent(options(root, api.fetchMock));
  assert.equal(result.publicationId, second.active.publicationId);
  assert.equal(api.discoveries(), 4);
}));

test("second active change during retry fails without replacing active snapshot", async () => withRoot(async (root) => {
  const baseline = generation(1, [Buffer.from("baseline")]);
  const first = generation(2, [Buffer.from("first-change")]);
  const second = generation(3, [Buffer.from("second-change")]);
  const third = generation(4, [Buffer.from("third-change")]);
  await syncProductionContent(options(root, mockApi([baseline]).fetchMock));
  const before = await readFile(resolve(root, ".local-content/active.json"), "utf8");
  const api = mockApi([first, second, third], [first, second, second, third]);
  await assert.rejects(syncProductionContent(options(root, api.fetchMock)), /changed repeatedly/);
  assert.equal(await readFile(resolve(root, ".local-content/active.json"), "utf8"), before);
  assert.equal(api.discoveries(), 4);
}));

test("duplicate media paths fail closed", async () => withRoot(async (root) => {
  const current = generation(1, [Buffer.from("one"), Buffer.from("two")]);
  const media = current.publication.media as PublicationMedia[];
  media[1]!.publicPath = media[0]!.publicPath;
  current.publicationBytes = `${canonicalJson(current.publication)}\n`;
  await assert.rejects(syncProductionContent(options(root, mockApi([current]).fetchMock)), /duplicate media paths|uniquely sorted/);
}));

test("media path traversal fails closed", async () => withRoot(async (root) => {
  const current = generation(1, [Buffer.from("one")]);
  (current.publication.media as PublicationMedia[])[0]!.publicPath = "media/../escape.webp";
  current.publicationBytes = `${canonicalJson(current.publication)}\n`;
  await assert.rejects(syncProductionContent(options(root, mockApi([current]).fetchMock)), /path is unsafe/);
}));

test("symlinked local-content root fails closed", async (context) => withRoot(async (root) => {
  const target = resolve(root, "target");
  const store = resolve(root, ".local-content");
  await mkdir(target);
  try { await symlink(target, store, "junction"); } catch { context.skip("Symlink creation is unavailable on this host."); return; }
  const current = generation(1, [Buffer.from("one")]);
  await assert.rejects(syncProductionContent(options(root, mockApi([current]).fetchMock)), /root may not be a symlink/);
}));

test("dirty Git worktree files remain untouched", async () => withRoot(async (root) => {
  const tracked = resolve(root, "developer-work.ts");
  await writeFile(tracked, "uncommitted developer change\n");
  const current = generation(1, [Buffer.from("one")]);
  await syncProductionContent(options(root, mockApi([current]).fetchMock));
  assert.equal(await readFile(tracked, "utf8"), "uncommitted developer change\n");
}));

test("source revision mismatch warns but does not fail", async () => withRoot(async (root) => {
  const current = generation(1, [Buffer.from("one")]);
  const output: string[] = [];
  const code = await runSyncProductionContentCli(
    options(root, mockApi([current]).fetchMock, { getGitHead: async () => "f".repeat(40) }),
    { log: (line) => output.push(line), warn: (line) => output.push(line), error: (line) => output.push(line) },
  );
  assert.equal(code, 0);
  assert.match(output.join("\n"), /differs from the publication source revision/);
  assert.equal(JSON.parse(await readFile(resolve(root, ".local-content/active.json"), "utf8")).publicationId, current.active.publicationId);
}));

test("token is never printed in normal or failure output", async () => withRoot(async (root) => {
  const current = generation(1, [Buffer.from("one")]);
  const lines: string[] = [];
  const output = { log: (line: string) => lines.push(line), warn: (line: string) => lines.push(line), error: (line: string) => lines.push(line) };
  assert.equal(await runSyncProductionContentCli(options(root, mockApi([current]).fetchMock), output), 0);
  const failedRoot = resolve(root, "failure");
  await mkdir(failedRoot);
  const leakingFetch: typeof fetch = async () => { throw new Error(`network ${token}`); };
  assert.equal(await runSyncProductionContentCli(options(failedRoot, leakingFetch), output), 1);
  assert.doesNotMatch(lines.join("\n"), new RegExp(token));
}));
