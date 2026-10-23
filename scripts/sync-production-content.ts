import { createHash, randomUUID } from "node:crypto";
import { execFile } from "node:child_process";
import { basename, resolve } from "node:path";
import { promisify } from "node:util";
import { open, readFile, rm } from "node:fs/promises";
import { validateManifest } from "../contracts/content";
import {
  canonicalJson,
  sha256,
  type PublicationMedia,
} from "./publication-package";
import {
  ProductionContentStore,
  type LocalActiveSnapshot,
} from "./production-content-store";

const execFileAsync = promisify(execFile);
const HASH = /^[a-f0-9]{64}$/;
const REVISION = /^[a-f0-9]{40}$/;
const UUID_V4 = /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/;
const API_PATH = "/api/content-sync/v1";
const MEDIA_EXTENSIONS: Record<PublicationMedia["type"], Set<string>> = {
  image: new Set(["webp", "jpg", "jpeg", "png"]),
  audio: new Set(["mp3", "wav", "ogg"]),
  video: new Set(["mp4", "webm"]),
};

type Environment = Record<string, string | undefined>;
type ActivePublication = {
  contractVersion: 1;
  publicationId: string;
  sequence: number;
  activatedAt: string;
  sourceRevision: string;
  packageSha256: string;
  manifestSha256: string;
  releaseSha256: string | null;
  publicationUrl: string;
  manifestUrl: string;
  mediaUrlTemplate: string;
};
type PublicationDocument = {
  contractVersion: 1;
  publicationId: string;
  sequence: number;
  candidateHash: string;
  planHash: string;
  sourceRevision: string;
  manifestSha256: string;
  packageSha256: string;
  createdAt: string;
  media: PublicationMedia[];
};

export type SyncSummary = Pick<
  ActivePublication,
  "sequence" | "publicationId" | "packageSha256" | "manifestSha256" | "sourceRevision"
> & {
  reusedMedia: number;
  downloadedMedia: number;
  revisionMismatch: boolean;
  currentRevision: string | null;
};

export type SyncProductionContentOptions = {
  repoRoot?: string;
  storeRoot?: string;
  env?: Environment;
  fetch?: typeof fetch;
  getGitHead?: () => Promise<string | null>;
};

const exactObject = (value: unknown, keys: string[], label: string) => {
  if (!value || typeof value !== "object" || Array.isArray(value)) throw new Error(`${label} is malformed.`);
  const record = value as Record<string, unknown>;
  const actual = Object.keys(record).sort();
  const expected = [...keys].sort();
  if (actual.length !== expected.length || actual.some((key, index) => key !== expected[index]))
    throw new Error(`${label} contract is malformed.`);
  return record;
};

const parseJson = (bytes: string, label: string) => {
  try {
    return JSON.parse(bytes) as unknown;
  } catch {
    throw new Error(`${label} contains malformed JSON.`);
  }
};

const parseActive = (value: unknown): ActivePublication => {
  const record = exactObject(value, [
    "contractVersion", "publicationId", "sequence", "activatedAt", "sourceRevision",
    "packageSha256", "manifestSha256", "releaseSha256", "publicationUrl", "manifestUrl",
    "mediaUrlTemplate",
  ], "Active production response");
  if (record.contractVersion !== 1) throw new Error("Unsupported content-sync contract version.");
  if (!UUID_V4.test(String(record.publicationId))) throw new Error("Active publication ID is invalid.");
  if (!Number.isSafeInteger(record.sequence) || Number(record.sequence) < 1)
    throw new Error("Active publication sequence is invalid.");
  if (typeof record.activatedAt !== "string" || !Number.isFinite(Date.parse(record.activatedAt)))
    throw new Error("Active publication timestamp is invalid.");
  if (!REVISION.test(String(record.sourceRevision))) throw new Error("Active source revision is invalid.");
  if (!HASH.test(String(record.packageSha256)) || !HASH.test(String(record.manifestSha256)))
    throw new Error("Active publication digest is invalid.");
  if (record.releaseSha256 !== null && !HASH.test(String(record.releaseSha256)))
    throw new Error("Active release digest is invalid.");
  const publicationId = String(record.publicationId);
  const expectedPublication = `${API_PATH}/publications/${publicationId}/publication.json`;
  const expectedManifest = `${API_PATH}/publications/${publicationId}/manifest.json`;
  const expectedMedia = `${API_PATH}/publications/${publicationId}/media/{sha256}`;
  if (
    record.publicationUrl !== expectedPublication ||
    record.manifestUrl !== expectedManifest ||
    record.mediaUrlTemplate !== expectedMedia
  ) throw new Error("Active publication endpoints are not canonical.");
  return record as unknown as ActivePublication;
};

