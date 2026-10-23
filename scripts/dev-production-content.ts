import { execFile } from "node:child_process";
import { spawnSync } from "node:child_process";
import { basename, resolve } from "node:path";
import { promisify } from "node:util";
import { ProductionContentStore, type ActiveProductionContent } from "./production-content-store";

const execFileAsync = promisify(execFile);
const REVISION = /^[a-f0-9]{40}$/;

type Output = { log: (message: string) => void; warn: (message: string) => void; error: (message: string) => void };
type Spawn = typeof spawnSync;

export type ProductionContentDevOptions = {
  repoRoot?: string;
  storeRoot?: string;
  args?: string[];
  env?: NodeJS.ProcessEnv;
  output?: Output;
  getGitHead?: () => Promise<string | null>;
  spawn?: Spawn;
};

export async function readCurrentGitRevision(repoRoot: string) {
  try {
    const { stdout } = await execFileAsync("git", ["rev-parse", "HEAD"], { cwd: repoRoot, encoding: "utf8" });
    const revision = stdout.trim().toLowerCase();
    return REVISION.test(revision) ? revision : null;
  } catch {
    return null;
  }
}

export function requireSourceRevision(args: string[]) {
  if (args.length === 0) return false;
  if (args.length === 1 && args[0] === "--require-source-revision") return true;
  throw new Error("Usage: npm run dev:production -- [--require-source-revision]");
}

export async function resolveProductionContentRuntime(
  repoRoot: string,
  storeRoot = resolve(repoRoot, ".local-content"),
): Promise<ActiveProductionContent> {
  return new ProductionContentStore(storeRoot).resolveActivePackage();
}

export async function runProductionContentDev(options: ProductionContentDevOptions = {}) {
  const repoRoot = resolve(options.repoRoot ?? process.cwd());
  const output = options.output ?? console;
  const strict = requireSourceRevision(options.args ?? process.argv.slice(2));
  const runtime = await resolveProductionContentRuntime(repoRoot, options.storeRoot);
  const currentRevision = await (options.getGitHead ?? (() => readCurrentGitRevision(repoRoot)))();
  const mismatch = currentRevision !== null && currentRevision !== runtime.active.sourceRevision;
  if (mismatch && strict)
    throw new Error("Current Git revision does not match synchronized production content.");
  if (mismatch)
    output.warn("Warning: current Git revision differs from the synchronized publication source revision.");
  output.log(`Production-content dev mode: sequence ${runtime.active.sequence}, publication ${runtime.active.publicationId}.`);
  const spawn = options.spawn ?? spawnSync;
  const result = spawn(
    process.execPath,
    [resolve(repoRoot, "scripts", "astro.mjs"), "dev"],
    {
      cwd: resolve(repoRoot, "frontend"),
      env: {
        ...(options.env ?? process.env),
        PIANISSIMO_PRODUCTION_CONTENT: "1",
        PIANISSIMO_MANIFEST_PATH: runtime.package.manifestPath,
        PIANISSIMO_MEDIA_ROOT: resolve(runtime.package.root, "media"),
      },
      stdio: "inherit",
    },
  );
  return result.status ?? 1;
}

async function main() {
  try {
    process.exitCode = await runProductionContentDev();
  } catch (error) {
    console.error(error instanceof Error ? error.message : "Production-content dev mode failed.");
    process.exitCode = 1;
  }
}

if (basename(process.argv[1] ?? "") === "dev-production-content.mjs") await main();
