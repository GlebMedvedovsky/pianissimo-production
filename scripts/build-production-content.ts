import { randomUUID } from "node:crypto";
import { basename, resolve } from "node:path";
import { buildPublication } from "./build-publication";
import { resolveProductionContentRuntime } from "./dev-production-content";

type Output = { log: (message: string) => void; error: (message: string) => void };

export type ProductionContentBuildOptions = {
  repoRoot?: string;
  storeRoot?: string;
  outputPath?: string;
  output?: Output;
  build?: (packagePath: string, outputPath: string) => Promise<string>;
};

export async function buildProductionContent(options: ProductionContentBuildOptions = {}) {
  const repoRoot = resolve(options.repoRoot ?? process.cwd());
  const runtime = await resolveProductionContentRuntime(repoRoot, options.storeRoot);
  const outputPath = resolve(
    options.outputPath ??
      resolve(
        repoRoot,
        ".local-builds",
        `production-content-${runtime.active.sequence}-${runtime.active.packageSha256.slice(0, 12)}-${randomUUID()}`,
      ),
  );
  const build = options.build ?? buildPublication;
  const result = await build(runtime.package.root, outputPath);
  (options.output ?? console).log(
    `Production-content build complete: sequence ${runtime.active.sequence}, publication ${runtime.active.publicationId}.`,
  );
  return { outputPath: result, active: runtime.active };
}

async function main() {
  if (process.argv.length > 2) {
    console.error("Usage: npm run build:production-content");
    process.exitCode = 1;
    return;
  }
  try {
    await buildProductionContent();
  } catch (error) {
    console.error(error instanceof Error ? error.message : "Production-content build failed.");
    process.exitCode = 1;
  }
}

if (basename(process.argv[1] ?? "") === "build-production-content.mjs") await main();
