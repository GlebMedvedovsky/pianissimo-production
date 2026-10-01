import test from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { createHash } from "node:crypto";
import { mkdir, mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { parse } from "yaml";
import { canonicalJson, sha256 } from "../../scripts/publication-package";

const workflowPath = resolve(".github/workflows/pianissimo-production-publisher.yml");
const extractorPath = resolve("scripts/extract-publication-transport.mjs");
const releaseIntegrityPath = resolve("scripts/create-release-integrity.mjs");
const buildRoot = resolve(".local-builds");

function octal(value: number, length: number) {
  return `${value.toString(8).padStart(length - 1, "0")}\0`;
}

function tarEntry(name: string, bytes: Buffer, type = "0") {
  const header = Buffer.alloc(512);
  header.write(name, 0, 100, "utf8");
  header.write(octal(0o644, 8), 100, 8, "ascii");
  header.write(octal(0, 8), 108, 8, "ascii");
  header.write(octal(0, 8), 116, 8, "ascii");
  header.write(octal(bytes.length, 12), 124, 12, "ascii");
  header.write(octal(0, 12), 136, 12, "ascii");
  header.fill(0x20, 148, 156);
  header.write(type, 156, 1, "ascii");
  header.write("ustar\0", 257, 6, "binary");
  header.write("00", 263, 2, "ascii");
  const checksum = header.reduce((sum, byte) => sum + byte, 0);
  header.write(`${checksum.toString(8).padStart(6, "0")}\0 `, 148, 8, "ascii");
  const padding = Buffer.alloc((512 - (bytes.length % 512)) % 512);
  return Buffer.concat([header, bytes, padding]);
}

function archive(entries: Array<[string, Buffer, string?]>) {
  return Buffer.concat([...entries.map(([name, bytes, type]) => tarEntry(name, bytes, type)), Buffer.alloc(1024)]);
}

function runExtractor(args: readonly string[]) {
  return spawnSync(process.execPath, [extractorPath, ...args], { encoding: "utf8" });
}

function workflowJob(workflow: string) {
  const document = parse(workflow) as { on: { workflow_dispatch: { inputs: Record<string, unknown> } }; jobs: { build: { env: Record<string, unknown>; steps: Array<Record<string, unknown>> } } };
  assert.ok(document.jobs?.build?.env && Array.isArray(document.jobs.build.steps));
  return { document, job: document.jobs.build };
}

test("production workflow is gated, pinned, read-only, isolated, and transfers through strict SSH", async () => {
  const workflow = await readFile(workflowPath, "utf8");
  const { document } = workflowJob(workflow);
  assert.deepEqual(Object.keys(document.on.workflow_dispatch.inputs), ["publication_id", "sequence", "package_sha256", "source_revision"]);
  for (const input of ["publication_id", "sequence", "package_sha256", "source_revision"])
    assert.match(workflow, new RegExp(`^      ${input}:`, "m"));
  assert.match(workflow, /PIANISSIMO_PRODUCTION_WORKFLOW_ENABLED/);
  assert.match(workflow, /test "\$WORKFLOW_ENABLED" = "true"/);
  assert.match(workflow, /^permissions:\n  contents: read$/m);
  assert.match(workflow, /group: pianissimo-production\n  cancel-in-progress: false/);
  assert.match(workflow, /actions\/checkout@[0-9a-f]{40}/);
  assert.match(workflow, /actions\/setup-node@[0-9a-f]{40}/);
  assert.doesNotMatch(workflow, /uses:\s*[^\n]+@(v\d+|main|master)\b/);
  assert.match(workflow, /ref: \$\{\{ inputs\.source_revision \}\}/);
  assert.match(workflow, /test "\$\(git rev-parse HEAD\)" = "\$SOURCE_REVISION"/);
  assert.match(workflow, /node-version: "22"/);
  assert.match(workflow, /npm ci/);
  assert.match(workflow, /npm run build:publication -- --package "\$PACKAGE_DIR" --out "\$OUTPUT_DIR"/);
  assert.match(workflow, /X-Pianissimo-Transport-Sha256/);
  assert.match(workflow, /sha256sum "\$PACKAGE_ARCHIVE"/);
  assert.match(workflow, /status:"built"/);
  assert.match(workflow, /status:"uploading"/);
  assert.match(workflow, /status:"uploaded"/);
  assert.match(workflow, /CLAIM_RUNNER_ID: github-\$\{\{ github\.run_id \}\}/);
  assert.match(workflow, /ATTEMPT_ID: github-\$\{\{ github\.run_id \}\}-\$\{\{ github\.run_attempt \}\}/);
  assert.doesNotMatch(workflow, /CLAIM_RUNNER_ID:[^\n]*run_attempt/);
  for (const output of ["build_required", "upload_required", "activation_required", "notify_building", "notify_built", "notify_uploading"])
    assert.match(workflow, new RegExp(`steps\\.claim\\.outputs\\.${output}`));
  assert.match(workflow, /create-release-integrity\.mjs/);
  assert.match(workflow, /release_files_sha256/);
  assert.match(workflow, /release_metadata_sha256/);
  assert.match(workflow, /sha256sum "\$OUTPUT_DIR\/\.pianissimo-release\.json"/);
  assert.match(workflow, /if: always\(\)/);
  assert.match(workflow, /\bscp\b/);
  assert.match(workflow, /pianissimo:production:activate/);
  assert.doesNotMatch(workflow, /echo\s+[^\n]*PUBLISHER_TOKEN/);
});

test("release integrity inventory is deterministic and covers every public file", async () => {
  await mkdir(buildRoot, { recursive: true });
  const root = await mkdtemp(resolve(buildRoot, "release-integrity-"));
  try {
    const output = resolve(root, "output");
    await mkdir(resolve(output, "_astro"), { recursive: true });
    await writeFile(resolve(output, "index.html"), "<h1>release</h1>");
    await writeFile(resolve(output, "_astro", "app.js"), "console.log('release')");
    await writeFile(resolve(output, "_astro", "app.css"), "body{color:#111}");
    const publicationPath = resolve(root, "publication.json");
    await writeFile(publicationPath, JSON.stringify({
      publicationId: "12345678-1234-4234-8234-123456789abc",
      sequence: 1,
      sourceRevision: "1".repeat(40),
      packageSha256: "2".repeat(64),
      manifestSha256: "3".repeat(64),
      media: [],
    }));

    const generated = spawnSync(process.execPath, [releaseIntegrityPath, output, publicationPath], { encoding: "utf8" });
    assert.equal(generated.status, 0, generated.stderr);
    const inventoryBytes = await readFile(resolve(output, ".pianissimo-release-files.json"), "utf8");
    const inventory = JSON.parse(inventoryBytes);
    assert.deepEqual(inventory.map((entry: { path: string }) => entry.path), ["_astro/app.css", "_astro/app.js", "index.html"]);
    const inventorySha = sha256(inventoryBytes);
    assert.equal(generated.stdout.trim(), inventorySha);
    const metadataBytes = await readFile(resolve(output, ".pianissimo-release.json"), "utf8");
    const metadata = JSON.parse(metadataBytes);
    assert.equal(metadata.releaseFilesSha256, inventorySha);
    assert.deepEqual(Object.keys(metadata), [
      "releaseContractVersion",
      "publicationId",
      "sequence",
      "sourceRevision",
      "packageSha256",
      "manifestSha256",
      "releaseFilesSha256",
      "media",
    ]);
    assert.equal(metadataBytes, `${JSON.stringify(metadata)}\n`);
    assert.equal(sha256(metadataBytes).length, 64);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("production workflow pins host identity, confines partial cleanup, and uses repository configuration", async () => {
  const workflow = await readFile(workflowPath, "utf8");
  for (const secret of [
    "PIANISSIMO_PUBLISHER_API_TOKEN",
    "NETCUP_PRODUCTION_SSH_PRIVATE_KEY",
    "NETCUP_SSH_KNOWN_HOSTS",
  ]) assert.match(workflow, new RegExp(`secrets\\.${secret}`));
  for (const variable of [
    "PIANISSIMO_CMS_URL",
    "PIANISSIMO_PRODUCTION_WORKFLOW_ENABLED",
    "NETCUP_SSH_HOST",
    "NETCUP_SSH_USER",
    "NETCUP_SSH_PORT",
    "NETCUP_PIANISSIMO_PRODUCTION_INCOMING_ROOT",
    "NETCUP_PIANISSIMO_BACKEND_ROOT",
    "NETCUP_PHP_BIN",
  ]) assert.match(workflow, new RegExp(`vars\\.${variable}`));
  for (const option of ["BatchMode=yes", "IdentitiesOnly=yes", "StrictHostKeyChecking=yes", "UserKnownHostsFile="])
    assert.match(workflow, new RegExp(option));
  assert.doesNotMatch(workflow, /ssh-keyscan/);
  assert.doesNotMatch(workflow, /StrictHostKeyChecking=no/);
  assert.doesNotMatch(workflow, /hosting213834|a2e58\.netcup\.net|\/usr\/local\/php84/);
  assert.match(workflow, /rm -f -- "\$root\/\$part"/);
  assert.doesNotMatch(workflow, /rm -rf[^\n]*REMOTE_INCOMING_ROOT/);
  assert.doesNotMatch(workflow, /echo\s+[^\n]*(?:SSH_PRIVATE_KEY_MATERIAL|SSH_KNOWN_HOSTS_MATERIAL)/);
});

test("production temporary paths are initialized only at runner step time", async () => {
  const workflow = await readFile(workflowPath, "utf8");
  const { job } = workflowJob(workflow);
  assert.doesNotMatch(JSON.stringify(job.env), /runner\.temp|RUNNER_TEMP/);
  assert.doesNotMatch(workflow, /\$\{\{\s*runner\.temp\s*\}\}/);

  const initIndex = job.steps.findIndex((step) => step.name === "Initialize runner-local temporary paths");
  const gateIndex = job.steps.findIndex((step) => step.name === "Enforce disabled-by-default workflow gate");
  assert.ok(initIndex > gateIndex);
  const initStep = String(job.steps[initIndex]?.run);
  for (const variable of [
    "PACKAGE_ARCHIVE", "PACKAGE_HEADERS", "PACKAGE_DIR", "OUTPUT_DIR", "RELEASE_ARCHIVE",
    "RELEASE_FILE_LIST", "SSH_DIR", "SSH_KEY_FILE", "SSH_KNOWN_HOSTS_FILE",
  ]) {
    assert.equal(Object.hasOwn(job.env, variable), false);
    assert.match(initStep, new RegExp(`"${variable}=\\$RUNNER_TEMP\\/[^"\\r\\n]+"`));
    assert.ok(job.steps.findIndex((step, index) => index > initIndex && JSON.stringify(step).includes(`$${variable}`)) > initIndex);
  }
  assert.match(initStep, /"SSH_DIR=\$RUNNER_TEMP\/pianissimo-ssh"/);
  assert.match(initStep, /"SSH_KEY_FILE=\$RUNNER_TEMP\/pianissimo-ssh\/id_ed25519"/);
  assert.match(initStep, /"SSH_KNOWN_HOSTS_FILE=\$RUNNER_TEMP\/pianissimo-ssh\/known_hosts"/);
  assert.match(initStep, /\}\s*>> "\$GITHUB_ENV"/);
});

test("extractor rejects invalid identities, package mismatch, traversal, and symlink entries", async () => {
  await mkdir(buildRoot, { recursive: true });
  const root = await mkdtemp(resolve(buildRoot, "workflow-transport-"));
  try {
    const publicationId = "12345678-1234-4234-8234-123456789abc";
    const sourceRevision = "1".repeat(40);
    const manifestBytes = Buffer.from("{}\n");
    const metadataBase = {
      contractVersion: 1,
      publicationId,
      sequence: 1,
      candidateHash: sha256("candidate"),
      planHash: sha256("plan"),
      sourceRevision,
      manifestSha256: sha256(manifestBytes),
      createdAt: "2026-09-30T12:00:00Z",
      media: [],
    };
    const packageSha256 = sha256(canonicalJson({
      contractVersion: 1,
      candidateHash: metadataBase.candidateHash,
      planHash: metadataBase.planHash,
      sourceRevision,
      manifestSha256: metadataBase.manifestSha256,
      media: [],
    }));
    const publicationBytes = Buffer.from(`${canonicalJson({ ...metadataBase, packageSha256 })}\n`);
    const validArchive = resolve(root, "valid.tar");
    await writeFile(validArchive, archive([
      ["manifest.json", manifestBytes],
      ["publication.json", publicationBytes],
    ]));

    const valid = runExtractor([validArchive, resolve(root, "valid-out"), publicationId, "1", packageSha256, sourceRevision]);
    assert.equal(valid.status, 0, valid.stderr);

    for (const [label, args] of [
      ["uuid", [validArchive, resolve(root, "bad-uuid"), "not-a-uuid", "1", packageSha256, sourceRevision]],
      ["sequence", [validArchive, resolve(root, "bad-sequence"), publicationId, "0", packageSha256, sourceRevision]],
      ["sha", [validArchive, resolve(root, "bad-sha"), publicationId, "1", "a".repeat(64), sourceRevision]],
    ] as const) {
      const result = runExtractor(args);
      assert.notEqual(result.status, 0, `${label} should fail`);
    }

    const traversalArchive = resolve(root, "traversal.tar");
    await writeFile(traversalArchive, archive([["../.env", Buffer.from("secret")]]));
    assert.notEqual(runExtractor([
      traversalArchive, resolve(root, "traversal-out"), publicationId, "1", packageSha256, sourceRevision,
    ]).status, 0);

    const symlinkArchive = resolve(root, "symlink.tar");
    await writeFile(symlinkArchive, archive([["media/link.jpg", Buffer.alloc(0), "2"]]));
    assert.notEqual(runExtractor([
      symlinkArchive, resolve(root, "symlink-out"), publicationId, "1", packageSha256, sourceRevision,
    ]).status, 0);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("workflow input validation contains strict UUID, sequence, SHA-256 and revision checks", async () => {
  const workflow = await readFile(workflowPath, "utf8");
  assert.match(workflow, /PUBLICATION_ID.*\^\[0-9a-f\]\{8\}/);
  assert.match(workflow, /PUBLICATION_SEQUENCE.*\^\[1-9\]\[0-9\]\*\$/);
  assert.match(workflow, /PACKAGE_SHA256.*\^\[0-9a-f\]\{64\}\$/);
  assert.match(workflow, /SOURCE_REVISION.*\^\[0-9a-f\]\{40\}\$/);
  assert.doesNotMatch(workflow, /IGNORECASE/);
  assert.match(workflow, /read-publisher-digest\.mjs/);
  assert.match(workflow, /logical_sha.*\^\[0-9a-f\]\{64\}\$/);
  assert.equal(createHash("sha256").update("transport").digest("hex").length, 64);
});
