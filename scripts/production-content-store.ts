import { createHash, randomUUID } from "node:crypto";
import { createReadStream } from "node:fs";
import {
  copyFile,
  lstat,
  mkdir,
  mkdtemp,
  open,
  readFile,
  realpath,
  rename,
  rm,
  unlink,
  writeFile,
} from "node:fs/promises";
import { isAbsolute, relative, resolve, sep } from "node:path";
import {
  validatePublicationPackage,
  type PublicationMedia,
} from "./publication-package";

const SHA256 = /^[a-f0-9]{64}$/;

const comparable = (value: string) =>
  (process.platform === "win32" ? value.toLowerCase() : value).replaceAll("\\", "/");

export type LocalActiveSnapshot = {
  contractVersion: 1;
  publicationId: string;
  sequence: number;
  activatedAt: string;
  sourceRevision: string;
  packageSha256: string;
  manifestSha256: string;
  releaseSha256: string | null;
  packagePath: string;
};

export class ProductionContentStore {
  readonly root: string;
  readonly blobsRoot: string;
  readonly packagesRoot: string;
  readonly stagingRoot: string;
  readonly activePath: string;
  readonly lockPath: string;

  constructor(root: string) {
    this.root = resolve(root);
    this.blobsRoot = resolve(this.root, "blobs", "sha256");
    this.packagesRoot = resolve(this.root, "packages");
    this.stagingRoot = resolve(this.root, "staging");
    this.activePath = resolve(this.root, "active.json");
    this.lockPath = resolve(this.root, "sync.lock");
  }

  async initialize() {
    const existing = await lstat(this.root).catch(() => null);
    if (existing?.isSymbolicLink()) throw new Error("Local content store root may not be a symlink.");
    if (existing && !existing.isDirectory()) throw new Error("Local content store root must be a directory.");
    await mkdir(this.root, { recursive: true });
    if (comparable(await realpath(this.root)) !== comparable(this.root))
      throw new Error("Local content store path may not traverse symlinks.");
    for (const directory of [this.blobsRoot, this.packagesRoot, this.stagingRoot])
      await this.ensureDirectory(directory);
  }

  async acquireLock() {
    await this.initialize();
    let handle;
    try {
      handle = await open(this.lockPath, "wx", 0o600);
      await handle.writeFile(`${process.pid}\n`, "utf8");
    } catch (error) {
      await handle?.close().catch(() => undefined);
      if ((error as NodeJS.ErrnoException).code === "EEXIST")
        throw new Error("A production-content sync is already running.");
      throw error;
    }
    let released = false;
    return async () => {
      if (released) return;
      released = true;
      await handle.close();
      await unlink(this.lockPath).catch((error: NodeJS.ErrnoException) => {
        if (error.code !== "ENOENT") throw error;
      });
    };
  }

  async resetStaging() {
    await this.assertSafeDirectory(this.stagingRoot);
    await rm(this.stagingRoot, { recursive: true, force: true });
    await mkdir(this.stagingRoot, { recursive: false });
  }

  async createStagingDirectory(prefix = "sync-") {
    await this.assertSafeDirectory(this.stagingRoot);
    const directory = await mkdtemp(resolve(this.stagingRoot, prefix));
    await this.assertSafeDirectory(directory);
    return directory;
  }

  blobPath(sha256: string) {
    this.assertSha256(sha256);
    return this.safePath(this.blobsRoot, sha256);
  }

  packagePath(packageSha256: string) {
    this.assertSha256(packageSha256);
    return this.safePath(this.packagesRoot, packageSha256);
  }

  async hasVerifiedBlob(sha256: string, size: number) {
    const path = this.blobPath(sha256);
    const info = await lstat(path).catch(() => null);
    if (!info?.isFile() || info.isSymbolicLink() || info.size !== size) return false;
    return (await this.hashFile(path)) === sha256;
  }

  async installBlob(partPath: string, sha256: string, size: number) {
    if (!this.isInside(partPath, this.stagingRoot)) throw new Error("Blob part file escaped staging.");
    const part = await lstat(partPath).catch(() => null);
    if (!part?.isFile() || part.isSymbolicLink() || part.size !== size || (await this.hashFile(partPath)) !== sha256)
      throw new Error("Downloaded media failed size or SHA-256 verification.");
    const target = this.blobPath(sha256);
    if (await this.hasVerifiedBlob(sha256, size)) {
      await rm(partPath, { force: true });
      return target;
    }
    const existing = await lstat(target).catch(() => null);
    if (existing?.isSymbolicLink()) throw new Error("Blob destination may not be a symlink.");
    if (existing) await rm(target, { force: true });
    await rename(partPath, target);
    if (!(await this.hasVerifiedBlob(sha256, size))) throw new Error("Installed media blob failed verification.");
    return target;
  }

