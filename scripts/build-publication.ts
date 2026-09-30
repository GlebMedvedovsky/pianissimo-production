import { randomBytes } from "node:crypto";
import { spawnSync } from "node:child_process";
import {
  copyFile,
  lstat,
  mkdir,
  mkdtemp,
  readFile,
  readdir,
  realpath,
  rename,
  rm,
  writeFile,
} from "node:fs/promises";
import { dirname, relative, resolve, sep } from "node:path";
import {
  isInside,
  sha256,
  validatePublicationPackage,
  type PublicationMedia,
} from "./publication-package";

const repositoryRoot = resolve(process.cwd());
const frontendRoot = resolve(repositoryRoot, "frontend");
const localBuildRoot = resolve(repositoryRoot, ".local-builds");
const staticPublicFiles = ["favicon.svg", "robots.txt"] as const;

const exists = async (path: string) => Boolean(await lstat(path).catch(() => null));

const assertNoSymlinkedOutputAncestor = async (output: string) => {
  let probe = dirname(output);
  while (!(await exists(probe))) {
    const parent = dirname(probe);
    if (parent === probe) throw new Error("Publication output path cannot be resolved safely.");
    probe = parent;
  }
  const resolved = resolve(await realpath(probe));
  if (process.platform === "win32" ? resolved.toLowerCase() !== resolve(probe).toLowerCase() : resolved !== resolve(probe))
    throw new Error("Publication output path may not traverse symlinks.");
};

export async function assertSafeOutputPath(outputPath: string, packageRoot: string) {
  const output = resolve(outputPath);
  if (await exists(output)) throw new Error("Publication output path must not already exist.");
  if (isInside(output, packageRoot) || isInside(packageRoot, output))
    throw new Error("Publication output and input package paths must not overlap.");
  if (isInside(output, repositoryRoot) && !isInside(output, localBuildRoot))
    throw new Error("Publication output inside repository source locations is forbidden.");
  if (output === localBuildRoot)
    throw new Error("Publication output must be a child of .local-builds, not the root itself.");
  await assertNoSymlinkedOutputAncestor(output);
  return output;
}

const copyStaticPublicFiles = async (targetRoot: string) => {
  for (const relativePath of staticPublicFiles) {
    const source = resolve(frontendRoot, "public", relativePath);
    const stat = await lstat(source).catch(() => null);
    if (!stat?.isFile() || stat.isSymbolicLink())
      throw new Error(`Static frontend asset is missing or unsafe: ${relativePath}.`);
    const target = resolve(targetRoot, relativePath);
    await mkdir(dirname(target), { recursive: true });
    await copyFile(source, target);
  }
};

const copyPackageMedia = async (
  packageRoot: string,
  targetRoot: string,
  media: PublicationMedia[],
) => {
  for (const entry of media) {
    const target = resolve(targetRoot, ...entry.publicPath.split("/"));
    await mkdir(dirname(target), { recursive: true });
    await copyFile(resolve(packageRoot, ...entry.publicPath.split("/")), target);
    const stat = await lstat(target);
    if (stat.size !== entry.size || sha256(await readFile(target)) !== entry.sha256)
      throw new Error(`Staged media failed verification: ${entry.publicPath}.`);
  }
};

const walkOutput = async (root: string) => {
  const files: string[] = [];
  const visit = async (directory: string) => {
    for (const entry of await readdir(directory, { withFileTypes: true })) {
      const path = resolve(directory, entry.name);
      const stat = await lstat(path);
      if (stat.isSymbolicLink()) throw new Error("Static output contains a symlink.");
      if (stat.isDirectory()) await visit(path);
      else if (stat.isFile()) files.push(relative(root, path).split(sep).join("/"));
      else throw new Error("Static output contains a special file.");
    }
  };
  await visit(root);
  return files.sort();
};

