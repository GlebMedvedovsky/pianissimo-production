import { createHash } from "node:crypto";
import {
  closeSync,
  existsSync,
  fstatSync,
  mkdirSync,
  openSync,
  readFileSync,
  readSync,
  writeSync,
} from "node:fs";
import { dirname, join, resolve, sep } from "node:path";
import { pathToFileURL } from "node:url";

const HASH = /^[0-9a-f]{64}$/;
const REVISION = /^[0-9a-f]{40}$/;
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

const fail = (message) => {
  throw new Error(message);
};
const sha256 = (bytes) => createHash("sha256").update(bytes).digest("hex");
const canonicalize = (value) => {
  if (Array.isArray(value)) return value.map(canonicalize);
  if (value && typeof value === "object")
    return Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonicalize(value[key])]));
  return value;
};
const canonicalJson = (value) => JSON.stringify(canonicalize(value));
const stringField = (block, offset, length) => {
  const field = block.subarray(offset, offset + length);
  const nul = field.indexOf(0);
  return field.subarray(0, nul === -1 ? field.length : nul).toString("utf8");
};
const octalField = (block, offset, length, label) => {
  const value = stringField(block, offset, length).trim();
  if (!/^[0-7]+$/.test(value)) fail(`Invalid tar ${label}.`);
  return Number.parseInt(value, 8);
};
const safeName = (name) => {
  if (
    !name ||
    name.startsWith("/") ||
    name.includes("\\") ||
    name.includes("\0") ||
    name.includes("//") ||
    name.split("/").some((part) => part === "" || part === "." || part === "..") ||
    !/^(?:manifest\.json|publication\.json|media\/[A-Za-z0-9._/-]+)$/.test(name)
  ) fail("Unsafe or unexpected tar entry name.");
  return name;
};
const inside = (candidate, root) => candidate === root || candidate.startsWith(`${root}${sep}`);
const readExact = (fd, buffer, position) => {
  let offset = 0;
  while (offset < buffer.length) {
    const count = readSync(fd, buffer, offset, buffer.length - offset, position + offset);
    if (count === 0) fail("Truncated publication transport.");
    offset += count;
  }
};