const safeMediaPath = (value: unknown) => {
  if (typeof value !== "string" || !value.startsWith("media/") || value.includes("\\"))
    throw new Error("Publication media path is unsafe.");
  const parts = value.split("/");
  if (parts.some((part) => !part || part === "." || part === "..") || /[:*?"<>|]/.test(value))
    throw new Error("Publication media path is unsafe.");
  return value;
};

const parsePublication = (
  publicationBytes: string,
  manifestBytes: string,
  active: ActivePublication,
): PublicationDocument => {
  const record = exactObject(parseJson(publicationBytes, "publication.json"), [
    "candidateHash", "contractVersion", "createdAt", "manifestSha256", "media",
    "packageSha256", "planHash", "publicationId", "sequence", "sourceRevision",
  ], "publication.json");
  if (publicationBytes !== `${canonicalJson(record)}\n`)
    throw new Error("publication.json is not canonical immutable metadata.");
  if (record.contractVersion !== 1) throw new Error("Unsupported publication contract version.");
  for (const key of ["candidateHash", "planHash", "manifestSha256", "packageSha256"])
    if (!HASH.test(String(record[key]))) throw new Error("publication.json contains an invalid digest.");
  if (!UUID_V4.test(String(record.publicationId)) || !REVISION.test(String(record.sourceRevision)))
    throw new Error("publication.json identity is invalid.");
  if (!Number.isSafeInteger(record.sequence) || Number(record.sequence) < 1)
    throw new Error("publication.json sequence is invalid.");
  if (typeof record.createdAt !== "string" || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(record.createdAt))
    throw new Error("publication.json timestamp is invalid.");
  if (!Array.isArray(record.media)) throw new Error("publication.json media inventory is invalid.");
  if (
    record.publicationId !== active.publicationId || record.sequence !== active.sequence ||
    record.sourceRevision !== active.sourceRevision || record.packageSha256 !== active.packageSha256 ||
    record.manifestSha256 !== active.manifestSha256
  ) throw new Error("Active and immutable publication identities do not agree.");
  if (sha256(manifestBytes) !== record.manifestSha256)
    throw new Error("Downloaded manifest SHA-256 does not match publication metadata.");

  const manifest = validateManifest(parseJson(manifestBytes, "manifest.json"), true);
  if (manifest.mode !== "release" || manifest.sources.length || manifest.projects.some((item) => item.sourceIds.length))
    throw new Error("Downloaded manifest violates release policy.");
  const assets = new Map(manifest.assets.map((asset) => [asset.path, asset]));
  const paths = new Set<string>();
  const assetIds = new Set<string>();
  const media = record.media.map((value, index) => {
    const entry = exactObject(value, ["assetId", "publicPath", "sha256", "size", "type"], `media[${index}]`);
    const publicPath = safeMediaPath(entry.publicPath);
    if (paths.has(publicPath)) throw new Error("Publication contains duplicate media paths.");
    if (typeof entry.assetId !== "string" || !entry.assetId || assetIds.has(entry.assetId))
      throw new Error("Publication contains duplicate or invalid media asset IDs.");
    if (!HASH.test(String(entry.sha256)) || !Number.isSafeInteger(entry.size) || Number(entry.size) < 0)
      throw new Error("Publication media integrity metadata is invalid.");
    if (!(["image", "audio", "video"] as const).includes(entry.type as PublicationMedia["type"]))
      throw new Error("Publication media type is invalid.");
    const type = entry.type as PublicationMedia["type"];
    const extension = publicPath.slice(publicPath.lastIndexOf(".") + 1).toLowerCase();
    if (!MEDIA_EXTENSIONS[type].has(extension))
      throw new Error("Publication media extension does not match its type.");
    const asset = assets.get(publicPath);
    if (!asset || asset.id !== entry.assetId || asset.type !== entry.type || asset.sha256 !== entry.sha256)
      throw new Error("Publication media inventory does not agree with the manifest.");
    paths.add(publicPath);
    assetIds.add(entry.assetId);
    return {
      assetId: entry.assetId,
      publicPath,
      sha256: String(entry.sha256),
      size: Number(entry.size),
      type,
    };
  });
  if (media.some((entry, index) => index > 0 && media[index - 1]!.publicPath >= entry.publicPath))
    throw new Error("Publication media inventory is not uniquely sorted.");
  if (assets.size !== media.length || [...assets.keys()].some((path) => !paths.has(path)))
    throw new Error("Publication media inventory does not agree with the manifest.");
  const packageSha256 = sha256(canonicalJson({
    contractVersion: record.contractVersion,
    candidateHash: record.candidateHash,
    planHash: record.planHash,
    sourceRevision: record.sourceRevision,
    manifestSha256: record.manifestSha256,
    media,
  }));
  if (packageSha256 !== record.packageSha256) throw new Error("Publication package digest does not match.");
  return { ...record, media } as unknown as PublicationDocument;
};

const parseLocalEnv = async (path: string) => {
  const bytes = await readFile(path, "utf8").catch((error: NodeJS.ErrnoException) => {
    if (error.code === "ENOENT") return "";
    throw error;
  });
  const values: Environment = {};
  for (const rawLine of bytes.split(/\r?\n/)) {
    const line = rawLine.trim();
    if (!line || line.startsWith("#")) continue;
    const match = /^(CMS_CONTENT_SYNC_URL|CMS_CONTENT_SYNC_API_TOKEN)\s*=\s*(.*)$/.exec(line);
    if (!match) continue;
    let value = match[2]!.trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'")))
      value = value.slice(1, -1);
    values[match[1]!] = value;
  }
  return values;
};

const configuration = async (repoRoot: string, environment: Environment) => {
  const local = await parseLocalEnv(resolve(repoRoot, ".env.local"));
  const urlValue = environment.CMS_CONTENT_SYNC_URL || local.CMS_CONTENT_SYNC_URL;
  const token = environment.CMS_CONTENT_SYNC_API_TOKEN || local.CMS_CONTENT_SYNC_API_TOKEN;
  if (!urlValue || !token) throw new Error("CMS content-sync URL and API token must be configured.");
  if (token.length < 32) throw new Error("CMS content-sync API token is invalid.");
  let configured: URL;
  try { configured = new URL(urlValue); } catch { throw new Error("CMS content-sync URL is invalid."); }
  if (configured.username || configured.password || configured.search || configured.hash)
    throw new Error("CMS content-sync URL must not contain credentials, query parameters, or fragments.");
  const loopback = configured.hostname === "127.0.0.1" || configured.hostname === "localhost" || configured.hostname === "::1";
  if (configured.protocol !== "https:" && !(configured.protocol === "http:" && loopback))
    throw new Error("CMS content-sync URL must use HTTPS except for loopback development.");
  const trimmed = configured.pathname.replace(/\/+$/, "");
  configured.pathname = trimmed.endsWith(API_PATH) ? `${trimmed}/` : `${API_PATH}/`;
  return { apiBase: configured, token };
};

const currentGitHead = async (repoRoot: string) => {
  try {
    const { stdout } = await execFileAsync("git", ["rev-parse", "HEAD"], { cwd: repoRoot, encoding: "utf8" });
    const revision = stdout.trim().toLowerCase();
    return REVISION.test(revision) ? revision : null;
  } catch {
    return null;
  }
};

export async function syncProductionContent(options: SyncProductionContentOptions = {}): Promise<SyncSummary> {
  const repoRoot = resolve(options.repoRoot ?? process.cwd());
  const store = new ProductionContentStore(options.storeRoot ?? resolve(repoRoot, ".local-content"));
  const fetchImpl = options.fetch ?? fetch;
  const { apiBase, token } = await configuration(repoRoot, options.env ?? process.env);
  const releaseLock = await store.acquireLock();
  const headers = { Authorization: `Bearer ${token}`, Accept: "application/json" };
  const request = async (path: string, accept = "application/json") => {
    const url = new URL(path, apiBase.origin);
    if (url.origin !== apiBase.origin || !url.pathname.startsWith(`${API_PATH}/`))
      throw new Error("Content-sync endpoint escaped the configured API origin.");
    let response: Response;
    try {
      response = await fetchImpl(url, {
        method: "GET",
        headers: { ...headers, Accept: accept },
        redirect: "error",
      });
    } catch {
      throw new Error("Content-sync API request failed.");
    }
    if (!response.ok || response.redirected) throw new Error("Content-sync API request failed.");
    return response;
  };
  const fetchJsonText = async (path: string) => {
    const response = await request(path);
    const type = response.headers.get("content-type")?.split(";", 1)[0]?.trim().toLowerCase();
    if (type !== "application/json") throw new Error("Content-sync API returned an unexpected response type.");
    return response.text();
  };
  const discover = async () => parseActive(parseJson(await fetchJsonText(`${API_PATH}/production/active`), "Active production response"));

  try {
    await store.resetStaging();
    for (let attempt = 0; attempt < 2; attempt += 1) {
      const active = await discover();
      const publicationBytes = await fetchJsonText(active.publicationUrl);
      const manifestBytes = await fetchJsonText(active.manifestUrl);
      const publication = parsePublication(publicationBytes, manifestBytes, active);
      const staging = await store.createStagingDirectory(`sync-${attempt}-`);
      let reusedMedia = 0;
      let downloadedMedia = 0;
      try {
        for (const entry of publication.media) {
          if (await store.hasVerifiedBlob(entry.sha256, entry.size)) {
            reusedMedia += 1;
            continue;
          }
          const response = await request(
            active.mediaUrlTemplate.replace("{sha256}", entry.sha256),
            "application/octet-stream",
          );
          const contentType = response.headers.get("content-type")?.toLowerCase() ?? "";
          if (contentType.startsWith("application/json") || contentType.startsWith("text/"))
            throw new Error("Content-sync media endpoint returned an unexpected response.");
          const length = response.headers.get("content-length");
          if (length !== null && (!/^\d+$/.test(length) || length !== String(entry.size)))
            throw new Error("Content-sync media Content-Length does not match metadata.");
          const etag = response.headers.get("etag");
          if (etag !== null && etag !== `"${entry.sha256}"`)
            throw new Error("Content-sync media ETag does not match metadata.");
          if (!response.body) throw new Error("Content-sync media response has no body.");
          const part = resolve(staging, `${entry.sha256}.${randomUUID()}.part`);
          const file = await open(part, "wx", 0o600);
          const hash = createHash("sha256");
          let size = 0;
          try {
            for await (const raw of response.body as unknown as AsyncIterable<Uint8Array>) {
              const chunk = Buffer.from(raw);
              size += chunk.length;
              if (size > entry.size) throw new Error("Downloaded media exceeds its declared size.");
              hash.update(chunk);
              await file.write(chunk);
            }
            await file.sync();
          } finally {
            await file.close();
          }
          if (size !== entry.size || hash.digest("hex") !== entry.sha256) {
            await rm(part, { force: true });
            throw new Error("Downloaded media failed size or SHA-256 verification.");
          }
          await store.installBlob(part, entry.sha256, entry.size);
          downloadedMedia += 1;
        }
        await store.assemblePackage(staging, active.packageSha256, publicationBytes, manifestBytes, publication.media);
      } finally {
        await rm(staging, { recursive: true, force: true });
      }

      const confirmed = await discover();
      if (canonicalJson(confirmed) !== canonicalJson(active)) {
        if (attempt === 0) continue;
        throw new Error("Active production publication changed repeatedly during synchronization.");
      }
      const activeSnapshot: LocalActiveSnapshot = {
        contractVersion: 1,
        publicationId: active.publicationId,
        sequence: active.sequence,
        activatedAt: active.activatedAt,
        sourceRevision: active.sourceRevision,
        packageSha256: active.packageSha256,
        manifestSha256: active.manifestSha256,
        releaseSha256: active.releaseSha256,
        packagePath: `packages/${active.packageSha256}`,
      };
      await store.activate(activeSnapshot);
      const currentRevision = await (options.getGitHead ?? (() => currentGitHead(repoRoot)))();
      return {
        sequence: active.sequence,
        publicationId: active.publicationId,
        packageSha256: active.packageSha256,
        manifestSha256: active.manifestSha256,
        sourceRevision: active.sourceRevision,
        reusedMedia,
        downloadedMedia,
        revisionMismatch: currentRevision !== null && currentRevision !== active.sourceRevision,
        currentRevision,
      };
    }
    throw new Error("Production-content synchronization exhausted its retry limit.");
  } finally {
    await releaseLock();
  }
}

type SyncOutput = { log: (message: string) => void; warn: (message: string) => void; error: (message: string) => void };

const printSummary = (summary: SyncSummary, output: SyncOutput) => {
  output.log(`Production content synchronized.`);
  output.log(`sequence: ${summary.sequence}`);
  output.log(`publicationId: ${summary.publicationId}`);
  output.log(`packageSha256: ${summary.packageSha256}`);
  output.log(`manifestSha256: ${summary.manifestSha256}`);
  output.log(`sourceRevision: ${summary.sourceRevision}`);
  output.log(`media: ${summary.reusedMedia} reused, ${summary.downloadedMedia} downloaded`);
  if (summary.revisionMismatch)
    output.warn(`Warning: current Git revision differs from the publication source revision.`);
};

export async function runSyncProductionContentCli(
  options: SyncProductionContentOptions = {},
  output: SyncOutput = console,
) {
  let token = options.env?.CMS_CONTENT_SYNC_API_TOKEN ?? process.env.CMS_CONTENT_SYNC_API_TOKEN ?? "";
  try {
    const summary = await syncProductionContent(options);
    printSummary(summary, output);
    return 0;
  } catch (error) {
    let message = error instanceof Error ? error.message : "Production-content synchronization failed.";
    if (token) message = message.replaceAll(token, "[redacted]");
    output.error(`Production-content synchronization failed: ${message}`);
    return 1;
  } finally {
    token = "";
  }
}

if (basename(process.argv[1] ?? "") === "sync-production-content.mjs")
  process.exitCode = await runSyncProductionContentCli();
