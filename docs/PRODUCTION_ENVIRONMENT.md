# Pianissimo production environment and preflight

This document covers the production environment contract, host/proxy policy, read-only preflight, immutable production publication pipeline, and the private package Draft Preview pipeline. Manual infrastructure verification has occurred, but this repository change does not deploy, publish, switch the public document root, enable SMTP, analytics, or tracking.

Start from `backend/.env.production.example` and store the real production file only on the host. Generate `APP_KEY` securely on the deployment workstation. Supply the database password through the private host environment; it is intentionally absent from the example and must never be committed, logged or pasted into diagnostics.

After the production implementation is committed and pushed, run `git rev-parse HEAD` and copy that exact lowercase 40-character value into the real production environment as `CMS_SOURCE_REVISION=<new exact commit SHA>`. The example deliberately contains an invalid placeholder, and production preflight rejects it until it is replaced.

## Verified infrastructure facts and remaining checks

The following were manually verified on the real Netcup account without deploying this working tree: Webhosting 2000 is active; WCP shows a 75 GB webspace quota and unlimited traffic; at verification time usage was about 1.8 GB / 2% with about 73.2 GB available. Those usage/free figures are dated observations. The SSH host, user and port, deployment-key login, `/usr/bin/sha256sum`, production incoming/static roots and their markers, SSH-user writes, atomic relative-symlink replacement, and absence of `releases/static/current` were also verified. The SSH/CLI site root is `/pianissimo-production.de`, while FastCGI sees it as `/var/www/vhosts/hosting213834.a2e58.netcup.net/pianissimo-production.de`.

FastCGI for `admin.pianissimo-production.de` is PHP 8.4 under Apache with `memory_limit=512M`, `max_execution_time=180`, `max_input_time=60`, `post_max_size=128M`, `upload_max_filesize=128M`, `file_uploads=on`, `display_errors=off`, and `log_errors=on`. Its document root is `/var/www/vhosts/hosting213834.a2e58.netcup.net/pianissimo-production.de/app/backend/public`, and `open_basedir` remains based on the webspace root plus temporary/session paths. CLI is separately PHP 8.4.24 at `/usr/local/php84/bin/php`, SAPI `cli`, with `memory_limit=128M`, unlimited execution time, `max_input_time=-1`, `post_max_size=8M`, `upload_max_filesize=2M`, and no `open_basedir`; those CLI upload values do not describe browser capacity.

The Netcup shell lacks `stat`, `unlink`, and `df`. PHP disk-space functions exposed the shared host filesystem (about 4.1 TiB total / 462 GiB free), not the account quota; WCP's 75 GB webspace figure is authoritative for account capacity.

The following remain unverified for this new code and block a production rehearsal or cutover: the not-yet-deployed media incoming directory and its FastCGI permissions; a real authenticated 4 MiB preview chunk; preview finalization timing; representative large SFTP-import timing; final application/import media maxima; end-to-end package preview after deployment; and the separately authorized public document-root cutover.

