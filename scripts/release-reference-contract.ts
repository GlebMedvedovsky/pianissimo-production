import { lstat, readFile } from "node:fs/promises";
import { isAbsolute, relative, resolve, sep } from "node:path";

type ReleaseFile = { path: string; sha256: string; size: number };

const TEXT_FILE = /\.(?:html?|css|m?js|json|xml|svg)$/i;
const MEDIA_REFERENCE = /\/media\/([A-Za-z0-9._~!$&*+,;=:@%/-]+)/g;

const safePath = (value: string) => {
  const decoded = decodeURIComponent(value.split(/[?#]/, 1)[0]!);
  const parts = `media/${decoded}`.split("/");
  if (parts.some((part) => !part || part === "." || part === "..") || parts.join("/") !== `media/${decoded}`)
    throw new Error("Static release has an unsafe media reference.");
  return parts.join("/");
};

export async function validateReleaseTextReferences(
  outputDirectory: string,
  inventory: ReleaseFile[],
) {
  const root = resolve(outputDirectory);
  const known = new Set(inventory.map((entry) => entry.path));
  for (const entry of inventory) {
    if (!TEXT_FILE.test(entry.path)) continue;
    const path = resolve(root, ...entry.path.split("/"));
    const localPath = relative(root, path);
    if (!localPath || localPath === ".." || localPath.startsWith(`..${sep}`) || isAbsolute(localPath))
      throw new Error("Static release inventory contains an unsafe path.");
    const stat = await lstat(path);
    if (!stat.isFile() || stat.isSymbolicLink())
      throw new Error("Static release inventory contains an unsafe file.");
    const bytes = await readFile(path, "utf8");
    for (const match of bytes.matchAll(MEDIA_REFERENCE)) {
      let referenced: string;
      try {
        referenced = safePath(match[1]!);
      } catch {
        throw new Error("Static release has an unsafe media reference.");
      }
      if (!known.has(referenced))
        throw new Error(`Static release references missing media: ${referenced}.`);
    }
  }
}