  async assemblePackage(
    stagingDirectory: string,
    packageSha256: string,
    publicationBytes: string,
    manifestBytes: string,
    media: PublicationMedia[],
  ) {
    if (!this.isInside(stagingDirectory, this.stagingRoot))
      throw new Error("Package staging directory escaped the local store.");
    const candidate = this.safePath(stagingDirectory, "package");
    await mkdir(candidate, { recursive: false });
    await writeFile(resolve(candidate, "publication.json"), publicationBytes, { flag: "wx" });
    await writeFile(resolve(candidate, "manifest.json"), manifestBytes, { flag: "wx" });
    for (const entry of media) {
      const destination = this.safeMediaPath(candidate, entry.publicPath);
      await mkdir(resolve(destination, ".."), { recursive: true });
      await copyFile(this.blobPath(entry.sha256), destination);
    }
    await validatePublicationPackage(candidate);

    const destination = this.packagePath(packageSha256);
    const existing = await lstat(destination).catch(() => null);
    if (existing) {
      if (!existing.isDirectory() || existing.isSymbolicLink())
        throw new Error("Immutable local package destination is unsafe.");
      await validatePublicationPackage(destination);
      await rm(candidate, { recursive: true, force: true });
      return destination;
    }
    await rename(candidate, destination);
    return destination;
  }

  async activate(snapshot: LocalActiveSnapshot) {
    const destination = this.packagePath(snapshot.packageSha256);
    await validatePublicationPackage(destination);
    const temporary = this.safePath(this.root, `.active.${randomUUID()}.part`);
    await writeFile(temporary, `${JSON.stringify(snapshot, null, 2)}\n`, { flag: "wx", mode: 0o600 });
    try {
      await rename(temporary, this.activePath);
    } finally {
      await rm(temporary, { force: true });
    }
  }

  async readActive() {
    return readFile(this.activePath, "utf8").catch((error: NodeJS.ErrnoException) => {
      if (error.code === "ENOENT") return null;
      throw error;
    });
  }

  private async ensureDirectory(path: string) {
    if (!this.isInside(path, this.root)) throw new Error("Local content directory escaped its root.");
    await mkdir(path, { recursive: true });
    await this.assertSafeDirectory(path);
  }

  private async assertSafeDirectory(path: string) {
    const info = await lstat(path).catch(() => null);
    if (!info?.isDirectory() || info.isSymbolicLink()) throw new Error("Local content path is not a safe directory.");
    if (comparable(await realpath(path)) !== comparable(resolve(path)))
      throw new Error("Local content path may not traverse symlinks.");
  }

  private safePath(root: string, name: string) {
    if (!name || isAbsolute(name) || name.includes("/") || name.includes("\\") || name === "." || name === "..")
      throw new Error("Unsafe local content path component.");
    const path = resolve(root, name);
    if (!this.isInside(path, root)) throw new Error("Local content path escaped its root.");
    return path;
  }

  private safeMediaPath(root: string, publicPath: string) {
    if (!publicPath.startsWith("media/") || publicPath.includes("\\") || isAbsolute(publicPath))
      throw new Error("Unsafe publication media path.");
    const parts = publicPath.split("/");
    if (parts.some((part) => !part || part === "." || part === ".."))
      throw new Error("Unsafe publication media path.");
    const path = resolve(root, ...parts);
    if (!this.isInside(path, root)) throw new Error("Publication media path escaped its package.");
    return path;
  }

  private isInside(candidate: string, root: string) {
    const local = relative(resolve(root), resolve(candidate));
    return local === "" || (local !== ".." && !local.startsWith(`..${sep}`) && !isAbsolute(local));
  }

  private assertSha256(value: string) {
    if (!SHA256.test(value)) throw new Error("Invalid SHA-256 content address.");
  }

  private async hashFile(path: string) {
    const hash = createHash("sha256");
    for await (const chunk of createReadStream(path)) hash.update(chunk);
    return hash.digest("hex");
  }
}