Required application values are `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`, `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `SESSION_DRIVER`, `SESSION_SECURE_COOKIE`, `SESSION_COOKIE`, `CACHE_STORE`, `QUEUE_CONNECTION` and `MAIL_MAILER`. Production uses the values shown in the example: German locale with English fallback, a secure named session cookie, file cache, synchronous queue and log-only mail. `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE` and `DB_USERNAME` contain known non-secret settings; the secret database password must be supplied separately.

`CMS_ALLOWED_HOSTS` must explicitly contain `admin.pianissimo-production.de`. `CMS_TRUSTED_PROXIES` is empty by default. Add only an exact IP address or CIDR after Netcup confirms that the request reaches PHP through that proxy; hostnames and wildcards are rejected. Forwarded host/protocol/port headers are not trusted from other peers.

The non-path CMS settings are `CMS_ALLOWED_HOSTS`, `CMS_TRUSTED_PROXIES`, `CMS_PUBLIC_SITE_URL`, `CMS_SOURCE_REVISION`, `CMS_PUBLICATION_DRIVER`, `CMS_PREVIEW_DRIVER`, `CMS_PREVIEW_TTL_MINUTES`, `CMS_MEDIA_APPLICATION_MAX_KB`, `CMS_MEDIA_UPLOAD_MAX_KB` and `CMS_MEDIA_IMPORT_MAX_KB`. `CMS_MEDIA_MAX_KB` is retained only as a backward-compatible fallback. The browser and SFTP-import ceilings must be positive and no greater than the application ceiling. Application startup applies `CMS_MEDIA_UPLOAD_MAX_KB` to Livewire's global temporary-file `max` rule as well as Filament, and preflight verifies the effective Livewire maximum. The production example remains at 65,536 KiB despite FastCGI's 128M transport settings because multipart overhead and real request behavior still apply. When `CMS_PREVIEW_DRIVER=package`, the package builder additionally requires `CMS_GITHUB_REPOSITORY`, `CMS_GITHUB_REF`, `CMS_PREVIEW_WORKFLOW` and `CMS_GITHUB_TOKEN`. The token needs only Actions write access to the configured repository.

The required path settings are `CMS_SCHEMA`, `CMS_ACTIVE_MANIFEST`, `CMS_MEDIA_ROOT`, `CMS_MANAGED_MEDIA_ROOT`, `CMS_MEDIA_ORIGINALS_ROOT`, `CMS_MEDIA_INCOMING_ROOT`, `CMS_PRIVATE_MEDIA_ROOT`, `CMS_PUBLICATION_PACKAGE_ROOT`, `CMS_FRONTEND_APPLY_STATE`, `CMS_PREVIEW_STORAGE_ROOT`, `CMS_PREVIEW_PACKAGE_ROOT`, `CMS_PREVIEW_INCOMING_ROOT`, `CMS_PREVIEW_RESULT_ROOT`, `CMS_PRODUCTION_INCOMING_ROOT`, `CMS_STATIC_RELEASE_ROOT` and `CMS_PUBLIC_SITE_ROOT`. `CMS_MEDIA_INCOMING_ROOT` is the private, temporary SFTP intake only; it is not permanent storage and must be readable/writable by FastCGI. `CMS_PUBLIC_SITE_ROOT` identifies the current static document root only so preflight and private-storage checks can prove separation from public files.

Keep these production values in their verified SSH-chroot form beginning with `/pianissimo-production.de`; the same `.env` is used by CLI and FastCGI. `CmsConfiguration` derives the runtime site root as two `dirname()` levels above Laravel `base_path()` because the backend is installed at `<site-root>/app/backend`. It replaces only an exact `/pianissimo-production.de` path component (the root itself or that root followed by `/`) across every required CMS production filesystem setting. Under SSH/CLI the replacement is effectively unchanged. Under FastCGI, for example, `/pianissimo-production.de/releases/static` resolves to `/var/www/vhosts/hosting213834.a2e58.netcup.net/pianissimo-production.de/releases/static`. Other absolute roots, including names that merely begin similarly such as `/pianissimo-production.de-backup`, are not translated.

The translation changes only the runtime values returned by CMS configuration. It does not change `.env`, relax the requirement that every production path be explicitly configured and absolute, or alter publication placement and workflow checks. Because the result depends on the current PHP namespace, do not generate an SSH/CLI config cache and share it with FastCGI; clear any existing cached configuration and allow `config/cms.php` to run in each runtime namespace.

After installing the backend and creating the listed directories/files, run from the backend directory:

```sh
php artisan cms:production-preflight
```

The command is read-only and reports `PASS`, `FAIL` and `WARN`. It does not write files, alter permissions or symlinks, change configuration, send mail, enable tracking, or query the database by default. To add an optional read-only connectivity probe:

```sh
php artisan cms:production-preflight --check-database
```

The probe executes only `SELECT 1` and suppresses connection error details so credentials are not exposed.

The command runs as the CLI user. Any successful CLI permission result is therefore followed by `WARN: requires FastCGI verification`. Before production use, verify separately through the Netcup PHP 8.4 FastCGI runtime that Laravel storage, cache and session directories are writable, that the expected extensions are loaded there, and that HTTPS/proxy server variables produce the expected secure request. No public diagnostic endpoint is provided.

## Production publication lifecycle

Production publication records are separate from the local candidate `Publication` records. A `ProductionPublication` starts as `preparing` and can become `prepared` only after its private package has been completely validated and atomically moved into place. The implemented forward lifecycle is:

`preparing -> prepared -> dispatching -> dispatched -> building -> built -> uploading -> uploaded -> activating -> active`

Failure is allowed from the preparation and pre-activation states. An active publication may become `superseded` or `rolled_back`; a prepared publication may also be superseded before dispatch. Failed package preparation alone may return to `preparing`. A failed claimed publisher run is explicitly retried by the lifecycle service: it returns to `dispatched` when no release identity exists, or to `built` when both immutable archive and full-file-inventory digests already exist. Every other invalid transition is rejected by both the lifecycle service and model invariant. Activation-state bookkeeping uses the same database mutex as sequence allocation and a nullable unique database slot owned only by the active row: when a publication becomes active, any previously active record becomes superseded, so `ProductionPublication::currentActive()` is unambiguous. The authenticated API may advance only through `uploaded`; the trusted server-side command alone performs the final activation transition.

Each logical publication is claimed by the unique pair of the exact reviewed plan SHA-256 and the exact 40-character source Git revision. A database-locked singleton counter allocates a unique positive monotonic sequence. Retrying the same plan, source revision, candidate hash, manifest and media content returns and verifies the existing record/package. Reusing the claimed identity with different content fails closed.

## Immutable production package contract

Packages are private directories below `CMS_PUBLICATION_PACKAGE_ROOT`, using this deterministic relative layout:

```text
production/<12-digit-sequence>-<publication-uuid>/
  publication.json
  manifest.json
  media/...
