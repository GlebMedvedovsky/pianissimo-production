import { createHash } from "node:crypto";
import {
  lstat,
  readFile,
  readdir,
  realpath,
} from "node:fs/promises";
import { isAbsolute, relative, resolve, sep } from "node:path";
import { validateManifest, type Manifest } from "../contracts/content";

export type PublicationMedia = {
  assetId: string;
  publicPath: string;
  sha256: string;
  size: number;
  type: "image" | "audio" | "video";
};

export type ValidatedPublicationPackage = {
  root: string;
  manifestPath: string;
  manifestBytes: string;
  manifest: Manifest;
  media: PublicationMedia[];
};

const HASH = /^[a-f0-9]{64}$/;
const REVISION = /^[a-f0-9]{40}$/;
const UUID_V4 = /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/;
const CREATED_AT = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;
const EXTENSIONS: Record<PublicationMedia["type"], Set<string>> = {
  image: new Set(["webp", "jpg", "jpeg", "png"]),
  audio: new Set(["mp3", "wav", "ogg"]),
  video: new Set(["mp4", "webm"]),
};

const comparable = (path: string) =>
  (process.platform === "win32" ? path.toLowerCase() : path).replaceAll("\\", "/");

export const isInside = (candidate: string, root: string) => {
  const path = comparable(resolve(candidate));
  const parent = comparable(resolve(root)).replace(/\/$/, "");
  return path === parent || path.startsWith(`${parent}/`);
};

const object = (value: unknown, label: string): Record<string, unknown> => {
  if (!value || typeof value !== "object" || Array.isArray(value))
    throw new Error(`${label} must be an object.`);
  return value as Record<string, unknown>;
};

const exactKeys = (
  value: Record<string, unknown>,
  expected: string[],
  label: string,
) => {
  const actual = Object.keys(value).sort();
  const keys = [...expected].sort();
  if (actual.length !== keys.length || actual.some((key, index) => key !== keys[index]))
    throw new Error(`${label} does not match publication contract keys.`);
};

const canonicalize = (value: unknown): unknown => {
  if (Array.isArray(value)) return value.map(canonicalize);
  if (value && typeof value === "object")
    return Object.fromEntries(
      Object.entries(value as Record<string, unknown>)
        .sort(([left], [right]) => (left < right ? -1 : left > right ? 1 : 0))
        .map(([key, item]) => [key, canonicalize(item)]),
    );
  return value;
};

export const canonicalJson = (value: unknown) => JSON.stringify(canonicalize(value));
export const sha256 = (value: string | Buffer) =>
  createHash("sha256").update(value).digest("hex");

