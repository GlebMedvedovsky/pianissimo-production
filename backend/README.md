# Pianissimo CMS — local foundation and controlled round trip, stages 03A / 03B / 04A

German Laravel + Filament administration, backed by MariaDB. This is a working local editorial backend, **not a production deployment**. CMS saving/exporting is independent of the existing Astro site. A separate confirmed local command can now apply a reviewed candidate to its active manifest and roll it back; 04A adds approved content and scoped proxy handling for the authorised existing CMS tunnel.

Architecture: MariaDB → Laravel models → one deterministic `ManifestExporter` → validated private candidate → explicit local apply → active manifest → Astro dev 4321. A future static production build/deployment remains separately authorised work. The public site has no database connection. Docker is a local convenience; the PHP application does not require Docker on future ordinary hosting.

## Versions and first installation

Composer locks Laravel **13.32.0**, Filament **5.8.2**, Livewire **4.4.5** and Opis JSON Schema **2.6.0**. The local image uses PHP **8.4.25**; MariaDB is **11.8.9**, nginx is the 1.28 Alpine image. PHP/Composer were not installed globally. Compatibility references: [Laravel 13](https://laravel.com/docs/13.x/releases), [Filament 5 installation](https://filamentphp.com/docs/5.x/introduction/installation).

Run from the permanent project root in PowerShell. The existing installation is already prepared; do not regenerate its `.env`, key, database or admin on every start.

```powershell
Set-Location D:\Projects\Sebastian
docker desktop start
pwsh -File backend/Initialize-Local.ps1
docker compose -f backend/compose.yaml build app
docker compose -f backend/compose.yaml run --rm --no-deps app composer install --no-interaction
pwsh -File backend/Start-Local.ps1
docker compose -f backend/compose.yaml exec app php artisan migrate
docker compose -f backend/compose.yaml exec app php artisan filament:assets
docker compose -f backend/compose.yaml exec app php artisan cms:admin
docker compose -f backend/compose.yaml exec app php artisan cms:import --dry-run
docker compose -f backend/compose.yaml exec app php artisan cms:import
```

`Initialize-Local.ps1` creates ignored runtime directories and an ignored `.env` with cryptographically random secrets only if absent. It never replaces an existing environment. `Start-Local.ps1` checks 8080, preserves this project's running server, and stops with an error if another process owns the port. It never kills a process or picks another port.

Admin: **http://127.0.0.1:8080/admin**. `cms:admin` asks for email/name and a hidden password of at least 12 characters; use `cms:admin --reset` for an interactive password reset. A local bootstrap administrator was created for verification. Its credentials exist only in ignored `backend/storage/app/private/local-admin.json`; no password is included in documentation. Create a personal admin with the command above if preferred. All admin users currently have the same trusted editorial access; no public registration or elaborate RBAC was added.

## Daily start, stop and persistence

```powershell
Set-Location D:\Projects\Sebastian
docker desktop start
pwsh -File backend/Start-Local.ps1
docker compose -f backend/compose.yaml ps
# Stop only this CMS:
docker compose -f backend/compose.yaml stop
# Resume the same services and database:
pwsh -File backend/Start-Local.ps1
```

Compose project: `pianissimo-cms`; services: `app`, `web`, `db`. Only nginx exposes a host port, bound to **127.0.0.1:8080**. MariaDB and PHP-FPM have no published host ports. Both PHP-FPM and CLI run as `www-data`, so browser and CLI exports share ownership; do not run routine Artisan commands as root. Named volume `pianissimo-cms_cms_database` retains `pianissimo` and the isolated `pianissimo_test` database. Ordinary `stop`/`start` and `up -d` retain the volume. Do not use `down -v`, `migrate:fresh` or destructive database commands on the editorial database. Keep `.env`/APP_KEY stable and back up the volume and private export storage before future upgrades. No other project's Compose stack is managed by these commands.

## Editing and models

The German menu provides Dashboard; Projekte, Tracks, Medien, About and CV; Startseite, Film Music, Directing, Kontakt, Social Links, SEO, Website-Farben and inactive Analytics; Impressum, Datenschutz, Cookie/Consent, Externe Dienste and Lizenzen/Credits; Site-Einstellungen, Benutzer and Veröffentlichung.

Tables (besides Laravel's migration bookkeeping): `users`, `projects`, `memberships`, `project_media`, `tracks`, `project_tracks`, `media_assets`, `cv_entries`, `settings`, `external_services`, `import_records`, `publications`.

Project and track identities are stable ASCII IDs. A project has independent Film Music and Directing memberships; shared projects are never duplicated. Memberships own direction-specific roles, covers, crops and order. Project-media assignments own gallery/trailer/other slots and manual order; project-track joins own soundtrack order. Taxonomies use typed multi-select arrays, not comma-separated prose. The exported project/track identity and playback values match the active contract.

Media creation defaults to **Neue Datei hochladen** with drag-and-drop, progress and server-side content-MIME validation. The advanced **Vorhandene Web-Datei registrieren** mode preserves the former metadata workflow. Accepted upload content types are JPEG (`.jpg`/`.jpeg` input is normalised to `.jpg`), PNG, WebP, MP4, WebM, MP3/MPEG audio, WAV and Ogg. SVG, HTML, PHP, JavaScript, executables and unknown MIME types are rejected. `CMS_MEDIA_MAX_KB` controls the limit; the supplied local configuration is 512000 KiB (500 MiB), matched by PHP's upload/post limits. Image dimensions, byte size and SHA-256 are calculated automatically. Audio/video duration is left empty because the runtime has no guaranteed media-probe binary.

Uploaded originals live only in `storage/app/private/media/originals/<stable-id>/<sha256>.<ext>`. The draft record receives a deterministic future path `media/cms/<stable-id>-<hash-prefix>.<ext>`, but Save Draft never writes the frontend. A candidate copies only referenced managed files into its immutable private payload and binds every path, byte size and checksum into the authenticated receipt. Apply may write only `content/manifest.json`, the dedicated `frontend/public/media/cms/` mount and private revision/audit storage. Existing `media/02d`, `media/04a` and legacy files remain read-only and unmoved.

**Datei ersetzen** stores a new private binary under the same stable media ID; all relations remain intact and the public path/checksum change only after review and Apply. **Verwendungen anzeigen** resolves actual setting and database relations (page slots, membership covers/hovers, project media, track playback and SEO), not manifest text. Delete shows every location and edit link. Optional usages may be detached independently; required covers say **Ersatz erforderlich**. Bulk replacement validates every selected slot before one database transaction. An unused asset can be deleted; a used asset cannot be deleted until all references are detached or atomically replaced. Applied managed bytes are retained in the revision backup and restored by rollback. Authenticated previews remain private/no-store.

Save buttons store **CMS drafts only**. “Im nächsten Paket zeigen” marks inclusion in the next package; it does not change an existing package or the running website. Draft/hidden projects and tracks are excluded from exported content; unreferenced tracks/assets are omitted. Imported materials have `existing_local_draft` scope. This is not a new public-use permission. Explicitly unconfirmed playback blocks export when selected.

Admin Dark/Light/System is persisted in `users.admin_theme`. New users default to Dark. Filament retains its native switch and system preference listener; the DB preference is restored on login and persisted through an authenticated CSRF-protected endpoint. The website's About/color settings are separate. Avatar and font assets are local.

## Import, candidate and explicit publication

```powershell
docker compose -f backend/compose.yaml exec app php artisan cms:import --dry-run
docker compose -f backend/compose.yaml exec app php artisan cms:import
docker compose -f backend/compose.yaml exec app php artisan cms:export --dry-run
docker compose -f backend/compose.yaml exec app php artisan cms:export
# Explicit confirmation creates a local published snapshot, never a deployment:
docker compose -f backend/compose.yaml exec app php artisan cms:export --publish
# Expected to fail while production prerequisites remain open:
docker compose -f backend/compose.yaml exec app php artisan cms:export --release-check --dry-run
```

Import reads only `CMS_MANIFEST` (the current manifest, mounted read-only). It validates shape, references and web-file checksums, then imports in a transaction. It does not read questionnaires, responses, STARTER packages, audit reports, original archives or the source URL registry. Source locations/URLs are replaced with neutral provenance descriptions. CV has no upstream ID, so its initial CMS ID is derived from date/text, not row position; a materially changed upstream CV entry requires editorial reconciliation rather than inferred identity.

`import_records` stores input and record fingerprints. Repeating identical input skips it; changed source data updates a clean imported record; manual edits, manual deletions and previously unknown existing records are reported as conflicts and preserved. Unknown additional records are not deleted. There is no force-overwrite switch. Exit code 2 indicates conflicts; review the listed IDs. Import reports do not contain passwords or private source contents.

The Veröffentlichung page uses the same exporter as CLI. Dry Run validates without writing. **Export-Vorschau erstellen** writes to `storage/app/private/exports/candidates/<content-hash>/`. It shows candidate hash, time, validation and exporting admin. **Änderungen prüfen** creates a hash-bound semantic plan against the current baseline. **Für Website übernehmen** requires a second confirmation and accepts only that exact authenticated, validated, reviewed candidate. **Letzte Änderung zurücksetzen** restores the preceding manifest and all affected managed-media bytes from the same revision. Confirmed **Lokalen Paketstand sichern** remains an immutable archival snapshot under `exports/published/<content-hash>/`; it is not a deployment. Later draft saves cannot alter snapshots. Download links require admin login. Each deterministic package contains:

- `manifest.json`: current **schemaVersion 1.0.0**, validated against the unchanged shared `../contracts/manifest.schema.json`, plus semantic reference/type/path/hash checks. Export is `mode: prototype`; the production guard remains blocked.
- `cms-settings.json`: version **cms-settings-1.0.0**, a typed companion for fields absent from manifest 1.0.0: SEO, per-project SEO, About behavior, colors, hero loop/muted flags, labels/footer, public legal identity, additional media assignments, consent, services, licenses and inactive analytics. It declares `scope: next_draft` and `deploymentAuthorized: false`. It contains no second copy of project/track content. The current frontend does **not** consume these new settings yet.

See [extension contract](contracts/README.md). The exporter uses explicit public-field allowlists. Passwords, internal recipients, private provenance/license notes, database fields and environment values are not exported. Errors identify the offending record/field. Export itself has no build trigger, active-manifest overwrite, queue, Netcup connection or deployment action.

## Browser apply and rollback

Sebastian's normal workflow is entirely in the authenticated German CMS: **Entwurf speichern → Paket prüfen → Export-Vorschau erstellen → Änderungen prüfen → Für Website übernehmen**. Ordinary saves never update the frontend. Review records the exact candidate and baseline hashes plus a semantic diff. Apply revalidates the candidate receipt and every payload checksum, rejects stale baselines/unreviewed or malformed hashes, uses an exclusive operation lock, stages new media first, atomically replaces the manifest, then removes superseded managed media. Repeated Apply is idempotent. A recovery revision and byte-exact backups exist before the active manifest changes.

Rollback is offered only when the active hash matches a recorded applied revision. It restores old media before atomically restoring the old manifest, and refuses missing/corrupt backups or later external changes. Audit rows record admin, time, stable object ID, action and relevant result hash; neither binary contents nor credentials are logged. Production remains blocked and no action builds or deploys the site.

## Developer emergency CLI (03B)

Create a candidate through the authenticated CMS action; it writes a local `receipt.json` with admin ID and manifest/settings checksums. CLI-only exports without that receipt can be validated, but cannot be applied: export the same content through CMS first. States are Draft, Candidate, Applied to Frontend; Production Released is always false. Saving an immutable local package is a separate archival operation.

The established PowerShell/Node boundary remains available only for developer recovery and diagnostics; Sebastian does not need it for normal publication. Run on the Windows host from `D:\Projects\Sebastian`:

```powershell
node scripts/cms-frontend.mjs review <candidate-hash>
node scripts/cms-frontend.mjs apply <plan-hash>
node scripts/cms-frontend.mjs rollback
node --test backend/tests/frontend-workflow.test.mjs
```

Review prints the exact semantic public diff; apply and rollback separately prompt for their complete confirmation hashes. The common TypeScript/Zod contract is loaded without rewriting its schema. Unknown fields, private provenance, bad references/media hashes, non-prototype mode and a non-V2 Kinnhakenhank trailer are rejected. The candidate receipt binds both export files. More than 20 changed paths, 32 KiB of diff or identity changes are blocked by default. A separately reviewed batch uses `review <candidate-hash> --expected <expected-diff.json>` with version 1, exact candidate/baseline hashes and every before/after field. Review stores the immutable expected file by hash; apply revalidates it. Existing validation, confirmation, backup and rollback still apply. No force switch exists.

The host command applies changed public fields by stable ID while retaining local source records, unused assets, array storage order and exact unchanged metadata. The public projection must equal the reviewed candidate. Normalisation covers empty optional values and PHP/PDO's 14-significant-digit asset durations; track duration/waveform values are never rounded. Playlist/gallery/CV order remains meaningful. This avoids wholesale replacement by a sanitised export. The raw active manifest stays denied over dev HTTP.

Before replacement, an exact validated backup and recovery revision are persisted in private `storage/app/private/frontend-apply/`. A flushed temporary file in the active directory is validated, the baseline is checked again, and same-directory `rename` replaces it atomically. Failed preconditions leave active intact; repeated apply returns `already-current`. A lock prevents competing tool instances; it does not claim to coordinate arbitrary external editors. After an interrupted process, inspect the recorded PID before removing only a stale lock. Revision and backup survive interruption; rollback accepts only a recognised resulting hash and validates the backup. It restores bytes without editing CMS drafts. Cancel a synthetic test draft explicitly after testing.

`history.jsonl` contains timestamps, exporting admin IDs, candidate/previous/result hashes and statuses, never content or secrets. The emergency CLI and the CMS service use separate review records but the same safety principles. Apply can be visible on shared dev 4321; it is not Netcup deployment or public-use approval.

## Dynamic Social Links

**Social Link hinzufügen** manages an ordered repeater with provider, label, HTTP(S) URL, visibility and drag/drop order. Supported providers are Instagram, IMDb, Crew United, Music Hub, Spotify, Tidal, Apple Music, Deezer, YouTube, Vimeo, SoundCloud, Letterboxd, Website and Other. Existing fixed profile/artist URLs are migrated into the list without re-entry and legacy manifest fields remain readable. Hidden or deleted entries are absent from presentation; an empty list is valid and contact/email settings remain separate. The frontend selects only built-in `Icon.astro` symbols (music, video, film, Instagram or neutral external link); the CMS cannot supply SVG or HTML.

## Legal, consent and safety boundaries

Legal text is editable with draft/reviewed/published status; no missing legal facts were invented or approved. Essential functionality stays enabled, external media require consent, analytics/marketing start disabled. External services use a fixed provider enum and a constrained public identifier, never arbitrary HTML/JavaScript or credentials. This is configuration preparation, not visitor consent logging or tracking. Public email and private form recipient are separate. SMTP remains inactive and no actual form is sent.

04A explicitly authorises the existing https://1nx12grf-8080.euw.devtunnels.ms/admin tunnel, forwarding local HTTP 8080. The external URL must never include `:8080`. Laravel trusts forwarded host/proto/port only from `172.21.0.1`; exact permitted hosts are configured in `config/dev-access.php`. The middleware rejects other hosts and uses Secure cookies only for HTTPS requests. APP_URL stays local; favicon URL generation is deferred until proxy handling. Recheck the actual gateway/headers if Docker networking changes. Wildcard proxy/host trust is forbidden. Frontend 4321 remains its separate existing shared-view endpoint. nginx's document root is `backend/public`; neither the project root, vendor, `.env`, database storage nor exports are public. Session cookies are separate from the frontend. Normal authentication, CSRF, login throttling and filesystem boundaries remain enabled. No symlink from private storage into public is created. `.gitignore` covers `.env`, vendor, generated panel assets, caches/logs, exports, credentials and backups; it is not the HTTP access control.

Kinnhakenhank may export only the selected local V2 trailer; an external full-film link or a different local trailer blocks export. Hero loop/muted-start requirements remain true. Au milieu de l’été uses the received and explicitly approved final MP3 without re-encoding. Its 212.1179 s container versus 212.297142857 s decoded duration is documented and checked in 04A; no replacement is requested.

## Verification commands

```powershell
# Uses only isolated pianissimo_test; refuses the editorial database:
docker compose -f backend/compose.yaml exec app php vendor/bin/phpunit --testdox
# Browser fixture setup stores random passwords privately, never stdout:
docker compose -f backend/compose.yaml exec app php tests/browser-users.php
node backend/tests/browser-03a.mjs
docker compose -f backend/compose.yaml exec app php tests/browser-cleanup.php
node backend/tests/check-contract.mjs
node backend/tests/check-boundaries.mjs
```

`check-contract.mjs` invokes the actual existing TypeScript/Zod validator without rewriting its generated schema. It checks IDs, memberships, track/gallery order and playback/waveform parity with the public projection of the active manifest, and verifies that production validation still fails. Test media/text are explicitly synthetic; tests do not represent Sebastian's answers. Private execution reports and screenshots are in `../docs/qa/03A/`. See the final report there for executed results and limits, rather than assuming every command has passed from its existence.

## Later Netcup work — not authorised or implemented

First confirm the actual hosting PHP version/extensions, MariaDB/MySQL support, private storage layout and document root. The PHP application can be installed with Composer and ordinary host configuration; Docker must not be required there. Authentication/TLS, backups/restoration, access policy, SMTP delivery and abuse controls need a separate deployment review. Node belongs only on a build workstation/runner; the public host serves static Astro output. The local 03B manifest switch is not a production rollout. Connecting companion settings, creating/building a release and deploying require later explicit work and approval. No production-readiness claim is made.

## 04A editorial fields and private drafts

Optional manifest fields preserve schema 1.0.0 compatibility: `track.libraryVisible`, `project.presentationStatus: placeholder`, `site.cv[].linkText`. Standalone library entries are explicitly opted in and deduplicated by ID. `soundtrack_order_pending`, nullable CMS soundtrack order, `editorial_notes` and legal `reviewDraft` stay private. A visible project cannot export pending/missing soundtrack order. Letterboxd rejects film/trailer/hover video. Der Statist and Letterboxd remain draft.

Three Der Statist playback derivatives are in ignored `storage/app/private/04a/prepared/media/04a`, not frontend/public. The existing authenticated `/admin-media/{asset}` route can preview this narrowly scoped root, with realpath containment and private/no-store responses. Before a future approved visible import, copy only the selected derivatives to their recorded public paths and revalidate hashes; do not expose private storage. Legal review drafts are excluded from both manifest and companion, and their status remains draft.

04A import preparation and exact decision/source hashes are private in `../docs/qa/04A/` and `storage/app/private/04a/`. The one-off `tests/prepare-04a.php` followed by `tests/refine-04a.php` was already executed; do not rerun it as a startup command. Refinement preserved existing Au milieu emotions and the relative order of non-priority cards. Current results: [04A report](../docs/qa/04A/REPORT_RU.md).
