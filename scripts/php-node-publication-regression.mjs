import { randomBytes } from "node:crypto";
import { readdir, rm } from "node:fs/promises";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";

const fixtureName = `php-node-publication-${randomBytes(8).toString("hex")}`;
const containerRoot = `/var/www/html/storage/framework/testing/${fixtureName}`;
const hostRoot = resolve("backend", "storage", "framework", "testing", fixtureName);

const run = (command, args) => {
  const result = spawnSync(command, args, { cwd: resolve(), stdio: "inherit" });
  if (result.status !== 0) {
    throw new Error(`${command} exited with status ${result.status ?? "unknown"}.`);
  }
};

try {
  run("docker", [
    "compose",
    "-f",
    "backend/compose.yaml",
    "exec",
    "-T",
    "-e",
    `PUBLICATION_CROSS_RUNTIME_ROOT=${containerRoot}`,
    "app",
    "php",
    "vendor/bin/phpunit",
    "tests/Feature/ProductionPublicationTest.php",
    "--filter",
    "canonical_manifest_preserves_recursive_json_object_and_array_types",
  ]);

  const productionRoot = resolve(hostRoot, "packages", "production");
  const packages = await readdir(productionRoot, { withFileTypes: true });
  const packageDirectories = packages.filter((entry) => entry.isDirectory());
  if (packageDirectories.length !== 1) {
    throw new Error(`Expected one PHP production package, found ${packageDirectories.length}.`);
  }
  run(process.execPath, [
    "scripts/run-ts.mjs",
    "scripts/validate-publication-package-cli.ts",
    resolve(productionRoot, packageDirectories[0].name),
  ]);
  console.log("PHP-produced production package passed the Node production validator.");
} finally {
  await rm(hostRoot, { recursive: true, force: true });
}
