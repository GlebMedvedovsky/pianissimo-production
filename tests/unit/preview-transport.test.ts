import test from "node:test";
import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { mkdtemp, readFile, readdir, rm, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { extractPreviewTransport } from "../../scripts/extract-preview-transport.mjs";

const canonicalize = (value: unknown): unknown => Array.isArray(value) ? value.map(canonicalize) : value && typeof value === "object" ? Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonicalize((value as Record<string, unknown>)[key])])) : value;
const canonicalJson = (value: unknown) => JSON.stringify(canonicalize(value));
const sha256 = (value: string | Buffer) => createHash("sha256").update(value).digest("hex");

function tar(entries: Array<[string, Buffer]>) {
  const parts: Buffer[] = [];
  const octal = (value: number, length: number) => `${value.toString(8).padStart(length - 1, "0")}\0`;
  for (const [name, bytes] of entries) {
    const header = Buffer.alloc(512);
    header.write(name, 0, 100, "utf8");
    header.write(octal(0o644, 8), 100, 8, "ascii");
    header.write(octal(0, 8), 108, 8, "ascii");
    header.write(octal(0, 8), 116, 8, "ascii");
    header.write(octal(bytes.length, 12), 124, 12, "ascii");
    header.write(octal(0, 12), 136, 12, "ascii");
    header.fill(0x20, 148, 156);
    header.write("0", 156, 1, "ascii");
    header.write("ustar\0", 257, 6, "ascii");
    header.write("00", 263, 2, "ascii");
    const checksum = header.reduce((sum, byte) => sum + byte, 0);
    header.write(`${checksum.toString(8).padStart(6, "0")}\0 `, 148, 8, "ascii");
    parts.push(header, bytes, Buffer.alloc((512 - bytes.length % 512) % 512));
  }
  parts.push(Buffer.alloc(1024));
  return Buffer.concat(parts);
}

test("preview transport stays metadata-only for a declared 500 MiB video", async (t) => {
  const root = await mkdtemp(resolve(".local-builds", "preview-transport-test-"));
  try {
    const manifest = Buffer.from("{}\n");
    const identifier = "c".repeat(32);
    const sourceRevision = "a".repeat(40);
    const media = [{ assetId: "hero-video", publicPath: "media/preview/hero-video.mp4", sha256: "d".repeat(64), size: 500 * 1024 * 1024, type: "video" }];
    const digest = { contractVersion: 2, draftHash: "b".repeat(64), manifestSha256: sha256(manifest), media, sourceRevision };
    const metadata = { ...digest, createdAt: "2026-10-02T12:00:00+00:00", packageSha256: sha256(canonicalJson(digest)), previewId: 42, previewIdentifier: identifier };
    const archiveBytes = tar([["manifest.json", manifest], ["preview.json", Buffer.from(`${canonicalJson(metadata)}\n`)]]);
    const archive = resolve(root, "transport.tar");
    await writeFile(archive, archiveBytes);
    assert.ok(archiveBytes.length < 16 * 1024, "transport size must not scale with original media bytes");
    t.diagnostic(`declared media: ${media[0]!.size} bytes; transport archive: ${archiveBytes.length} bytes`);

    const destination = resolve(root, "extracted");
    extractPreviewTransport({ archive, destination, identifier, packageSha256: metadata.packageSha256, sourceRevision });
    assert.deepEqual((await readdir(destination)).sort(), ["manifest.json", "preview.json"]);
    assert.equal((JSON.parse(await readFile(resolve(destination, "preview.json"), "utf8"))).media[0].size, 500 * 1024 * 1024);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("preview transport rejects embedded media bytes", async () => {
  const root = await mkdtemp(resolve(".local-builds", "preview-transport-reject-"));
  try {
    const archive = resolve(root, "transport.tar");
    await writeFile(archive, tar([["media/preview/hero-video.mp4", Buffer.from("not allowed")]]));
    assert.throws(() => extractPreviewTransport({ archive, destination: resolve(root, "extracted"), identifier: "c".repeat(32), packageSha256: "d".repeat(64), sourceRevision: "a".repeat(40) }), /Unsafe preview tar entry/);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});
