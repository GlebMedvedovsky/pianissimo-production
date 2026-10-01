import test from "node:test";
import assert from "node:assert/strict";
import {
  mkdir,
  mkdtemp,
  readFile,
  readdir,
  rm,
  unlink,
  writeFile,
} from "node:fs/promises";
import { resolve } from "node:path";
import releaseFixture from "../../backend/tests/Fixtures/valid-release-manifest.json";
import { buildPublication, assertSafeOutputPath } from "../../scripts/build-publication";
import {
  canonicalJson,
  sha256,
  validatePublicationPackage,
} from "../../scripts/publication-package";
import { createReleaseIntegrity } from "../../scripts/create-release-integrity.mjs";
import { validateReleaseTextReferences } from "../../scripts/release-reference-contract";

const localBuildRoot = resolve(".local-builds");
const mediaBytes = Buffer.from("deterministic-public-release-image");

async function createPackage(testRoot: string) {
  const packageRoot = resolve(testRoot, "package");
  await mkdir(resolve(packageRoot, "media"), { recursive: true });
  const manifest = structuredClone(releaseFixture) as any;
  const mediaSha = sha256(mediaBytes);
  manifest.assets[0]!.path = "media/release-image.webp";
  manifest.assets[0]!.sha256 = mediaSha;
  manifest.site.mediaSlots.hero.image = "release-image";
  const manifestBytes = `${canonicalJson(manifest)}\n`;
  const manifestSha256 = sha256(manifestBytes);
  const media = [
    {
      assetId: "release-image",
      publicPath: "media/release-image.webp",
      sha256: mediaSha,
      size: mediaBytes.length,
      type: "image" as const,
    },
  ];
  const publication = {
    contractVersion: 1,
    publicationId: "12345678-1234-4234-8234-123456789abc",
    sequence: 1,
    candidateHash: sha256("candidate"),
    planHash: sha256("plan"),
    sourceRevision: "1".repeat(40),
    manifestSha256,
    packageSha256: sha256(
      canonicalJson({
        contractVersion: 1,
        candidateHash: sha256("candidate"),
        planHash: sha256("plan"),
        sourceRevision: "1".repeat(40),
        manifestSha256,
        media,
      }),
    ),
    createdAt: "2026-09-30T12:00:00Z",
    media,
  };
  await writeFile(resolve(packageRoot, "manifest.json"), manifestBytes);
  await writeFile(
    resolve(packageRoot, "publication.json"),
    `${canonicalJson(publication)}\n`,
  );
  await writeFile(resolve(packageRoot, "media", "release-image.webp"), mediaBytes);
  return { packageRoot, publication, manifest };
}

async function withRoot(run: (root: string) => Promise<void>) {
  await mkdir(localBuildRoot, { recursive: true });
  const root = await mkdtemp(resolve(localBuildRoot, "publication-test-"));
  try {
    await run(root);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
}

test("publication package rejects manifest and deterministic package digest mismatches", async () => {
  await withRoot(async (root) => {
    const first = await createPackage(resolve(root, "manifest-sha"));
    const badManifest = { ...first.publication, manifestSha256: "a".repeat(64) };
    await writeFile(
      resolve(first.packageRoot, "publication.json"),
      `${canonicalJson(badManifest)}\n`,
    );
    await assert.rejects(
      validatePublicationPackage(first.packageRoot),
      /manifest SHA-256 mismatch/,
    );

    const second = await createPackage(resolve(root, "package-sha"));
    const badPackage = { ...second.publication, packageSha256: "b".repeat(64) };
    await writeFile(
      resolve(second.packageRoot, "publication.json"),
      `${canonicalJson(badPackage)}\n`,
    );
    await assert.rejects(
      validatePublicationPackage(second.packageRoot),
      /deterministic digest mismatch/,
    );
  });
});

test("publication package rejects media SHA, size, missing, and extra file mismatches", async () => {
  await withRoot(async (root) => {
    const checksum = await createPackage(resolve(root, "checksum"));
    await writeFile(
      resolve(checksum.packageRoot, "media", "release-image.webp"),
      Buffer.from("deterministic-public-release-imagf"),
    );
    await assert.rejects(validatePublicationPackage(checksum.packageRoot), /SHA-256 mismatch/);

    const size = await createPackage(resolve(root, "size"));
    await writeFile(resolve(size.packageRoot, "media", "release-image.webp"), "too short");
    await assert.rejects(validatePublicationPackage(size.packageRoot), /size mismatch/);

    const missing = await createPackage(resolve(root, "missing"));
    await unlink(resolve(missing.packageRoot, "media", "release-image.webp"));
    await assert.rejects(validatePublicationPackage(missing.packageRoot), /regular non-symlink file/);

    const extra = await createPackage(resolve(root, "extra"));
    await writeFile(resolve(extra.packageRoot, "media", "unreferenced.jpg"), "private");
    await assert.rejects(validatePublicationPackage(extra.packageRoot), /missing or extra files/);
  });
});

test("unsafe or overlapping publication output paths are rejected", async () => {
  await withRoot(async (root) => {
    await assert.rejects(
      validatePublicationPackage(resolve(root, "missing-package")),
      /regular non-symlink directory/,
    );
    const fixture = await createPackage(root);
    await mkdir(resolve(root, "existing-output"));
    await assert.rejects(
      assertSafeOutputPath(resolve(root, "existing-output"), fixture.packageRoot),
      /must not already exist/,
    );
    await assert.rejects(
      assertSafeOutputPath(resolve("content", "publication-output"), fixture.packageRoot),
      /repository source locations/,
    );
    await assert.rejects(
      assertSafeOutputPath(resolve(fixture.packageRoot, "output"), fixture.packageRoot),
      /must not overlap/,
    );
  });
});

test("publication build injects the package manifest and emits only allowlisted media", async () => {
  await withRoot(async (root) => {
    const fixture = await createPackage(root);
    const output = resolve(root, "static-output");
    const trackedManifest = resolve("content", "manifest.json");
    const before = sha256(await readFile(trackedManifest));
    await buildPublication(fixture.packageRoot, output);
    const after = sha256(await readFile(trackedManifest));
    assert.equal(after, before, "production build must not mutate tracked content");
    assert.equal(
      sha256(await readFile(resolve(output, "media", "release-image.webp"))),
      fixture.publication.media[0]!.sha256,
    );
    await assert.rejects(readFile(resolve(output, "media", "abszission.webp")));

    const files: string[] = [];
    const walk = async (directory: string) => {
      for (const entry of await readdir(directory, { withFileTypes: true })) {
        const path = resolve(directory, entry.name);
        if (entry.isDirectory()) await walk(path);
        else files.push(path);
      }
    };
    await walk(output);
    assert.equal(
      files.some((file) => /(?:\.env|\.php|fixtures|tests|private)/i.test(file)),
      false,
    );
    const html = (
      await Promise.all(
        files.filter((file) => file.endsWith(".html")).map((file) => readFile(file, "utf8")),
      )
    ).join("\n");
    assert.match(html, /Pianissimo release fixture/);
    assert.doesNotMatch(html, /release-image-480\.webp/);
    await createReleaseIntegrity(output, resolve(fixture.packageRoot, "publication.json"));
    const inventory = JSON.parse(await readFile(resolve(output, ".pianissimo-release-files.json"), "utf8"));
    await validateReleaseTextReferences(output, inventory);
  });
});
