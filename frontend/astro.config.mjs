import { defineConfig } from "astro/config";
import { lstatSync, readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { isAbsolute, resolve } from "node:path";
import { isFileServingAllowed } from "vite";
import { audioFixtures } from "../scripts/fixture-middleware.mjs";
import { createProductionContentMediaMiddleware } from "../scripts/production-content-media.mjs";

const localPath = (relative) =>
  fileURLToPath(new URL(relative, import.meta.url)).replaceAll("\\", "/");

const publicationBuild = process.env.PIANISSIMO_PUBLICATION_BUILD === "1";
const previewBuild = process.env.PIANISSIMO_PREVIEW_BUILD === "1";
const productionContent = process.env.PIANISSIMO_PRODUCTION_CONTENT === "1";
const contactApiUrl = publicationBuild
  ? "https://admin.pianissimo-production.de/api/contact"
  : "http://127.0.0.1:8080/api/contact";
const previewAssetBase = process.env.PIANISSIMO_PREVIEW_ASSET_BASE;
const publicationManifestPath = process.env.PIANISSIMO_MANIFEST_PATH;
const publicationPublicDir = process.env.PIANISSIMO_PUBLIC_DIR;
const publicationOutDir = process.env.PIANISSIMO_OUT_DIR;
const productionMediaRoot = process.env.PIANISSIMO_MEDIA_ROOT;
if (publicationBuild && productionContent) {
  throw new Error("Publication build and production-content dev modes are mutually exclusive.");
}
if (
  !publicationBuild && !productionContent &&
  (publicationManifestPath || publicationPublicDir || publicationOutDir)
) {
  throw new Error("Publication build paths require PIANISSIMO_PUBLICATION_BUILD=1.");
}
if (!productionContent && productionMediaRoot) {
  throw new Error("Production media root requires PIANISSIMO_PRODUCTION_CONTENT=1.");
}
if (
  publicationBuild &&
  (![publicationManifestPath, publicationPublicDir, publicationOutDir].every(
    (value) => value && isAbsolute(value),
  ))
) {
  throw new Error("Publication build paths must be explicit absolute paths.");
}
if (
  productionContent &&
  (
    !publicationManifestPath || !productionMediaRoot ||
    !isAbsolute(publicationManifestPath) || !isAbsolute(productionMediaRoot) ||
    publicationPublicDir || publicationOutDir ||
    resolve(publicationManifestPath) !== resolve(productionMediaRoot, "..", "manifest.json")
  )
) {
  throw new Error("Production-content mode requires matching absolute package manifest and media paths.");
}
if (previewAssetBase && (!previewBuild || previewAssetBase !== "/draft-preview/assets/")) {
  throw new Error("Preview asset base must identify the authenticated preview asset route.");
}
if (previewBuild && !previewAssetBase) {
  throw new Error("Package preview builds require an authenticated preview asset base.");
}

const manifestModule = {
  name: "pianissimo-manifest",
  enforce: "pre",
  resolveId(id) {
    return id === "virtual:pianissimo-manifest"
      ? "\0virtual:pianissimo-manifest"
      : null;
  },
  load(id) {
    if (id !== "\0virtual:pianissimo-manifest") return null;
    const path = publicationBuild || productionContent
      ? publicationManifestPath
      : localPath("../content/manifest.json");
    const stat = lstatSync(path);
    if (!stat.isFile() || stat.isSymbolicLink()) {
      throw new Error("Manifest input must be a regular non-symlink file.");
    }
    const parsed = JSON.parse(readFileSync(path, "utf8"));
    return `export default ${JSON.stringify(parsed)};`;
  },
};

const productionContentMedia = {
  name: "production-content-media",
  apply: "serve",
  enforce: "pre",
  configureServer(server) {
    if (productionContent) {
      server.middlewares.use(createProductionContentMediaMiddleware(productionMediaRoot));
    }
  },
};

const localDraftPreviewRoute = {
  name: "local-draft-preview-route",
  hooks: {
    "astro:route:setup": ({ route }) => {
      if (route.component.replaceAll("\\", "/").endsWith("/pages/draft-preview.astro")) {
        route.prerender = !process.argv.includes("dev");
      }
    },
  },
};

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
  ...(previewBuild ? { base: previewAssetBase } : {}),
  ...(publicationBuild
    ? {
        publicDir: publicationPublicDir,
        outDir: publicationOutDir,
        cacheDir: resolve(publicationOutDir, "../.astro-cache"),
        build: { server: resolve(publicationOutDir, "../.astro-server") },
      }
    : {}),
  trailingSlash: "always",
  devToolbar: { enabled: false },
  integrations: [localDraftPreviewRoute],
  server: { host: "127.0.0.1", port: 4321 },
  vite: {
    define: {
      "import.meta.env.PIANISSIMO_CONTACT_API_URL": JSON.stringify(contactApiUrl),
      "import.meta.env.PIANISSIMO_PUBLICATION_BUILD": JSON.stringify(publicationBuild ? "1" : "0"),
      "import.meta.env.PIANISSIMO_PRODUCTION_CONTENT": JSON.stringify(productionContent ? "1" : "0"),
      "import.meta.env.PIANISSIMO_PREVIEW_BUILD": JSON.stringify(previewBuild ? "1" : "0"),
    },
    plugins: [manifestModule, productionContentMedia, privateLocalMaterials, audioFixtures],
    server: {
      allowedHosts: ["host.docker.internal"],
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
          localPath("../.local-content") + "/**",
        ],
      },
    },
  },
});
