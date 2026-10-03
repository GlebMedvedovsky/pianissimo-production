import { validatePublicationPackage } from "./publication-package";

const packagePath = process.argv[2];
if (!packagePath) {
  console.error("Usage: validate-publication-package-cli.ts <package-path>");
  process.exitCode = 1;
} else {
  try {
    await validatePublicationPackage(packagePath);
  } catch (error) {
    console.error(error instanceof Error ? error.message : "Publication package validation failed.");
    process.exitCode = 1;
  }
}
