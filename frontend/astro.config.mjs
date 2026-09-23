import { defineConfig } from "astro/config";
import { fileURLToPath } from "node:url";
import { resolve } from "node:path";
import { isFileServingAllowed } from "vite";
import { audioFixtures } from "../scripts/fixture-middleware.mjs";

const localPath = (relative) =>
  fileURLToPath(new URL(relative, import.meta.url)).replaceAll("\\", "/");

// Astro's Markdown/asset import handlers can run before Vite's static-file guard.
// Apply the same native Vite policy before those handlers, including ?import.
const privateLocalMaterials = {
  name: "private-local-materials",
  apply: "serve",
  enforce: "pre",
  configureServer(server) {
    server.middlewares.use((request, response, next) => {
      let pathname;
      try {
        pathname = decodeURIComponent(
          new URL(request.url, "http://127.0.0.1").pathname,
        );
      } catch {
        response.writeHead(400).end("Bad request");
        return;
      }
      const moduleId = pathname.startsWith("/@fs/")
        ? pathname.slice(5)
        : pathname.startsWith("/@id/")
          ? pathname.slice(5)
          : /^\/[a-z]:[\\/]/i.test(pathname)
            ? pathname.slice(1)
            : null;
      const modulePath = moduleId?.replace(
        /^__x00__(?=[a-z]:[\\/]|\/|file:)/i,
        "",
      );
      // /@id/ may also identify an absolute file, rather than a virtual module.
      if (
        modulePath &&
        (pathname.startsWith("/@fs/") ||
          /^(?:[a-z]:[\\/]|\/|file:)/i.test(modulePath))
      ) {
        let file;
        try {
          const absolutePath = modulePath.startsWith("file:")
            ? fileURLToPath(modulePath)
            : modulePath.replace(/^\/([a-z]:[\\/])/i, "$1");
          file = resolve(absolutePath).replaceAll("\\", "/");
        } catch {
          response.writeHead(400).end("Bad request");
          return;
        }
        if (!isFileServingAllowed(server.config, "/@fs/" + file)) {
          response.writeHead(403).end("Forbidden");
          return;
        }
      }
      next();
    });
  },
};

export default defineConfig({
  output: "static",
  trailingSlash: "always",
  devToolbar: { enabled: false },
  server: { host: "127.0.0.1", port: 4321 },
  vite: {
    plugins: [privateLocalMaterials, audioFixtures],
    server: {
      // A shared viewing session must never silently move to another port.
      strictPort: true,
      fs: {
        strict: true,
        // Do not expose the npm workspace root through /@fs/.
        allow: [
          "./src",
          "./public",
          "./node_modules",
          "../node_modules",
          "../contracts",
        ].map(localPath),
        deny: [
          // Preserve Vite's default secret-file protections.
          ".env",
          ".env.*",
          "*.{crt,pem,key,p12,pfx,cer,der}",
          ".npmrc",
          ".yarnrc.yml",
          "**/.git/**",
          "**/.ssh/**",
          "id_rsa",
          "id_ed25519",
          "**/SEBASTIAN_*_STARTER/**",
          "**/SEBASTIAN_*_STARTER.zip",
          "SEBASTIAN_CODEX_*",
          "SEBASTIAN_ANSWERS_*",
          "Pianissimo_Fragen_*",
          "Pianissimo_Rueckfragen_*",
          "**/Sebastian_Materials/**",
          "**/_Materials/**",
          localPath("../docs") + "/**",
          localPath("../images") + "/**",
          localPath("../content/imported") + "/**",
          // SSR reads the manifest; HTTP receives only publicContent's projection.
          localPath("../content/manifest.json"),
          localPath("./dist") + "/**",
          localPath("../.local-builds") + "/**",
        ],
      },
    },
  },
});
