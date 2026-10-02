# Production status — 2026-10-02

Open this checkpoint together with [NETCUP_RUNBOOK.md](NETCUP_RUNBOOK.md) at the start of future production work.

- Repository: `GlebMedvedovsky/pianissimo-production`; the current branch for this work is `main`.
- Private-preview contract v2 was deployed successfully to Netcup on 2026-10-02 from source revision `0f2d1982deacf34b615a66ae9e9bff8b0b9e1514`.
- The prior media-heavy production preview run #9 used `CMS_SOURCE_REVISION=cf97951aeda4f7db5d98db60b6cc6d004ac1f975`; the first successful live contract-v2 verification was run #10 at the deployed revision above.
- The production workflow remains disabled, `releases/static/current` was not activated, and the public site remains untouched.
- CMS: `https://admin.pianissimo-production.de`; public URL: `https://pianissimo-production.de`.
- Hosting: **Webhosting 2000 was verified active on 2026-10-01**. WCP showed 75 GB webspace, about 1.8 GB / 2% used, about 73.2 GB available at that moment, and unlimited traffic. Usage/free observations will change over time.

## Working production state

Laravel/Filament, admin login, MySQL, all migrations, idempotent CMS import, Filament assets, FastCGI production-media reads, and private Draft Preview snapshot creation work. SSH and FastCGI use different absolute namespaces; `CmsConfiguration` performs the exact runtime-root translation. Production storage uses the relative `../../private/runtime/cms/storage` symlink.

The prior package Draft Preview blocker under FastCGI was caused by symlink detection probing `/var` above `open_basedir`. This branch addresses it by starting at `dirname(base_path(), 2)`, rejecting roots outside it before creation, checking the runtime root and descendants, and adding regression coverage including a no-ancestor-probe assertion.

Package preview remains private and expiring: CMS snapshot/package → exact-revision GitHub Actions Astro build → authenticated result upload → strict archive/inventory validation → private Laravel serving. This branch implements result return as authenticated 4 MiB chunks plus finalization instead of one potentially large POST. It does not involve the production release pointer.

The real production private-preview E2E is successful. Run **#9** proved the original media-heavy pipeline and owner-facing pages, but took roughly 191 seconds: package download about 14 seconds, Astro about 7 seconds, and result upload/finalization about 148 seconds. Netcup Apache/FastCGI required `backend/public/.htaccess` to forward `HTTP Authorization` into `HTTP_AUTHORIZATION`.

Run **#10** is the first successful live verification of contract v2. The workflow concluded successfully at source revision `0f2d1982deacf34b615a66ae9e9bff8b0b9e1514`. User-visible time from clicking **Website-Vorschau** until display was approximately **45.78 seconds**; the GitHub workflow took about 43 seconds, including about 3 seconds for the metadata-only package download, about 5 seconds for `npm ci`, 1.21 seconds for Astro to build 21 pages, below 0.2 seconds for validation/archive creation, and about 7 seconds for result upload/finalization. Compared with run #9, contract v2 removed the dominant duplicate media transfer in both directions.

Live UX verification confirmed the spinner/status waiting screen replaced the old three-second full-page meta-refresh without blinking and loaded the preview automatically when ready. Homepage, Film Music, Directing, project routes, CSS, fonts, media, and navigation worked; the banner remained **“Entwurfs-Vorschau · Nicht veröffentlicht”**. Browser media continues through the authenticated private CMS route. Original media bytes are neither sent Netcup → GitHub nor returned GitHub → Netcup, and production `StaticReleaseArchive` semantics remain unchanged.

Changed-draft behavior was also verified live: after saving the project title **“Kinnhakenhank TEST”**, a newly opened Website-Vorschau displayed that exact current value. This confirms `edit → save → new Website-Vorschau → current saved draft` and that an old result is not reused for a changed package identity.

