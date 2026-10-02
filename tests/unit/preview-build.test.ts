import test from "node:test";
import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { lstat, mkdir, mkdtemp, readFile, readdir, rm, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { buildPreview } from "../../scripts/build-preview";
import { createReleaseIntegrity } from "../../scripts/create-release-integrity.mjs";

const canonicalize = (value: unknown): unknown => Array.isArray(value) ? value.map(canonicalize) : value && typeof value === "object" ? Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonicalize((value as Record<string, unknown>)[key])])) : value;
const canonicalJson = (value: unknown) => JSON.stringify(canonicalize(value));
const sha256 = (value: string) => createHash("sha256").update(value).digest("hex");
const mediaBytes = Buffer.from("deterministic-preview-webp");

async function assertAuthenticatedPreviewAssets(output: string) {
  const files: string[] = [];
  const walk = async (directory: string) => {
    for (const entry of await readdir(directory, { withFileTypes: true })) {
      const path = resolve(directory, entry.name);
      if (entry.isDirectory()) await walk(path);
      else files.push(path);
    }
  };
  await walk(output);
  const known = new Set(files.map((path) => path.slice(output.length + 1).replaceAll("\\", "/")));
  const prefix = "/draft-preview/assets/";
  for (const path of files.filter((file) => /\.(?:html|css|m?js)$/i.test(file))) {
    const bytes = await readFile(path, "utf8");
    assert.doesNotMatch(bytes, /(?:["'(])\/_astro\//, `${path} bypasses the authenticated preview route`);
    for (const match of bytes.matchAll(/\/draft-preview\/assets\/([A-Za-z0-9._~!$&*+,;=:@%/-]+)/g)) {
      assert.ok(known.has(match[1]!.split(/[?#]/, 1)[0]!), `preview asset is absent from result inventory: ${match[1]}`);
      assert.ok(match[0].startsWith(prefix), `preview asset bypasses the session-authenticated route: ${match[0]}`);
    }
  }
}

test("package preview builds real Astro routes with the private preview shell", async (t) => {
  const root = await mkdtemp(resolve(".local-builds", "preview-test-"));
  try {
    const packageRoot = resolve(root, "package");
    const output = resolve(root, "output");
    const source = JSON.parse(await readFile(resolve("backend/tests/Fixtures/valid-release-manifest.json"), "utf8"));
    const mediaSha = createHash("sha256").update(mediaBytes).digest("hex");
    source.mode = "prototype";
    source.assets[0].path = "media/preview/release-image.webp";
    source.assets[0].sha256 = mediaSha;
    source.site.mediaSlots.hero.image = "release-image";
    const manifestBytes = `${canonicalJson(source)}\n`;
    const media = [{
      assetId: "release-image",
      publicPath: "media/preview/release-image.webp",
      sha256: mediaSha,
      size: 480 * 1024 * 1024,
      type: "image",
    }];
    const base = {
      contractVersion: 2,
      draftHash: "b".repeat(64),
      manifestSha256: sha256(manifestBytes),
      media,
      sourceRevision: "a".repeat(40),
    };
    const metadata = {
      ...base,
      createdAt: "2026-09-30T12:00:00+00:00",
      packageSha256: sha256(canonicalJson(base)),
      previewId: 1,
      previewIdentifier: "c".repeat(32),
    };
    await mkdir(packageRoot, { recursive: true });
    await writeFile(resolve(packageRoot, "manifest.json"), manifestBytes);
    await writeFile(resolve(packageRoot, "preview.json"), `${canonicalJson(metadata)}\n`);
    await buildPreview(packageRoot, output);
    await createReleaseIntegrity(output, resolve(packageRoot, "preview.json"));
    const inventory = JSON.parse(await readFile(resolve(output, ".pianissimo-release-files.json"), "utf8"));
    await assertAuthenticatedPreviewAssets(output);
    assert.equal(await lstat(resolve(output, "media", "preview")).catch(() => null), null);
    assert.equal(inventory.some((entry: { path: string }) => entry.path.startsWith("media/preview/")), false);
    const resultBytes = inventory.reduce((sum: number, entry: { size: number }) => sum + entry.size, 0);
    assert.ok(resultBytes < 10 * 1024 * 1024,
      "thin result must not scale with the declared 480 MiB original medium");
    t.diagnostic(`declared media: ${media[0]!.size} bytes; generated result inventory: ${resultBytes} bytes`);
    const declared = new Set(media.map((entry) => entry.publicPath));
    for (const route of ["index.html", "film-music/index.html", "directing/index.html", "legal/index.html", "privacy/index.html"]) {
      const html = await readFile(resolve(output, route), "utf8");
      assert.match(html, /data-draft-preview="true"/);
      assert.match(html, /Entwurfs-Vorschau/);
      assert.match(html, /noindex, nofollow/);
      assert.doesNotMatch(html, /LOCAL PROTOTYPE|PENDING REVIEW|Local prototype|delivery is disabled/i);
      assert.doesNotMatch(html, /release-image-480\.webp/);
      for (const match of html.matchAll(/\/(media\/preview\/[A-Za-z0-9._~!$&*+,;=:@%/-]+)/g)) {
        assert.ok(declared.has(match[1]!.split(/[?#]/, 1)[0]!), `preview references undeclared media: ${match[1]}`);
      }
    }
    assert.equal(await readFile(resolve(output, "robots.txt"), "utf8"), "User-agent: *\nDisallow: /\n");
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});
