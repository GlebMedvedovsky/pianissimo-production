import { readFile } from "node:fs/promises";
import { basename } from "node:path";

const ALLOWED_HEADERS = new Set([
  "x-pianissimo-package-sha256",
  "x-pianissimo-transport-sha256",
]);
const SHA256 = /^[0-9a-f]{64}$/;

export async function readPublisherDigest(path, requestedHeader) {
  const header = String(requestedHeader).toLowerCase();
  if (!ALLOWED_HEADERS.has(header)) throw new Error("Unsupported publisher digest header.");
  const values = (await readFile(path, "utf8"))
    .split(/\r?\n/)
    .map((line) => {
      const separator = line.indexOf(":");
      if (separator < 0 || line.slice(0, separator).trim().toLowerCase() !== header) return null;
      return line.slice(separator + 1).trim();
    })
    .filter((value) => value !== null);
  const value = values.at(-1) ?? "";
  if (!SHA256.test(value)) throw new Error(`Invalid or missing ${requestedHeader} response header.`);
  return value;
}

if (process.argv[1] && basename(process.argv[1]) === "read-publisher-digest.mjs") {
  const [path, header] = process.argv.slice(2);
  if (!path || !header) throw new Error("Usage: node scripts/read-publisher-digest.mjs <headers> <header-name>");
  process.stdout.write(`${await readPublisherDigest(path, header)}\n`);
}
