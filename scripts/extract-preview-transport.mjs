import { createHash } from "node:crypto";
import { closeSync, existsSync, fstatSync, mkdirSync, openSync, readFileSync, readSync, writeSync } from "node:fs";
import { basename, dirname, join, resolve, sep } from "node:path";

const HASH = /^[a-f0-9]{64}$/; const REVISION = /^[a-f0-9]{40}$/; const IDENTIFIER = /^[a-f0-9]{32}$/;
const fail = (message) => { throw new Error(message); };
const sha256 = (bytes) => createHash("sha256").update(bytes).digest("hex");
const canonicalize = (value) => Array.isArray(value) ? value.map(canonicalize) : value && typeof value === "object" ? Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonicalize(value[key])])) : value;
const canonicalJson = (value) => JSON.stringify(canonicalize(value));
const field = (block, offset, length) => { const bytes = block.subarray(offset, offset + length); const nul = bytes.indexOf(0); return bytes.subarray(0, nul < 0 ? bytes.length : nul).toString("utf8"); };
const octal = (block, offset, length) => { const value = field(block, offset, length).trim(); if (!/^[0-7]+$/.test(value)) fail("Invalid preview tar number."); return Number.parseInt(value, 8); };
const safeName = (name) => {
  if (!name || name.startsWith("/") || name.includes("\\") || name.includes("\0") || name.includes("//") || name.split("/").some((part) => !part || part === "." || part === "..")
    || !/^(?:manifest\.json|preview\.json)$/.test(name)) fail("Unsafe preview tar entry.");
  return name;
};
const readExact = (fd, buffer, position) => { let offset = 0; while (offset < buffer.length) { const count = readSync(fd, buffer, offset, buffer.length - offset, position + offset); if (!count) fail("Truncated preview transport."); offset += count; } };

export function extractPreviewTransport({ archive, destination, identifier, packageSha256, sourceRevision }) {
  if (!IDENTIFIER.test(identifier) || !HASH.test(packageSha256) || !REVISION.test(sourceRevision)) fail("Invalid expected preview identity.");
  const root = resolve(destination); if (existsSync(root)) fail("Preview extraction destination must be fresh."); mkdirSync(root, { recursive: false, mode: 0o700 });
  const fd = openSync(resolve(archive), "r"); const size = fstatSync(fd).size; const seen = new Set(); let position = 0; let zeroBlocks = 0;
  try {
    while (position + 512 <= size) {
      const header = Buffer.alloc(512); readExact(fd, header, position); position += 512;
      if (header.every((byte) => byte === 0)) { zeroBlocks++; if (zeroBlocks === 2) break; continue; }
      if (zeroBlocks) fail("Invalid preview tar end marker.");
      const stored = octal(header, 148, 8); const checksum = Buffer.from(header); checksum.fill(0x20, 148, 156); if (stored !== checksum.reduce((sum, byte) => sum + byte, 0)) fail("Preview tar checksum mismatch.");
      if (field(header, 257, 6) !== "ustar" || ![0, 0x30].includes(header[156])) fail("Unsupported preview transport entry.");
      const prefix = field(header, 345, 155); const name = safeName(prefix ? `${prefix}/${field(header, 0, 100)}` : field(header, 0, 100)); if (seen.has(name)) fail("Duplicate preview tar entry."); seen.add(name);
      const entrySize = octal(header, 124, 12); if (!Number.isSafeInteger(entrySize) || entrySize < 0 || entrySize > 536870912 || position + entrySize > size) fail("Invalid preview tar entry size.");
      const output = resolve(root, ...name.split("/")); if (!(output === root || output.startsWith(root + sep))) fail("Preview tar path escaped root."); mkdirSync(dirname(output), { recursive: true, mode: 0o700 });
      const outputFd = openSync(output, "wx", 0o600); try { let remaining = entrySize; const chunk = Buffer.alloc(Math.min(1048576, Math.max(1, remaining))); while (remaining) { const length = Math.min(chunk.length, remaining); const view = chunk.subarray(0, length); readExact(fd, view, position); let written = 0; while (written < length) written += writeSync(outputFd, view, written); position += length; remaining -= length; } } finally { closeSync(outputFd); }
      const padding = (512 - entrySize % 512) % 512; if (padding) { const bytes = Buffer.alloc(padding); readExact(fd, bytes, position); if (!bytes.every((byte) => byte === 0)) fail("Non-zero preview tar padding."); position += padding; }
    }
    if (zeroBlocks !== 2) fail("Preview transport has no complete end marker.");
  } finally { closeSync(fd); }
  const metadataBytes = readFileSync(join(root, "preview.json"), "utf8"); const manifestBytes = readFileSync(join(root, "manifest.json")); const metadata = JSON.parse(metadataBytes);
  if (Object.keys(metadata).join(",") !== "contractVersion,createdAt,draftHash,manifestSha256,media,packageSha256,previewId,previewIdentifier,sourceRevision" || metadataBytes !== canonicalJson(metadata) + "\n" || metadata.contractVersion !== 2 || metadata.previewIdentifier !== identifier || metadata.packageSha256 !== packageSha256 || metadata.sourceRevision !== sourceRevision || sha256(manifestBytes) !== metadata.manifestSha256 || !Array.isArray(metadata.media)) fail("Preview transport identity mismatch.");
  let previous = ""; for (const item of metadata.media) { if (!item || Object.keys(item).join(",") !== "assetId,publicPath,sha256,size,type" || typeof item.assetId !== "string" || item.assetId === "" || !/^media\/preview\/[a-z0-9-]+\.(?:webp|jpg|png|mp3|wav|ogg|mp4|webm)$/.test(item.publicPath) || item.publicPath <= previous || !HASH.test(item.sha256) || !Number.isSafeInteger(item.size) || item.size < 0 || !["image", "audio", "video"].includes(item.type)) fail("Invalid preview media metadata."); previous = item.publicPath; }
  const logical = sha256(canonicalJson({ contractVersion: metadata.contractVersion, draftHash: metadata.draftHash, manifestSha256: metadata.manifestSha256, media: metadata.media, sourceRevision: metadata.sourceRevision }));
  if (logical !== metadata.packageSha256) fail("Preview package logical digest mismatch.");
  const expected = new Set(["preview.json", "manifest.json"]);
  if (seen.size !== expected.size || [...seen].some((name) => !expected.has(name))) fail("Preview transport inventory mismatch.");
}

if (process.argv[1] && basename(process.argv[1]) === "extract-preview-transport.mjs") {
  const [archive, destination, identifier, packageSha256, sourceRevision] = process.argv.slice(2);
  try { extractPreviewTransport({ archive, destination, identifier, packageSha256, sourceRevision }); } catch (error) { console.error(error instanceof Error ? error.message : "Preview extraction failed."); process.exit(1); }
}
