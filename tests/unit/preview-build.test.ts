import test from "node:test";
import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { mkdir, mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { buildPreview } from "../../scripts/build-preview";

const canonicalize = (value: unknown): unknown => Array.isArray(value) ? value.map(canonicalize) : value && typeof value === "object" ? Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonicalize((value as Record<string, unknown>)[key])])) : value;
const canonicalJson = (value: unknown) => JSON.stringify(canonicalize(value));
const sha256 = (value: string) => createHash("sha256").update(value).digest("hex");

test("package preview builds real Astro routes with the private preview shell", async () => {
  const root = await mkdtemp(resolve(".local-builds", "preview-test-"));
  try {
    const packageRoot = resolve(root, "package");
    const output = resolve(root, "output");
    const source = JSON.parse(await readFile(resolve("backend/tests/Fixtures/valid-release-manifest.json"), "utf8"));
    source.mode = "prototype";
    source.assets = [];
    const manifestBytes = `${canonicalJson(source)}\n`;
    const base = {
      contractVersion: 1,
      draftHash: "b".repeat(64),
      manifestSha256: sha256(manifestBytes),
      media: [],
      sourceRevision: "a".repeat(40),
    };
    const metadata = {
      ...base,
      createdAt: "2026-09-30T12:00:00+00:00",
      packageSha256: sha256(canonicalJson(base)),
      previewId: 1,
      previewIdentifier: "c".repeat(32),
    };
    await mkdir(packageRoot);
    await writeFile(resolve(packageRoot, "manifest.json"), manifestBytes);
    await writeFile(resolve(packageRoot, "preview.json"), `${canonicalJson(metadata)}\n`);
    await buildPreview(packageRoot, output);
    for (const route of ["index.html", "film-music/index.html", "directing/index.html", "legal/index.html", "privacy/index.html"]) {
      const html = await readFile(resolve(output, route), "utf8");
      assert.match(html, /data-draft-preview="true"/);
      assert.match(html, /Entwurfs-Vorschau/);
      assert.match(html, /noindex, nofollow/);
    }
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});
