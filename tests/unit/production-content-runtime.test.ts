import test from "node:test";
import assert from "node:assert/strict";
import { createServer } from "node:http";
import { mkdir, mkdtemp, readFile, rm, symlink, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import releaseFixture from "../../backend/tests/Fixtures/valid-release-manifest.json";
import { buildProductionContent } from "../../scripts/build-production-content";
import { runProductionContentDev } from "../../scripts/dev-production-content";
import { createProductionContentMediaMiddleware } from "../../scripts/production-content-media.mjs";
import { canonicalJson, sha256 } from "../../scripts/publication-package";

const buildRoot = resolve(".local-builds");

async function createSnapshot(root: string, mediaBytes = Buffer.from("synchronized-production-media")) {
  const storeRoot = resolve(root, ".local-content");
  const manifest = structuredClone(releaseFixture) as any;
  const mediaSha256 = sha256(mediaBytes);
  manifest.assets[0]!.path = "media/release-image.webp";
  manifest.assets[0]!.sha256 = mediaSha256;
  manifest.site.mediaSlots.hero.image = "release-image";
  const manifestBytes = `${canonicalJson(manifest)}\n`;
  const manifestSha256 = sha256(manifestBytes);
  const media = [{
    assetId: "release-image",
    publicPath: "media/release-image.webp",
    sha256: mediaSha256,
    size: mediaBytes.length,
    type: "image" as const,
  }];
  const sourceRevision = "1".repeat(40);
  const candidateHash = sha256("runtime-candidate");
  const planHash = sha256("runtime-plan");
  const packageSha256 = sha256(canonicalJson({
    contractVersion: 1,
    candidateHash,
    planHash,
    sourceRevision,
    manifestSha256,
    media,
  }));
  const publicationId = "12345678-1234-4234-8234-123456789abc";
  const packageRoot = resolve(storeRoot, "packages", packageSha256);
  await mkdir(resolve(packageRoot, "media"), { recursive: true });
  await mkdir(resolve(storeRoot, "blobs", "sha256"), { recursive: true });
  await mkdir(resolve(storeRoot, "staging"), { recursive: true });
  await writeFile(resolve(packageRoot, "manifest.json"), manifestBytes);
  await writeFile(resolve(packageRoot, "publication.json"), `${canonicalJson({
    candidateHash,
    contractVersion: 1,
    createdAt: "2026-10-10T12:00:00Z",
    manifestSha256,
    media,
    packageSha256,
    planHash,
    publicationId,
    sequence: 10,
    sourceRevision,
  })}\n`);
  await writeFile(resolve(packageRoot, "media", "release-image.webp"), mediaBytes);
  const active = {
    contractVersion: 1,
    publicationId,
    sequence: 10,
    activatedAt: "2026-10-10T12:05:00Z",
    sourceRevision,
    packageSha256,
    manifestSha256,
    releaseSha256: sha256("release"),
    packagePath: `packages/${packageSha256}`,
  };
  await writeFile(resolve(storeRoot, "active.json"), `${JSON.stringify(active, null, 2)}\n`);
  return { storeRoot, packageRoot, manifestBytes, mediaBytes, active };
}

async function withRoot(run: (root: string) => Promise<void>) {
  await mkdir(buildRoot, { recursive: true });
  const root = await mkdtemp(resolve(buildRoot, "production-runtime-test-"));
  try { await run(root); } finally {
    await rm(root, { recursive: true, force: true, maxRetries: 5, retryDelay: 50 });
  }
}

const quiet = { log() {}, warn() {}, error() {} };

test("valid synchronized package starts production-content dev mode", async () => withRoot(async (root) => {
  const snapshot = await createSnapshot(root);
  let invocation: any;
  const status = await runProductionContentDev({
    repoRoot: root,
    storeRoot: snapshot.storeRoot,
    args: [],
    output: quiet,
    getGitHead: async () => snapshot.active.sourceRevision,
    spawn: ((command: any, args: any, options: any) => { invocation = { command, args, options }; return { status: 0 }; }) as any,
  });
  assert.equal(status, 0);
  assert.equal(invocation.options.env.PIANISSIMO_PRODUCTION_CONTENT, "1");
  assert.deepEqual(invocation.args.slice(-1), ["dev"]);
}));

test("production-content dev uses synchronized manifest instead of repository fixture", async () => withRoot(async (root) => {
  const snapshot = await createSnapshot(root);
  let manifestPath = "";
  await runProductionContentDev({
    repoRoot: root,
    storeRoot: snapshot.storeRoot,
    args: [],
    output: quiet,
    getGitHead: async () => snapshot.active.sourceRevision,
    spawn: ((_command: any, _args: any, options: any) => {
      manifestPath = String(options.env.PIANISSIMO_MANIFEST_PATH);
      return { status: 0 };
    }) as any,
  });
  assert.equal(resolve(manifestPath), resolve(snapshot.packageRoot, "manifest.json"));
  assert.notEqual(resolve(manifestPath), resolve(root, "content", "manifest.json"));
  assert.equal(await readFile(manifestPath, "utf8"), snapshot.manifestBytes);
}));

async function requestMedia(mediaRoot: string, path: string, init?: RequestInit) {
  const middleware = createProductionContentMediaMiddleware(mediaRoot);
  const server = createServer((request, response) => middleware(request, response, () => {
    response.writeHead(599).end("repository fallback");
  }));
  await new Promise<void>((resolvePromise) => server.listen(0, "127.0.0.1", resolvePromise));
  try {
    const address = server.address();
    assert.ok(address && typeof address === "object");
    return await fetch(`http://127.0.0.1:${address.port}${path}`, init);
  } finally {
    await new Promise<void>((resolvePromise, reject) => server.close((error) => error ? reject(error) : resolvePromise()));
  }
}

test("synchronized media resolves at the manifest URL", async () => withRoot(async (root) => {
  const snapshot = await createSnapshot(root);
  const response = await requestMedia(resolve(snapshot.packageRoot, "media"), "/media/release-image.webp");
  assert.equal(response.status, 200);
  assert.deepEqual(Buffer.from(await response.arrayBuffer()), snapshot.mediaBytes);
  const partial = await requestMedia(resolve(snapshot.packageRoot, "media"), "/media/release-image.webp", {
    headers: { Range: "bytes=1-4" },
  });
  assert.equal(partial.status, 206);
  assert.deepEqual(Buffer.from(await partial.arrayBuffer()), snapshot.mediaBytes.subarray(1, 5));
}));

test("missing synchronized media fails without repository fallback", async () => withRoot(async (root) => {
  const snapshot = await createSnapshot(root);
  const response = await requestMedia(resolve(snapshot.packageRoot, "media"), "/media/not-synchronized.webp");
  assert.equal(response.status, 404);
  assert.notEqual(await response.text(), "repository fallback");
}));

test("corrupt active.json and corrupt packages fail closed", async () => withRoot(async (root) => {
  const snapshot = await createSnapshot(root);
  await writeFile(resolve(snapshot.storeRoot, "active.json"), "{not-json");
  await assert.rejects(runProductionContentDev({
    repoRoot: root, storeRoot: snapshot.storeRoot, args: [], output: quiet,
    getGitHead: async () => snapshot.active.sourceRevision,
    spawn: (() => ({ status: 0 })) as any,
  }), /active\.json is malformed/);

  const restored = await createSnapshot(root);
  await writeFile(resolve(restored.packageRoot, "manifest.json"), "{}\n");
  await assert.rejects(buildProductionContent({
    repoRoot: root, storeRoot: restored.storeRoot, output: quiet,
    build: async () => "unreachable",
  }), /manifest SHA-256 mismatch/);
}));

test("symlinked local-content store fails closed", async (context) => withRoot(async (root) => {
  const targetRoot = resolve(root, "target");
  const snapshot = await createSnapshot(targetRoot);
  const linkedStore = resolve(root, ".local-content");
  try { await symlink(snapshot.storeRoot, linkedStore, "junction"); } catch {
    context.skip("Symlink creation is unavailable on this host.");
    return;
  }
  await assert.rejects(runProductionContentDev({
    repoRoot: root, storeRoot: linkedStore, args: [], output: quiet,
    getGitHead: async () => snapshot.active.sourceRevision,
    spawn: (() => ({ status: 0 })) as any,
  }), /missing or unsafe/);
}));

test("revision mismatch warns and still starts", async () => withRoot(async (root) => {
  const snapshot = await createSnapshot(root);
  const warnings: string[] = [];
  let started = false;
  const status = await runProductionContentDev({
    repoRoot: root, storeRoot: snapshot.storeRoot, args: [],
    output: { log() {}, error() {}, warn: (message) => warnings.push(message) },
    getGitHead: async () => "f".repeat(40),
    spawn: (() => { started = true; return { status: 0 }; }) as any,
  });
  assert.equal(status, 0);
  assert.equal(started, true);
  assert.match(warnings.join("\n"), /differs/);
}));

test("strict revision mode fails before starting on mismatch", async () => withRoot(async (root) => {
  const snapshot = await createSnapshot(root);
  let started = false;
  await assert.rejects(runProductionContentDev({
    repoRoot: root, storeRoot: snapshot.storeRoot, args: ["--require-source-revision"], output: quiet,
    getGitHead: async () => "f".repeat(40),
    spawn: (() => { started = true; return { status: 0 }; }) as any,
  }), /does not match/);
  assert.equal(started, false);
}));

test("repository npm run dev remains fixture-backed and unchanged", async () => {
  const packageJson = JSON.parse(await readFile("package.json", "utf8"));
  const config = await readFile("frontend/astro.config.mjs", "utf8");
  assert.equal(packageJson.scripts.dev, "npm run dev --workspace frontend");
  assert.match(config, /: localPath\("\.\.\/content\/manifest\.json"\)/);
  assert.match(config, /const productionContent = process\.env\.PIANISSIMO_PRODUCTION_CONTENT === "1"/);
});

test("build:production-content passes the exact synchronized package to the isolated builder", async () => withRoot(async (root) => {
  const snapshot = await createSnapshot(root);
  let packagePath = "";
  const result = await buildProductionContent({
    repoRoot: root,
    storeRoot: snapshot.storeRoot,
    outputPath: resolve(root, ".local-builds", "output"),
    output: quiet,
    build: async (input, output) => { packagePath = input; return output; },
  });
  assert.equal(resolve(packagePath), resolve(snapshot.packageRoot));
  assert.equal(result.active.packageSha256, snapshot.active.packageSha256);
}));

test("production-content dev and build do not modify tracked files", async () => withRoot(async (root) => {
  const tracked = resolve(root, "developer-change.ts");
  await writeFile(tracked, "uncommitted work\n");
  const snapshot = await createSnapshot(root);
  await runProductionContentDev({
    repoRoot: root, storeRoot: snapshot.storeRoot, args: [], output: quiet,
    getGitHead: async () => snapshot.active.sourceRevision,
    spawn: (() => ({ status: 0 })) as any,
  });
  await buildProductionContent({
    repoRoot: root, storeRoot: snapshot.storeRoot, outputPath: resolve(root, ".local-builds", "output"), output: quiet,
    build: async (_input, output) => output,
  });
  assert.equal(await readFile(tracked, "utf8"), "uncommitted work\n");
}));