async function validateStaticOutput(root: string, media: PublicationMedia[]) {
  const files = await walkOutput(root);
  const actualMedia = files.filter((path) => path.startsWith("media/"));
  const expectedMedia = media.map((entry) => entry.publicPath).sort();
  if (
    actualMedia.length !== expectedMedia.length ||
    actualMedia.some((path, index) => path !== expectedMedia[index])
  )
    throw new Error("Static output media differs from the publication allowlist.");
  for (const entry of media) {
    const path = resolve(root, ...entry.publicPath.split("/"));
    const stat = await lstat(path);
    if (stat.size !== entry.size || sha256(await readFile(path)) !== entry.sha256)
      throw new Error(`Built media failed verification: ${entry.publicPath}.`);
  }
}

const removeOwnedStage = async (stage: string, marker: string) => {
  const markerPath = resolve(stage, ".pianissimo-stage-owner");
  if (!isInside(stage, dirname(stage)) || (await readFile(markerPath, "utf8").catch(() => "")) !== marker)
    throw new Error("Refusing to clean an unowned publication staging directory.");
  await rm(stage, { recursive: true, force: true });
};

export async function buildPublication(packagePath: string, outputPath: string) {
  const publication = await validatePublicationPackage(packagePath);
  const output = await assertSafeOutputPath(outputPath, publication.root);
  await mkdir(dirname(output), { recursive: true });
  const stage = await mkdtemp(resolve(dirname(output), ".pianissimo-publication-build-"));
  const marker = randomBytes(24).toString("hex");
  await writeFile(resolve(stage, ".pianissimo-stage-owner"), marker, { flag: "wx" });
  const publicDir = resolve(stage, "public");
  const outDir = resolve(stage, "output");
  const manifestPath = resolve(stage, "manifest.json");
  try {
    await mkdir(publicDir);
    await writeFile(manifestPath, publication.manifestBytes, { flag: "wx" });
    await copyStaticPublicFiles(publicDir);
    await copyPackageMedia(publication.root, publicDir, publication.media);
    const build = spawnSync(
      process.execPath,
      [resolve(repositoryRoot, "scripts", "astro.mjs"), "build"],
      {
        cwd: frontendRoot,
        env: {
          ...process.env,
          PIANISSIMO_PUBLICATION_BUILD: "1",
          PIANISSIMO_MANIFEST_PATH: manifestPath,
          PIANISSIMO_PUBLIC_DIR: publicDir,
          PIANISSIMO_OUT_DIR: outDir,
        },
        stdio: "inherit",
      },
    );
    if (build.status !== 0) throw new Error("Astro publication build failed.");
    await validateStaticOutput(outDir, publication.media);
    const isolation = spawnSync(
      process.execPath,
      [resolve(repositoryRoot, "scripts", "check-dist.mjs"), outDir],
      { cwd: repositoryRoot, stdio: "inherit" },
    );
    if (isolation.status !== 0) throw new Error("Publication build isolation validation failed.");
    await rename(outDir, output);
    await removeOwnedStage(stage, marker);
    console.log(`Publication static build created: ${output}`);
    return output;
  } catch (error) {
    await removeOwnedStage(stage, marker);
    throw error;
  }
}

export function parsePublicationBuildArguments(values: string[]) {
  let packagePath: string | undefined;
  let outputPath: string | undefined;
  for (let index = 0; index < values.length; index += 2) {
    const flag = values[index];
    const value = values[index + 1];
    if (!value || (flag !== "--package" && flag !== "--out"))
      throw new Error("Usage: npm run build:publication -- --package <path> --out <path>");
    if (flag === "--package" && packagePath) throw new Error("--package may be supplied only once.");
    if (flag === "--out" && outputPath) throw new Error("--out may be supplied only once.");
    if (flag === "--package") packagePath = value;
    if (flag === "--out") outputPath = value;
  }
  if (!packagePath || !outputPath)
    throw new Error("Usage: npm run build:publication -- --package <path> --out <path>");
  return { packagePath, outputPath };
}
