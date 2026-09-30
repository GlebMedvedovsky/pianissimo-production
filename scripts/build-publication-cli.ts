import {
  buildPublication,
  parsePublicationBuildArguments,
} from "./build-publication";

try {
  const { packagePath, outputPath } = parsePublicationBuildArguments(
    process.argv.slice(2),
  );
  await buildPublication(packagePath, outputPath);
} catch (error) {
  console.error(error instanceof Error ? error.message : "Publication build failed.");
  process.exitCode = 1;
}