Post-deployment production preflight reported **0 FAIL, 1 WARN**; the only warning was `Laravel paths (web user) — requires FastCGI verification`. All five deployed runtime files matched the reviewed staging files byte-for-byte. The temporary deployment archive and staging directory were removed after verification. Rollback backup retained: `/pianissimo-production.de/private/backups/backend-before-preview-v2-20261002.tar.gz`.

The first real private-preview dispatch reached GitHub with repository, workflow, active-state, ref, and dispatch permission checks succeeding, but GitHub rejected the workflow before creating a run because job-level `env` referenced the unavailable `${{ runner.temp }}` context. This branch moves preview temporary-path initialization to runner step time through `$RUNNER_TEMP` and `$GITHUB_ENV`. The same latent parser defect was found and corrected proactively in the production publisher workflow while `PIANISSIMO_PRODUCTION_WORKFLOW_ENABLED` remained `false`; that gate must stay disabled. Before the successful dispatch, local real-build testing also found that frontend code invented undeclared `-480.webp` paths and that preview CSS used public-root `/_astro/` font URLs. The implementation now uses only canonical manifest media paths and a session-authenticated `/draft-preview/assets/{path}` base; production build URLs remain unchanged.

This branch implements the owner-facing large-media path: FileZilla/SFTP upload to `/pianissimo-production.de/private/incoming/media` → authenticated CMS `Medien → Große Datei importieren` selection → streamed change detection and existing `MediaStorage` validation → canonical private original → media record. Browser, application, and SFTP-import limits are separately configurable. A controlled production import for `pianissimo-sftp-test` verified incoming listing and consumption, canonical private storage, and the `MediaAsset` row. Its initial edit redirect exposed Filament local-disk hydration; after patch `3ef1372`, the edit page returned HTTP 200, a harmless metadata save succeeded, and authenticated **Datei ansehen** succeeded without a new `production.ERROR`. The unused test asset was then deleted through the CMS: its active row disappeared, soft-deleted history remained, its canonical original was physically removed, and no incoming source remained.

Post-upgrade verification recorded PHP 8.4 FastCGI under Apache with `memory_limit=512M`, `max_execution_time=180`, `max_input_time=60`, `post_max_size=128M`, `upload_max_filesize=128M`, uploads enabled, display errors disabled, and log errors enabled. The admin document root is the Laravel `app/backend/public` directory in the FastCGI namespace. CLI remains PHP 8.4.24 at `/usr/local/php84/bin/php` with its separate non-browser limits. Run #10 has now verified real preview package download, result upload/finalization, and browser serving; representative large-file SFTP import timing remains separate.

`PIANISSIMO_PREVIEW_WORKFLOW_ENABLED` is configured/enabled. `PIANISSIMO_PRODUCTION_WORKFLOW_ENABLED` remains `false`. Production publication and public activation remain disabled. The public site has **not** been cut over; `/httpdocs` and any existing WordPress/public hosting state must be inspected and backed up before any separately authorized cutover. No current host-backup inventory was independently verified in this task.

## Remaining follow-up

1. Choose final application/SFTP-import maxima only after representative FastCGI import timing; keep the conservative 65,536 KiB examples for now.
2. Separately verify one controlled failed SFTP import and representative large-file import timing; the successful `pianissimo-sftp-test` record has already been cleaned up.
3. Keep the production workflow disabled. Successful media import, preview, backend deployment, or completed hosting upgrade does not authorize public cutover, which requires separate explicit authorization and rollback planning.

The content-addressed unchanged-draft build cache and terminal GitHub failure callback remain follow-up work. The generic session-authenticated `/draft-preview/assets/{path}` route removes the numeric preview ID from new build output, but no cross-preview result cache is activated. A cache must be keyed by `sourceRevision + packageSha256`, revalidate the complete cached inventory, and never serve a result whose package identity differs. A future failure callback should accept only an allowlisted failure code and clean up with preview expiry/invalidation.