export function extractPublicationTransport({
  archive,
  destination,
  publicationId,
  sequence,
  packageSha256,
  sourceRevision,
}) {
  if (!UUID.test(publicationId) || !/^[1-9][0-9]*$/.test(sequence) || !HASH.test(packageSha256) || !REVISION.test(sourceRevision))
    fail("Invalid expected publication identity.");
  const root = resolve(destination);
  if (existsSync(root)) fail("Publication extraction destination must be fresh.");
  mkdirSync(root, { recursive: false, mode: 0o700 });
  const fd = openSync(resolve(archive), "r");
  const size = fstatSync(fd).size;
  const seen = new Set();
  let position = 0;
  let zeroBlocks = 0;
  try {
    while (position + 512 <= size) {
      const header = Buffer.alloc(512);
      readExact(fd, header, position);
      position += 512;
      if (header.every((byte) => byte === 0)) {
        zeroBlocks++;
        if (zeroBlocks === 2) break;
        continue;
      }
      if (zeroBlocks !== 0) fail("Invalid tar end marker.");
      const storedChecksum = octalField(header, 148, 8, "checksum");
      const checksumHeader = Buffer.from(header);
      checksumHeader.fill(0x20, 148, 156);
      const actualChecksum = checksumHeader.reduce((sum, byte) => sum + byte, 0);
      if (storedChecksum !== actualChecksum) fail("Tar header checksum mismatch.");
      if (stringField(header, 257, 6) !== "ustar") fail("Unsupported publication transport format.");
      const type = header[156];
      if (type !== 0 && type !== 0x30) fail("Symlinks and special tar entries are forbidden.");
      const prefix = stringField(header, 345, 155);
      const leaf = stringField(header, 0, 100);
      const name = safeName(prefix ? `${prefix}/${leaf}` : leaf);
      if (seen.has(name)) fail("Duplicate tar entry.");
      seen.add(name);
      const entrySize = octalField(header, 124, 12, "size");
      if (!Number.isSafeInteger(entrySize) || entrySize < 0 || position + entrySize > size)
        fail("Invalid tar entry size.");
      const output = resolve(root, ...name.split("/"));
      if (!inside(output, root)) fail("Tar entry escaped extraction root.");
      mkdirSync(dirname(output), { recursive: true, mode: 0o700 });
      const outputFd = openSync(output, "wx", 0o600);
      try {
        let remaining = entrySize;
        const chunk = Buffer.alloc(Math.min(1024 * 1024, Math.max(1, entrySize)));
        while (remaining > 0) {
          const length = Math.min(chunk.length, remaining);
          const view = chunk.subarray(0, length);
          readExact(fd, view, position);
          let written = 0;
          while (written < view.length) written += writeSync(outputFd, view, written);
          position += length;
          remaining -= length;
        }
      } finally {
        closeSync(outputFd);
      }
      const padding = (512 - (entrySize % 512)) % 512;
      if (padding) {
        const bytes = Buffer.alloc(padding);
        readExact(fd, bytes, position);
        if (!bytes.every((byte) => byte === 0)) fail("Non-zero tar padding is forbidden.");
        position += padding;
      }
    }
    if (zeroBlocks !== 2) fail("Publication transport has no complete end marker.");
    if (position !== size) {
      const remainder = Buffer.alloc(size - position);
      readExact(fd, remainder, position);
      if (!remainder.every((byte) => byte === 0)) fail("Data after tar end marker is forbidden.");
    }
  } finally {
    closeSync(fd);
  }

  if (!seen.has("publication.json") || !seen.has("manifest.json")) fail("Publication transport metadata is incomplete.");
  const publicationBytes = readFileSync(join(root, "publication.json"));
  const manifestBytes = readFileSync(join(root, "manifest.json"));
  let publication;
  try {
    publication = JSON.parse(publicationBytes.toString("utf8"));
  } catch {
    fail("Invalid publication metadata JSON.");
  }
  if (`${canonicalJson(publication)}\n` !== publicationBytes.toString("utf8")) fail("Publication metadata is not canonical JSON.");
  if (
    publication.publicationId !== publicationId ||
    String(publication.sequence) !== sequence ||
    publication.packageSha256 !== packageSha256 ||
    publication.sourceRevision !== sourceRevision ||
    !HASH.test(publication.manifestSha256) ||
    sha256(manifestBytes) !== publication.manifestSha256 ||
    !Array.isArray(publication.media)
  ) fail("Publication transport identity or digest mismatch.");
  const expected = new Set(["manifest.json", "publication.json"]);
  for (const entry of publication.media) {
    const name = safeName(entry?.publicPath);
    if (!HASH.test(entry?.sha256) || !Number.isSafeInteger(entry?.size) || entry.size < 0 || expected.has(name))
      fail("Invalid publication media inventory.");
    const bytes = readFileSync(join(root, ...name.split("/")));
    if (bytes.length !== entry.size || sha256(bytes) !== entry.sha256) fail("Publication media digest mismatch.");
    expected.add(name);
  }
  if (seen.size !== expected.size || [...seen].some((name) => !expected.has(name)))
    fail("Publication transport contains unexpected or missing files.");
  const logical = sha256(canonicalJson({
    contractVersion: publication.contractVersion,
    candidateHash: publication.candidateHash,
    planHash: publication.planHash,
    sourceRevision: publication.sourceRevision,
    manifestSha256: publication.manifestSha256,
    media: publication.media,
  }));
  if (logical !== packageSha256) fail("Publication logical package digest mismatch.");
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const [archive, destination, publicationId, sequence, packageSha256, sourceRevision] = process.argv.slice(2);
  if (!sourceRevision) {
    console.error("Usage: node extract-publication-transport.mjs ARCHIVE DEST PUBLICATION_ID SEQUENCE PACKAGE_SHA256 SOURCE_REVISION");
    process.exit(2);
  }
  try {
    extractPublicationTransport({ archive, destination, publicationId, sequence, packageSha256, sourceRevision });
  } catch (error) {
    console.error(error instanceof Error ? error.message : "Publication transport extraction failed.");
    process.exit(1);
  }
}
