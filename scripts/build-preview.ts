import { createHash, randomBytes } from "node:crypto";
import { spawnSync } from "node:child_process";
import { copyFile, lstat, mkdir, mkdtemp, readFile, readdir, realpath, rename, rm, writeFile } from "node:fs/promises";
import { dirname, relative, resolve, sep } from "node:path";
import { validateManifest } from "../contracts/content";

const repositoryRoot = resolve(process.cwd());
const frontendRoot = resolve(repositoryRoot, "frontend");
const localBuildRoot = resolve(repositoryRoot, ".local-builds");
const HASH = /^[a-f0-9]{64}$/;
const REVISION = /^[a-f0-9]{40}$/;
const IDENTIFIER = /^[a-f0-9]{32}$/;
const canonicalize = (value: unknown): unknown => Array.isArray(value) ? value.map(canonicalize) : value && typeof value === "object" ? Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonicalize((value as Record<string, unknown>)[key])])) : value;
const canonicalJson = (value: unknown) => JSON.stringify(canonicalize(value));
const sha256 = (bytes: string | Buffer) => createHash("sha256").update(bytes).digest("hex");
const inside = (candidate: string, root: string) => candidate === root || candidate.startsWith(root + sep);

type Media = { assetId: string; publicPath: string; sha256: string; size: number; type: "image" | "audio" | "video" };

async function validatePackage(packagePath: string) {
  const root = resolve(await realpath(resolve(packagePath)));
  if ((await lstat(root)).isSymbolicLink()) throw new Error("Preview package root may not be a symlink.");
  const metadataBytes = await readFile(resolve(root, "preview.json"), "utf8");
  const manifestBytes = await readFile(resolve(root, "manifest.json"), "utf8");
  const metadata = JSON.parse(metadataBytes) as Record<string, unknown>;
  if (Object.keys(metadata).join(",") !== "contractVersion,createdAt,draftHash,manifestSha256,media,packageSha256,previewId,previewIdentifier,sourceRevision"
    || metadataBytes !== canonicalJson(metadata) + "\n" || metadata.contractVersion !== 2
    || !IDENTIFIER.test(String(metadata.previewIdentifier)) || !Number.isInteger(metadata.previewId) || Number(metadata.previewId) < 1
    || !HASH.test(String(metadata.draftHash)) || !HASH.test(String(metadata.manifestSha256)) || !HASH.test(String(metadata.packageSha256))
    || !REVISION.test(String(metadata.sourceRevision)) || !Array.isArray(metadata.media)) throw new Error("Invalid preview package metadata.");
  if (sha256(manifestBytes) !== metadata.manifestSha256) throw new Error("Preview manifest digest mismatch.");
  const manifest = validateManifest(JSON.parse(manifestBytes), false, "draft-preview");
  if (manifest.site.analytics?.enabled !== false) throw new Error("Analytics must be disabled in preview packages.");
  const media = metadata.media as Media[];
  let previous = "";
  const expected = new Set(["manifest.json", "preview.json"]);
  for (const item of media) {
    if (!item || Object.keys(item).join(",") !== "assetId,publicPath,sha256,size,type" || typeof item.assetId !== "string" || !/^media\/preview\/[a-z0-9-]+\.(?:webp|jpg|png|mp3|wav|ogg|mp4|webm)$/.test(item.publicPath)
      || item.publicPath <= previous || !HASH.test(item.sha256) || !Number.isInteger(item.size) || item.size < 0
      || !["image", "audio", "video"].includes(item.type)) throw new Error("Invalid preview media inventory.");
    previous = item.publicPath;
  }
  const logical = sha256(canonicalJson({ contractVersion: metadata.contractVersion, draftHash: metadata.draftHash, manifestSha256: metadata.manifestSha256, media, sourceRevision: metadata.sourceRevision }));
  if (logical !== metadata.packageSha256) throw new Error("Preview package logical digest mismatch.");
  const actual: string[] = [];
  const walk = async (directory: string) => { for (const entry of await readdir(directory, { withFileTypes: true })) { const path = resolve(directory, entry.name); const stat = await lstat(path); if (stat.isSymbolicLink()) throw new Error("Preview package contains a symlink."); if (stat.isDirectory()) await walk(path); else if (stat.isFile()) actual.push(relative(root, path).split(sep).join("/")); else throw new Error("Preview package contains a special file."); } };
  await walk(root);
  if (actual.length !== expected.size || actual.some((path) => !expected.has(path))) throw new Error("Preview package file inventory mismatch.");
  return { root, manifestBytes, media, previewId: Number(metadata.previewId) };
}

export async function buildPreview(packagePath: string, outputPath: string) {
  const preview = await validatePackage(packagePath);
  const output = resolve(outputPath);
  if (await lstat(output).catch(() => null)) throw new Error("Preview output path must be fresh.");
  if (inside(output, preview.root) || inside(preview.root, output)) throw new Error("Preview input and output paths may not overlap.");
  if (inside(output, repositoryRoot) && !inside(output, localBuildRoot)) throw new Error("Preview output inside repository source locations is forbidden.");
  await mkdir(dirname(output), { recursive: true });
  const stage = await mkdtemp(resolve(dirname(output), ".pianissimo-preview-build-"));
  const marker = randomBytes(24).toString("hex");
  await writeFile(resolve(stage, ".pianissimo-stage-owner"), marker, { flag: "wx" });
  try {
    const publicDir = resolve(stage, "public"); const outDir = resolve(stage, "output"); const manifestPath = resolve(stage, "manifest.json");
    await mkdir(publicDir); await writeFile(manifestPath, preview.manifestBytes, { flag: "wx" });
    for (const name of ["favicon.svg", "robots.txt"]) { const source = resolve(frontendRoot, "public", name); const stat = await lstat(source); if (!stat.isFile() || stat.isSymbolicLink()) throw new Error("Static preview asset is unsafe."); await copyFile(source, resolve(publicDir, name)); }
    const build = spawnSync(process.execPath, [resolve(repositoryRoot, "scripts", "astro.mjs"), "build"], { cwd: frontendRoot, env: { ...process.env, PIANISSIMO_PUBLICATION_BUILD: "1", PIANISSIMO_PREVIEW_BUILD: "1", PIANISSIMO_PREVIEW_ASSET_BASE: "/draft-preview/assets/", PIANISSIMO_MANIFEST_PATH: manifestPath, PIANISSIMO_PUBLIC_DIR: publicDir, PIANISSIMO_OUT_DIR: outDir }, stdio: "inherit" });
    if (build.status !== 0) throw new Error("Astro preview build failed.");
    const isolation = spawnSync(process.execPath, [resolve(repositoryRoot, "scripts", "check-dist.mjs"), outDir], { cwd: repositoryRoot, stdio: "inherit" });
    if (isolation.status !== 0) throw new Error("Preview build isolation validation failed.");
    await rename(outDir, output); await rm(stage, { recursive: true, force: true });
    return output;
  } catch (error) { await rm(stage, { recursive: true, force: true }); throw error; }
}
