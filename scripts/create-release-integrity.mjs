import { createHash } from "node:crypto";
import { createReadStream } from "node:fs";
import { lstat, readdir, readFile, writeFile } from "node:fs/promises";
import { relative, resolve, sep } from "node:path";
import { fileURLToPath } from "node:url";

export const RELEASE_METADATA_FILE = ".pianissimo-release.json";
export const RELEASE_FILES_FILE = ".pianissimo-release-files.json";

const sha256File = (path) => new Promise((resolveHash, reject) => {
  const hash = createHash("sha256");
  const stream = createReadStream(path);
  stream.on("error", reject);
  stream.on("data", (chunk) => hash.update(chunk));
  stream.on("end", () => resolveHash(hash.digest("hex")));
});

const comparePaths = (left, right) => left < right ? -1 : left > right ? 1 : 0;

export async function createReleaseIntegrity(outputDirectory, publicationPath) {
  const root = resolve(outputDirectory);
  const metadataPath = resolve(root, RELEASE_METADATA_FILE);
  const inventoryPath = resolve(root, RELEASE_FILES_FILE);
  for (const path of [metadataPath, inventoryPath]) {
    if (await lstat(path).catch(() => null)) throw new Error("Release integrity metadata already exists.");
  }

  const files = [];
  const visit = async (directory) => {
    const entries = await readdir(directory, { withFileTypes: true });
    entries.sort((left, right) => comparePaths(left.name, right.name));
    for (const entry of entries) {
      const path = resolve(directory, entry.name);
      const stat = await lstat(path);
      if (stat.isSymbolicLink()) throw new Error("Static release contains a symlink.");
      if (stat.isDirectory()) {
        await visit(path);
      } else if (stat.isFile()) {
        const normalized = relative(root, path).split(sep).join("/");
        if (!normalized || normalized.startsWith("../") || normalized.includes("/../"))
          throw new Error("Static release contains an unsafe path.");
        files.push({ path: normalized, sha256: await sha256File(path), size: stat.size });
      } else {
        throw new Error("Static release contains a special file.");
      }
    }
  };
  await visit(root);
  files.sort((left, right) => comparePaths(left.path, right.path));

  const inventoryBytes = `${JSON.stringify(files)}\n`;
  const releaseFilesSha256 = createHash("sha256").update(inventoryBytes).digest("hex");
  const publication = JSON.parse(await readFile(resolve(publicationPath), "utf8"));
  const metadata = {
    releaseContractVersion: 1,
    publicationId: publication.publicationId ?? publication.previewIdentifier,
    sequence: publication.sequence ?? publication.previewId,
    sourceRevision: publication.sourceRevision,
    packageSha256: publication.packageSha256,
    manifestSha256: publication.manifestSha256,
    releaseFilesSha256,
    media: publication.media.map(({ publicPath, sha256, size, type }) => ({ publicPath, sha256, size, type })),
  };
  await writeFile(inventoryPath, inventoryBytes, { flag: "wx" });
  await writeFile(metadataPath, `${JSON.stringify(metadata)}\n`, { flag: "wx" });

  return releaseFilesSha256;
}

if (process.argv[1] && resolve(process.argv[1]) === resolve(fileURLToPath(import.meta.url))) {
  const [outputDirectory, publicationPath] = process.argv.slice(2);
  if (!outputDirectory || !publicationPath) {
    throw new Error("Usage: node scripts/create-release-integrity.mjs <static-output> <publication.json>");
  }
  process.stdout.write(`${await createReleaseIntegrity(outputDirectory, publicationPath)}\n`);
}