const normalizePublicPath = (value: unknown) => {
  if (typeof value !== "string" || !value || value.includes("\0") || isAbsolute(value))
    throw new Error("Publication media path must be a safe relative path.");
  const replaced = value.replaceAll("\\", "/");
  const parts = replaced.split("/");
  if (parts.some((part) => !part || part === "." || part === ".."))
    throw new Error(`Unsafe publication media path: ${value}.`);
  const normalized = parts.join("/");
  if (normalized !== value || !normalized.startsWith("media/") || /[:*?"<>|]/.test(normalized))
    throw new Error(`Publication media path is not canonical: ${value}.`);
  return normalized;
};

const regularFile = async (path: string, label: string) => {
  const stat = await lstat(path).catch(() => null);
  if (!stat?.isFile() || stat.isSymbolicLink())
    throw new Error(`${label} must be a regular non-symlink file.`);
  return stat;
};

const walkPackage = async (root: string) => {
  const files: string[] = [];
  const visit = async (directory: string) => {
    for (const entry of await readdir(directory, { withFileTypes: true })) {
      const path = resolve(directory, entry.name);
      const stat = await lstat(path);
      if (stat.isSymbolicLink()) throw new Error("Publication package contains a symlink.");
      if (stat.isDirectory()) await visit(path);
      else if (stat.isFile()) files.push(relative(root, path).split(sep).join("/"));
      else throw new Error("Publication package contains a special file.");
    }
  };
  await visit(root);
  return files.sort();
};

export async function validatePublicationPackage(
  inputPath: string,
): Promise<ValidatedPublicationPackage> {
  const root = resolve(inputPath);
  const rootStat = await lstat(root).catch(() => null);
  if (!rootStat?.isDirectory() || rootStat.isSymbolicLink())
    throw new Error("Publication package must be a regular non-symlink directory.");
  if (comparable(await realpath(root)) !== comparable(root))
    throw new Error("Publication package path may not traverse symlinks.");

  const publicationPath = resolve(root, "publication.json");
  const manifestPath = resolve(root, "manifest.json");
  await regularFile(publicationPath, "publication.json");
  await regularFile(manifestPath, "manifest.json");
  const publicationBytes = await readFile(publicationPath, "utf8");
  const manifestBytes = await readFile(manifestPath, "utf8");
  let publicationValue: unknown;
  let manifestValue: unknown;
  try {
    publicationValue = JSON.parse(publicationBytes);
    manifestValue = JSON.parse(manifestBytes);
  } catch {
    throw new Error("Publication metadata and manifest must contain valid JSON.");
  }

  const publication = object(publicationValue, "publication.json");
  exactKeys(
    publication,
    [
      "contractVersion",
      "publicationId",
      "sequence",
      "candidateHash",
      "planHash",
      "sourceRevision",
      "manifestSha256",
      "packageSha256",
      "createdAt",
      "media",
    ],
    "publication.json",
  );
  if (publication.contractVersion !== 1) throw new Error("Unsupported publication contract version.");
  if (!UUID_V4.test(String(publication.publicationId))) throw new Error("Invalid publication ID.");
  if (!Number.isInteger(publication.sequence) || Number(publication.sequence) < 1)
    throw new Error("Invalid publication sequence.");
  if (!HASH.test(String(publication.candidateHash)) || !HASH.test(String(publication.planHash)))
    throw new Error("Invalid publication candidate or plan hash.");
  if (!REVISION.test(String(publication.sourceRevision))) throw new Error("Invalid source revision.");
  if (!HASH.test(String(publication.manifestSha256)) || !HASH.test(String(publication.packageSha256)))
    throw new Error("Invalid publication digest.");
  if (!CREATED_AT.test(String(publication.createdAt))) throw new Error("Invalid publication timestamp.");
  if (!Array.isArray(publication.media)) throw new Error("Publication media inventory is required.");

  const canonicalManifestBytes = `${canonicalJson(manifestValue)}\n`;
  if (manifestBytes !== canonicalManifestBytes)
    throw new Error("manifest.json is not the immutable canonical Task 2 payload.");
  const manifestSha256 = sha256(manifestBytes);
  if (manifestSha256 !== publication.manifestSha256)
    throw new Error("Publication manifest SHA-256 mismatch.");

  const manifest = validateManifest(manifestValue, true);
  if (manifest.mode !== "release") throw new Error("Publication manifest must use release mode.");
  if (manifest.sources.length || manifest.projects.some((project) => project.sourceIds.length))
    throw new Error("Release manifest exposes private provenance identifiers.");

  const assets = new Map(manifest.assets.map((asset) => [asset.path, asset]));
  const seenPaths = new Set<string>();
  const seenAssets = new Set<string>();
  const media: PublicationMedia[] = publication.media.map((entryValue, index) => {
    const entry = object(entryValue, `media[${index}]`);
    exactKeys(entry, ["assetId", "publicPath", "sha256", "size", "type"], `media[${index}]`);
    const publicPath = normalizePublicPath(entry.publicPath);
    if (seenPaths.has(publicPath)) throw new Error(`Duplicate publication media path: ${publicPath}.`);
    if (typeof entry.assetId !== "string" || seenAssets.has(entry.assetId))
      throw new Error(`Duplicate or invalid publication media asset ID at ${publicPath}.`);
    const entrySha256 = String(entry.sha256);
    if (!HASH.test(entrySha256)) throw new Error(`Invalid media SHA-256: ${publicPath}.`);
    if (!Number.isInteger(entry.size) || Number(entry.size) < 0)
      throw new Error(`Invalid media size: ${publicPath}.`);
    if (!(["image", "audio", "video"] as const).includes(entry.type as PublicationMedia["type"]))
      throw new Error(`Invalid media type: ${publicPath}.`);
    const type = entry.type as PublicationMedia["type"];
    const extension = publicPath.slice(publicPath.lastIndexOf(".") + 1).toLowerCase();
    if (!EXTENSIONS[type].has(extension)) throw new Error(`Media extension/type mismatch: ${publicPath}.`);
    const asset = assets.get(publicPath);
    if (
      !asset ||
      asset.id !== entry.assetId ||
      asset.type !== type ||
      asset.sha256 !== entrySha256
    )
      throw new Error(`Manifest/publication media mismatch: ${publicPath}.`);
    seenPaths.add(publicPath);
    seenAssets.add(entry.assetId);
    return {
      assetId: entry.assetId,
      publicPath,
      sha256: entrySha256,
      size: Number(entry.size),
      type,
    };
  });
  if (media.some((entry, index) => index && media[index - 1]!.publicPath >= entry.publicPath))
    throw new Error("Publication media inventory must be uniquely sorted by public path.");
  if (assets.size !== media.length || [...assets.keys()].some((path) => !seenPaths.has(path)))
    throw new Error("Release manifest and publication media inventory must agree exactly.");

  const packageDigest = sha256(
    canonicalJson({
      contractVersion: publication.contractVersion,
      candidateHash: publication.candidateHash,
      planHash: publication.planHash,
      sourceRevision: publication.sourceRevision,
      manifestSha256: publication.manifestSha256,
      media,
    }),
  );
  if (packageDigest !== publication.packageSha256)
    throw new Error("Publication package deterministic digest mismatch.");

  for (const entry of media) {
    const file = resolve(root, ...entry.publicPath.split("/"));
    if (!isInside(file, root)) throw new Error(`Unsafe media location: ${entry.publicPath}.`);
    const stat = await regularFile(file, entry.publicPath);
    if (stat.size !== entry.size) throw new Error(`Publication media size mismatch: ${entry.publicPath}.`);
    if (sha256(await readFile(file)) !== entry.sha256)
      throw new Error(`Publication media SHA-256 mismatch: ${entry.publicPath}.`);
  }

  const expected = ["manifest.json", "publication.json", ...media.map((entry) => entry.publicPath)].sort();
  const actual = await walkPackage(root);
  if (actual.length !== expected.length || actual.some((file, index) => file !== expected[index]))
    throw new Error("Publication package file inventory contains missing or extra files.");

  return { root, manifestPath, manifestBytes, manifest, media };
}
