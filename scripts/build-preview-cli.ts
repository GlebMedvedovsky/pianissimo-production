import { buildPreview } from "./build-preview";

try {
  const values = process.argv.slice(2);
  if (values.length !== 4 || values[0] !== "--package" || values[2] !== "--out")
    throw new Error("Usage: npm run build:preview -- --package <path> --out <path>");
  await buildPreview(values[1]!, values[3]!);
} catch (error) {
  console.error(error instanceof Error ? error.message : "Preview build failed.");
  process.exitCode = 1;
}