```

Construction occurs below a private `.pending/` directory. Only after release validation, exact media validation, hashing, database claim and complete pending-package verification does an atomic directory rename expose the final path. Existing final directories are never overwritten: every expected byte/hash/size and the complete file inventory must match, otherwise the operation fails. The configured root is rejected if it is below `backend/public`, the active public site root, static release storage, or `releases/static/current`.

`manifest.json` is the release manifest encoded as canonical JSON (object keys sorted recursively, array order preserved, UTF-8/unescaped slashes, a single trailing newline). `manifestSha256` hashes those exact bytes. `publication.json` contains only contract metadata and the sorted public media inventory (`assetId`, `publicPath`, `type`, `sha256`, `size`); source filesystem paths and credentials are never included.

`packageSha256` is SHA-256 over canonical JSON with no trailing newline of this object:

```text
{
  contractVersion,
  candidateHash,
  planHash,
  sourceRevision,
  manifestSha256,
  media: [{ assetId, publicPath, sha256, size, type }, ...sorted by publicPath]
}
```

The digest intentionally excludes `packageSha256` itself, `publicationId`, `sequence` and `createdAt`. The first exclusion avoids recursion; the latter fields identify a database/package instance but do not change the public release payload. The manifest hash plus exact ordered media hash/size/path inventory fully commits the public payload.

The package input boundary requires an explicitly release-mode manifest, exact candidate and reviewed-plan hashes, exact source revision, and a one-to-one referenced-media map. Production `ContentValidator` rules still apply, including the exact Kinnhakenhank media restriction. Absolute/traversing or duplicate normalized public paths, symlinks, non-regular/missing files, files outside allowed public media roots, unsupported type/extension pairs, and checksum/size mismatches are rejected. Unreferenced files, private originals, provenance documents, environment files, databases and arbitrary frontend files are not copied.

## Explicit release manifests and static publication builds

`ManifestExporter::package()` remains the local/default boundary and still emits `mode="prototype"`. Candidate export, Draft Preview, local Apply and ordinary `npm run build` continue to use that behavior. Production packaging must deliberately call `ManifestExporter::releasePackage()`. That method changes only the explicit production payload to `mode="release"`, clears `sources` and every project `sourceIds`, and invokes the existing strict production validation with file checks. It does not write the active `content/manifest.json` or change the owner-facing workflow.

An immutable Task 2 package can be built from the repository root with explicit input and fresh output paths:

```sh
npm run build:publication -- --package /absolute/or/resolved/package/path --out /absolute/or/resolved/output/path
```

The command validates the `publication.json` contract and complete file inventory, canonical `manifest.json` bytes and SHA-256, deterministic package digest, release manifest, media ID/path/type/SHA/size agreement, and non-symlink regular files. It rejects overlapping input/output paths, existing output, and output inside repository source directories. Repository-local output is allowed only below `.local-builds/`.

The package manifest is injected through the build-only `virtual:pianissimo-manifest` module. With no explicit publication-build environment, that module reads the tracked `content/manifest.json`, so normal development and builds are unchanged. Publication builds use a temporary public directory containing only the explicit static frontend allowlist (`favicon.svg` and `robots.txt`) plus the exact package media inventory. Astro writes to an owned sibling staging directory; media and general build-isolation checks run before the completed directory is renamed to the requested fresh output. The package and tracked manifest are never modified. No PHP/Laravel process or network request is needed during the Astro build.

For Kinnhakenhank, PHP and TypeScript release validation both require local trailer asset `local-f-87d401c4a58387a5` with derivative SHA-256 `fab8ab6335ef8c48b9c070b434f915c5f62a301570ee510e8bc11165f2803252`. External film is forbidden. Every Kinnhakenhank video reference, including membership hover slots, must be in the explicit production allowlist; the allowlist currently contains only that V2 trailer. The silent-preview asset `local-f-1967744a58884a30` is deliberately not approved.

The current real manifest remains blocked from release. Its known release failures include unapproved assets, missing required audio, incomplete legal/contact/domain approval, and the unapproved Kinnhakenhank hover video. Validation was not weakened to make current content pass.

## Private package Draft Preview

`CMS_PREVIEW_DRIVER` has two deliberately separate modes:

- `local-http` preserves the development flow. Laravel posts the saved, validated draft to the local Astro dev renderer configured by `CMS_PREVIEW_FRONTEND_INTERNAL_URL` and rewrites dev-asset URLs to `CMS_PREVIEW_FRONTEND_URL`.
- `package` never reads or calls either frontend URL. It does not require `host.docker.internal`, localhost, or a persistent Astro process on Netcup.

For `package`, opening **Website-Vorschau** creates the existing expiring `DraftPreview` snapshot and a second immutable build package below `CMS_PREVIEW_PACKAGE_ROOT`. Before package identity is created, every snapshot medium is checked against its server-owned SHA-256 and size. Contract v2 sends the runner only the draft-preview-policy manifest plus canonical metadata containing the exact referenced media inventory, `CMS_SOURCE_REVISION`, and SHA-256/size values. `packageSha256` binds all of those values, but the transport tar contains no original media bytes. It never changes the active manifest, production publication records, `releases/static/current`, or the public document root.

The CMS then dispatches `.github/workflows/pianissimo-preview-builder.yml` at the configured ref. The workflow is disabled unless repository variable `PIANISSIMO_PREVIEW_WORKFLOW_ENABLED` is exactly `true`. It checks out the exact source revision, downloads the metadata-only package through the bearer-authenticated publisher API, safely extracts and revalidates it, and runs the isolated static Astro preview build without copying original media into Astro's public directory. The thin result contains generated files and strict integrity metadata, not `media/preview/*` payloads. It is split into fixed 4 MiB chunks, uploaded with per-chunk SHA-256 and immutable result identity, then finalized separately. No complete-archive POST, SSH access, production activation command, or public media access is used.

The CMS streams each bounded request into `CMS_PREVIEW_INCOMING_ROOT`, serializes uploads per preview, rejects conflicting retries, assembles chunks without loading the archive into PHP memory, and verifies the complete archive digest, USTAR structure, paths, types, entry limits, full generated-file inventory, metadata identity, manifest/package/source hashes, and every generated file SHA/size. The preview-specific validator accepts `/media/preview/...` references only when the exact path is declared by the already byte-verified immutable package; it rejects undeclared media and physical original-media copies in the result. `StaticReleaseArchive` production validation is unchanged and production releases still require all declared media bytes. Only then is the preview result atomically moved below `CMS_PREVIEW_RESULT_ROOT`.

While the build is pending, `/draft-preview/site` returns a calm private `202` page. JavaScript polls the authenticated, `no-store` status endpoint every 2.5 seconds and reloads only once when ready; there is no timed full-page refresh or fake percentage. Astro assets use `/draft-preview/assets/{path}` and the server resolves the current authorized preview from the session, so no numeric preview ID is baked into CSS/JS/font URLs. Every browser route still requires the authenticated CMS session and matching expiring preview token. Draft changes, a newer preview, expiry, media tampering, result tampering, or package/source mismatch invalidate or block the preview.

Required GitHub values for package preview are:

- secret `PIANISSIMO_PUBLISHER_API_TOKEN`, exactly matching the CMS `CMS_PUBLISHER_API_TOKEN`;
- variable `PIANISSIMO_CMS_URL=https://admin.pianissimo-production.de`;
- variable `PIANISSIMO_PREVIEW_WORKFLOW_ENABLED=true` only when preview builds are intentionally enabled.

Required CMS values are `CMS_GITHUB_REPOSITORY=<owner/repository>`, `CMS_GITHUB_REF=<deployed branch or tag>`, `CMS_PREVIEW_WORKFLOW=pianissimo-preview-builder.yml`, and a fine-grained `CMS_GITHUB_TOKEN` with only Actions write permission for that repository. Keep `CMS_PREVIEW_FRONTEND_URL` and `CMS_PREVIEW_FRONTEND_INTERNAL_URL` unset in production.

### Production deployment steps for package preview

1. Deploy the backend, frontend source, `scripts/`, and `.github/workflows/pianissimo-preview-builder.yml` from one committed revision. Do not change the public document root or production release pointer.
2. Set `CMS_SOURCE_REVISION` to that exact lowercase 40-character commit SHA. Set `CMS_PREVIEW_DRIVER=package`; leave both `CMS_PREVIEW_FRONTEND_*` variables absent.
3. In the private production environment set `CMS_GITHUB_REPOSITORY`, `CMS_GITHUB_REF`, `CMS_PREVIEW_WORKFLOW=pianissimo-preview-builder.yml`, and the restricted `CMS_GITHUB_TOKEN`. Do not print the token during verification.
4. Ensure the configured `CMS_PREVIEW_STORAGE_ROOT`, `CMS_PREVIEW_PACKAGE_ROOT`, `CMS_PREVIEW_INCOMING_ROOT`, and `CMS_PREVIEW_RESULT_ROOT` directories exist outside both web document roots and are writable by the FastCGI user. Preserve the existing absolute SSH-chroot paths; runtime translation handles FastCGI. FastCGI's verified 128M request settings are above the nominal 4 MiB raw preview chunk size, but a real authenticated chunk and proxy behavior still require live verification; the complete archive is never uploaded in one request.
5. Configure the GitHub secret/variables listed above, then enable `PIANISSIMO_PREVIEW_WORKFLOW_ENABLED=true`.
6. From `backend/`, run `php artisan optimize:clear`, then `php artisan cms:production-preflight --check-database`. This change adds no database migration.
7. Sign in to the production CMS, save a harmless draft, open **Website-Vorschau**, and verify the workflow completes and the private preview renders. Confirm a logged-out request redirects to login, response headers contain `no-store`/`noindex`, and changing the draft makes the old tab stale.
8. Do not run the production activation command and do not modify `releases/static/current`; preview deployment is complete without publishing anything.

## Authenticated production publisher API

Task 4 adds a stateless publisher API under `/api/publisher/v1`. It does not use the Filament/admin session and has no browser login flow. Every endpoint requires `Authorization: Bearer <token>` with the dedicated `CMS_PUBLISHER_API_TOKEN`. The token must be a high-entropy value of 32–512 non-whitespace characters supplied only through environment/configuration. It is compared with `hash_equals`, is never stored in the database, returned, or included in publisher audit metadata, and an absent or malformed configuration fails closed. Ordinary publisher, package, and finalization routes use the existing `publisher` limiter: 30 requests per minute per resolved client IP. Preview result chunk uploads use the separate `publisher-preview-upload` limiter: 120 requests per minute per resolved client IP. All responses use private/no-store caching.

The implemented endpoints are:

- `POST /api/publisher/v1/publications/{publication_id}/claim` with `runner_id`. A database row lock makes the first claim exclusive; a retry from the exact same runner is idempotent, while another runner is rejected. A prepared claim advances through the lifecycle service to `dispatched`. The response provides centrally derived resume flags for `dispatched`, `building`, `built`, `uploading`, `uploaded`, `activating`, `active`, and explicit failed-state retry; no runner-supplied source revision or package digest is accepted.
- `GET /api/publisher/v1/publications/{publication_id}/package` with the bearer token and `X-Pianissimo-Runner-Id`. Only the claiming runner in a pre-activation build state can download. The endpoint derives the immutable package path exclusively from the database record, revalidates canonical metadata, the complete file inventory, media SHA/size, manifest SHA and logical package digest, and rejects symlinks, special files, additions and tampering.
- `POST /api/publisher/v1/publications/{publication_id}/status` with `runner_id` and one of `building`, `built`, `uploading`, `uploaded`, or `failed`. `built` requires both the static release archive SHA-256 and the complete release-file inventory SHA-256. `ProductionPublicationService` alone applies or rejects the requested lifecycle event. Same-state retries require matching immutable digests; backwards/illegal transitions are rejected, and `activating`/`active` cannot be requested. Runner error text is never retained; only a small allowlisted failure code and fixed sanitized message are stored.

The download is a deterministic uncompressed POSIX ustar transport. It contains only `publication.json`, `manifest.json`, and the exact sorted `media/...` inventory. Headers use fixed permissions, owner/group zero, timestamp zero, validated relative names, regular-file entries only, and two zero end blocks. `packageSha256` remains the Task 2 logical content digest and is returned as `X-Pianissimo-Package-Sha256`. The SHA-256 of the tar bytes is a separate transport digest returned as `X-Pianissimo-Transport-Sha256`; GitHub Actions verifies both distinctions and safely extracts without invoking a general archive extractor. This does not require `ZipArchive` and does not change the Task 2 package contract.

`ProductionWorkflowDispatcher::payload()` prepares the exact non-secret production-publication inputs without making a network call: `publication_id`, decimal `sequence`, logical `package_sha256`, and exact `source_revision`. Production Apply still does not automatically dispatch GitHub. The package Draft Preview separately uses `CMS_GITHUB_REPOSITORY`, `CMS_GITHUB_REF`, `CMS_PREVIEW_WORKFLOW`, and `CMS_GITHUB_TOKEN` for its own narrowly scoped workflow dispatch. Use a fine-grained PAT or GitHub App token restricted to this repository with only **Actions: write** permission; a classic broad `repo` PAT is not required.

## GitHub Actions production build boundary

`.github/workflows/pianissimo-production-publisher.yml` is manual `workflow_dispatch` only and requires the four exact inputs above. It has only `contents: read`, serializes runs with concurrency group `pianissimo-production`, and never cancels an in-progress run. It is disabled unless repository variable `PIANISSIMO_PRODUCTION_WORKFLOW_ENABLED` is exactly `true`.

Configure these GitHub values:

- repository secret `PIANISSIMO_PUBLISHER_API_TOKEN`, equal to the CMS `CMS_PUBLISHER_API_TOKEN`;
- repository variable `PIANISSIMO_CMS_URL`, later `https://admin.pianissimo-production.de`;
- repository variable `PIANISSIMO_PRODUCTION_WORKFLOW_ENABLED`, left unset or non-`true` until explicitly enabled.

The immutable claim identity is `github-${github.run_id}` and therefore stays constant across GitHub re-runs. `github.run_attempt` is used only in the temporary partial-upload name. The workflow validates inputs/configuration and the server-returned identity, then conditionally resumes: `dispatched`/`building` rebuild; `built` rebuilds and must reproduce both stored release digests without moving lifecycle backwards; `uploading` rebuilds and resumes transfer; `uploaded` skips build and upload; `activating` resumes activation; `active` is complete; and `failed` first follows the explicit server reset described above. A different `run_id` cannot take the immutable claim. After a successful claim, failures send only a generic allowlisted callback. Temporary package, build, headers, release archive, and attempt-owned partial-upload material are always cleaned up.

Task 5 extends that boundary as documented below. Production publishing is still not operational until the host is bootstrapped and an explicitly authorized Netcup rehearsal succeeds. The private package Draft Preview above is isolated from publication and does not authorize that rehearsal. The large-media SFTP import is implemented by the hardening branch/revision but is not deployed and still requires reviewed deployment plus live Netcup verification; SMTP, analytics/tracking, HSTS, and public-domain cutover remain out of scope.

## Netcup static release layout and safety markers

`CMS_PRODUCTION_INCOMING_ROOT` is the private transfer destination. `CMS_STATIC_RELEASE_ROOT` is the marked static root itself, not its `releases` child. The intended SSH-chroot layout is:

```text
/pianissimo-production.de/
├── app/backend/
├── private/incoming/production/
│   └── .pianissimo-production-incoming
└── releases/static/
    ├── .pianissimo-production-root
    ├── .pianissimo-production.lock
    ├── .pianissimo-production-state.json
    ├── releases/
    │   └── <sequence>-<publication-uuid>/
    └── current -> releases/<sequence>-<publication-uuid>
```

The incoming and static roots are deliberately distinct. `current` must be absent or a relative symlink matching the shown release pattern; a normal file/directory or an escaping link aborts activation. First activation is accepted only when `current` is absent and persistent state is absent or exactly pristine (`highestActivatedSequence=0`, no active or previous release, `stable`). Any one-sided absence, different target, or non-pristine no-active state fails closed; activation never reconstructs state from `current`. A future explicit recovery command may be designed separately. Releases are staged under the marked static root, moved into `releases/` by an atomic directory rename, and never modified after their server-side archive digest marker has been added. A filesystem lock serializes activation and rollback. The atomic JSON state retains the highest activated sequence, active target, previous target, and a fail-closed ambiguous-state flag. Only explicit rollback may select a sequence below the highest activated sequence.

The real marker files must be created during a separately authorized host bootstrap, not by application code. From the SSH chroot, after verifying the exact target paths, use:

```sh
install -d -m 0750 /pianissimo-production.de/private/incoming/production
install -d -m 0750 /pianissimo-production.de/releases/static/releases
printf '%s\n' 'pianissimo-production-incoming-v1' > /pianissimo-production.de/private/incoming/production/.pianissimo-production-incoming
printf '%s\n' 'pianissimo-production-root-v1' > /pianissimo-production.de/releases/static/.pianissimo-production-root
chmod 0640 /pianissimo-production.de/private/incoming/production/.pianissimo-production-incoming
chmod 0640 /pianissimo-production.de/releases/static/.pianissimo-production-root
```

Do not use `chmod 777`. Ownership and the final group must be chosen for the actual Netcup SSH/FastCGI users during that bootstrap. Keep the verified SSH-chroot paths in `.env`; `CmsConfiguration` translates their `/pianissimo-production.de` prefix to the site root visible to the current runtime.

## Static archive validation and activation

The GitHub workflow writes `.pianissimo-release-files.json` as canonical compact JSON plus one newline. It is a strictly path-sorted array of `{path,sha256,size}` for every regular file in the static public output before integrity metadata is added. Paths use `/`; the SHA-256 covers the exact file bytes. `.pianissimo-release.json`, `.pianissimo-release-files.json`, and the server-created `.pianissimo-archive.sha256` receipt are excluded to avoid recursion. `.pianissimo-release.json` contains the SHA-256 of the exact inventory bytes as `releaseFilesSha256`; that digest is also stored immutably on the publication row when `built` is accepted. Thus retained metadata cannot be changed to re-sign tampered files.

Initial activation verifies the archive SHA-256, extracts safely, and verifies the complete inventory before and after adding the operational archive receipt. Idempotent activation, retained-release validation, and rollback recompute every listed SHA-256/size and require the actual public file set to match exactly. Missing, extra, modified, duplicate-normalized, symlink, and special-file entries fail closed, including HTML, Astro JS/CSS, favicon, robots, media, and other public files.

The server locks and hashes the exact transferred archive handle before inspecting it, then inspects and extracts from that same locked handle. The native extractor accepts only regular files and directories with valid USTAR headers. It rejects absolute, traversal, dot-component, backslash and duplicate normalized paths; symlinks, hardlinks, devices, FIFOs, sockets and other types; invalid checksums and end markers; and excessive entry counts, individual sizes or total extracted size. It restores neither archive ownership nor permissions.

Before pointer switching, the extracted tree must contain `index.html`, `_astro/` assets, matching release metadata and every metadata/text-referenced `/media/...` file with its expected SHA-256 and size. Symlinks and special files are forbidden everywhere. Server-executable extensions, `.env`, backend/storage/vendor/node_modules/private/config/database trees, secret-like names and unexpected static file classes are rejected.

After validation, normal activation atomically renames the staged directory, creates a unique temporary relative symlink and renames it over `current`. Publication lifecycle advancement to `activating`/`active` happens only after the pointer switch. The previous active publication becomes `superseded` only then. A database/status failure compensates by restoring the prior pointer and state; failed compensation records ambiguous state and blocks later operations for operator recovery. Retrying an already-correct active release is idempotent.

The non-interactive server command is:

```sh
php artisan pianissimo:production:activate <publication-uuid> <sequence> <archive-filename> <archive-sha256> --runner=<claimed-runner-id> --no-interaction
```

It requires `APP_ENV=production`, `CMS_PUBLICATION_DRIVER=package`, both correct root markers, a strictly named archive already inside the configured incoming root, and an exact publication/sequence/SHA/claimed-runner match. It never accepts a destination path.

## Rollback semantics

Rollback selects only a retained, previously validated release by publication UUID or strict `<sequence>-<publication-uuid>` release name:

```sh
php artisan pianissimo:production:rollback <publication-uuid-or-release-name> --no-interaction
```

The command uses the same marked root, exclusive lock, metadata/media validation and atomic pointer mechanism. If A is active and earlier B is selected, B becomes the sole `active` publication and A becomes `rolled_back`; a retry to already-active B is idempotent. The release directories are retained. Rollback does not rebuild source, delete releases, or read/write Draft/Candidate manifests or newer CMS Draft data.

## GitHub SSH transfer configuration

The workflow requires these repository secrets:

- `PIANISSIMO_PUBLISHER_API_TOKEN`
- `NETCUP_PRODUCTION_SSH_PRIVATE_KEY`
- `NETCUP_SSH_KNOWN_HOSTS`

It requires these repository variables:

- `PIANISSIMO_CMS_URL`
- `PIANISSIMO_PRODUCTION_WORKFLOW_ENABLED`
- `NETCUP_SSH_HOST`
- `NETCUP_SSH_USER`
- `NETCUP_SSH_PORT`
- `NETCUP_PIANISSIMO_PRODUCTION_INCOMING_ROOT`
- `NETCUP_PIANISSIMO_BACKEND_ROOT`
- `NETCUP_PHP_BIN`

The following SSH host/user/port, incoming-root and PHP-binary values are manually verified. The backend root remains the configured deployment target and is not evidence that the application was deployed:

```text
NETCUP_SSH_HOST=hosting213834.a2e58.netcup.net
NETCUP_SSH_USER=hosting213834
NETCUP_SSH_PORT=22
NETCUP_PIANISSIMO_PRODUCTION_INCOMING_ROOT=/pianissimo-production.de/private/incoming/production
NETCUP_PIANISSIMO_BACKEND_ROOT=/pianissimo-production.de/app/backend
NETCUP_PHP_BIN=/usr/local/php84/bin/php
```

The workflow validates every identifier/path, stores the private key and pinned known-host data only below runner temporary storage with mode `0600`, validates the private key, and uses `BatchMode=yes`, `IdentitiesOnly=yes`, `StrictHostKeyChecking=yes`, and an explicit `UserKnownHostsFile`. It never runs `ssh-keyscan`. The archive is copied to a unique run-owned `.part`, re-hashed remotely, atomically renamed to its final incoming filename, marked uploaded through the authenticated API, and activated by the Artisan command. Failure cleanup can remove only that run's exact `.part`; runner key/package/build material is always removed.

No deployment was performed while implementing these fixes. The real markers and SSH mechanics listed above were verified manually, but FastCGI/chroot application paths and permissions, database/extensions, HTTPS/proxy behavior, migrations/bootstrap, the gated end-to-end rehearsal, and the public document-root cutover remain separate tasks. Pointing the public vhost/document root at `releases/static/current` (or an approved equivalent) remains a separate, explicitly authorized production-cutover task.
