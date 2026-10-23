import { createReadStream, lstatSync, realpathSync } from "node:fs";
import { isAbsolute, relative, resolve, sep } from "node:path";

const MIME = {
  webp: "image/webp",
  jpg: "image/jpeg",
  jpeg: "image/jpeg",
  png: "image/png",
  mp3: "audio/mpeg",
  wav: "audio/wav",
  ogg: "audio/ogg",
  mp4: "video/mp4",
  webm: "video/webm",
};

const comparable = (value) =>
  (process.platform === "win32" ? value.toLowerCase() : value).replaceAll("\\", "/");

const inside = (candidate, root) => {
  const local = relative(root, candidate);
  return local !== ".." && !local.startsWith(`..${sep}`) && !isAbsolute(local);
};

export function createProductionContentMediaMiddleware(mediaRoot) {
  if (!mediaRoot || !isAbsolute(mediaRoot))
    throw new Error("Production-content media root must be an absolute path.");
  const root = resolve(mediaRoot);
  const rootStat = lstatSync(root);
  if (!rootStat.isDirectory() || rootStat.isSymbolicLink() || comparable(realpathSync(root)) !== comparable(root))
    throw new Error("Production-content media root must be a regular non-symlink directory.");

  return (request, response, next) => {
    let pathname;
    const rawPath = String(request.url ?? "").split(/[?#]/, 1)[0];
    const rawMediaRoute = rawPath === "/media" || rawPath.startsWith("/media/");
    try {
      pathname = decodeURIComponent(new URL(request.url, "http://127.0.0.1").pathname);
    } catch {
      response.writeHead(400).end("Bad request");
      return;
    }
    if (!rawMediaRoute && pathname !== "/media" && !pathname.startsWith("/media/")) {
      next();
      return;
    }
    if (pathname !== "/media" && !pathname.startsWith("/media/")) {
      response.writeHead(404).end("Not found");
      return;
    }
    if (request.method !== "GET" && request.method !== "HEAD") {
      response.writeHead(405, { Allow: "GET, HEAD" }).end();
      return;
    }
    const local = pathname.slice("/media/".length);
    const parts = local.split("/");
    if (!local || parts.some((part) => !part || part === "." || part === ".." || /[:*?"<>|\\]/.test(part))) {
      response.writeHead(404).end("Not found");
      return;
    }
    const file = resolve(root, ...parts);
    if (!inside(file, root)) {
      response.writeHead(404).end("Not found");
      return;
    }
    let stat;
    try {
      stat = lstatSync(file);
      if (!stat.isFile() || stat.isSymbolicLink() || comparable(realpathSync(file)) !== comparable(file))
        throw new Error();
    } catch {
      // This route is terminal in production-content mode: never reach publicDir.
      response.writeHead(404).end("Not found");
      return;
    }
    const extension = parts.at(-1).split(".").at(-1).toLowerCase();
    const contentType = MIME[extension];
    if (!contentType) {
      response.writeHead(415).end("Unsupported media type");
      return;
    }
    let start = 0;
    let end = stat.size - 1;
    let status = 200;
    const range = request.headers.range;
    if (range) {
      if (stat.size === 0) {
        response.writeHead(416, { "Content-Range": "bytes */0" }).end();
        return;
      }
      const match = /^bytes=(\d*)-(\d*)$/.exec(range);
      if (!match || (!match[1] && !match[2])) {
        response.writeHead(416, { "Content-Range": `bytes */${stat.size}` }).end();
        return;
      }
      if (!match[1]) {
        const suffix = Number(match[2]);
        if (!Number.isSafeInteger(suffix) || suffix <= 0) {
          response.writeHead(416, { "Content-Range": `bytes */${stat.size}` }).end();
          return;
        }
        start = Math.max(0, stat.size - suffix);
      } else {
        start = Number(match[1]);
        end = match[2] ? Number(match[2]) : end;
      }
      if (!Number.isSafeInteger(start) || !Number.isSafeInteger(end) || start < 0 || start > end || start >= stat.size) {
        response.writeHead(416, { "Content-Range": `bytes */${stat.size}` }).end();
        return;
      }
      end = Math.min(end, stat.size - 1);
      status = 206;
    }
    const headers = {
      "Accept-Ranges": "bytes",
      "Cache-Control": "no-store",
      "Content-Length": String(Math.max(0, end - start + 1)),
      "Content-Type": contentType,
      ...(status === 206 ? { "Content-Range": `bytes ${start}-${end}/${stat.size}` } : {}),
    };
    response.writeHead(status, headers);
    if (request.method === "HEAD" || stat.size === 0) response.end();
    else createReadStream(file, { start, end }).pipe(response);
  };
}
