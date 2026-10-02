# Production status — 2026-10-02

Open this checkpoint together with [NETCUP_RUNBOOK.md](NETCUP_RUNBOOK.md) at the start of future production work.

- This local optimization started on `main` at `907256874225d5d8de8b43ebacab7b7777773534`. The successful live run used the intentionally unchanged production `CMS_SOURCE_REVISION` `cf97951aeda4f7db5d98db60b6cc6d004ac1f975`; this task did not change that production value.

- Repository: `GlebMedvedovsky/pianissimo-production`; the current branch for this work is `main`.
- Pushed `main` before the local preview-v2 optimization: `907256874225d5d8de8b43ebacab7b7777773534`.
- The preview-v2 optimization remains local and uncommitted. Before any authorized deployment, operators must verify the current Git status, local HEAD, and remote branch state.
- The successful prior production preview used `CMS_SOURCE_REVISION=cf97951aeda4f7db5d98db60b6cc6d004ac1f975`. The production workflow remains disabled and the public site remains untouched.
- CMS: `https://admin.pianissimo-production.de`; public URL: `https://pianissimo-production.de`.
- Hosting: **Webhosting 2000 was verified active on 2026-10-01**. WCP showed 75 GB webspace, about 1.8 GB / 2% used, about 73.2 GB available at that moment, and unlimited traffic. Usage/free observations will change over time.

## Working production state

Laravel/Filament, admin login, MySQL, all migrations, idempotent CMS import, Filament assets, FastCGI production-media reads, and private Draft Preview snapshot creation work. SSH and FastCGI use different absolute namespaces; `CmsConfiguration` performs the exact runtime-root translation. Production storage uses the relative `../../private/runtime/cms/storage` symlink.

The prior package Draft Preview blocker under FastCGI was caused by symlink detection probing `/var` above `open_basedir`. This branch addresses it by starting at `dirname(base_path(), 2)`, rejecting roots outside it before creation, checking the runtime root and descendants, and adding regression coverage including a no-ancestor-probe assertion.

Package preview remains private and expiring: CMS snapshot/package → exact-revision GitHub Actions Astro build → authenticated result upload → strict archive/inventory validation → private Laravel serving. This branch implements result return as authenticated 4 MiB chunks plus finalization instead of one potentially large POST. It does not involve the production release pointer.

The real production private-preview E2E is now successful. GitHub private preview run **#9** completed successfully, and the owner verified the homepage, Film Music, Directing, project pages, texts, CSS, fonts, media, navigation, and the banner **“Entwurfs-Vorschau · Nicht veröffentlicht”**. Netcup Apache/FastCGI required `backend/public/.htaccess` to forward `HTTP Authorization` into `HTTP_AUTHORIZATION`. The public site was untouched and `PIANISSIMO_PRODUCTION_WORKFLOW_ENABLED` remains `false`.

The successful run took about three minutes: package download was about 14 seconds, the 21-page Astro build itself about 7 seconds, and preview-result chunk upload/finalization about 148 seconds. The dominant cost was transferring roughly 200+ MiB of original media from Netcup to GitHub and then returning duplicate copies. The local contract-v2 performance patch changes the runner transport to manifest plus cryptographically bound media metadata and changes the return archive to generated HTML/CSS/JS/fonts/static assets only. Browser media continues through authenticated `/media/preview/...` serving. This performance patch is local only until separately reviewed and deployed.

The first real private-preview dispatch reached GitHub with repository, workflow, active-state, ref, and dispatch permission checks succeeding, but GitHub rejected the workflow before creating a run because job-level `env` referenced the unavailable `${{ runner.temp }}` context. This branch moves preview temporary-path initialization to runner step time through `$RUNNER_TEMP` and `$GITHUB_ENV`. The same latent parser defect was found and corrected proactively in the production publisher workflow while `PIANISSIMO_PRODUCTION_WORKFLOW_ENABLED` remained `false`; that gate must stay disabled. Before the successful dispatch, local real-build testing also found that frontend code invented undeclared `-480.webp` paths and that preview CSS used public-root `/_astro/` font URLs. The implementation now uses only canonical manifest media paths and a session-authenticated `/draft-preview/assets/{path}` base; production build URLs remain unchanged.

This branch implements the owner-facing large-media path: FileZilla/SFTP upload to `/pianissimo-production.de/private/incoming/media` → authenticated CMS `Medien → Große Datei importieren` selection → streamed change detection and existing `MediaStorage` validation → canonical private original → media record. Browser, application, and SFTP-import limits are separately configurable. A controlled production import for `pianissimo-sftp-test` verified incoming listing and consumption, canonical private storage, and the `MediaAsset` row. Its initial edit redirect exposed Filament local-disk hydration; after patch `3ef1372`, the edit page returned HTTP 200, a harmless metadata save succeeded, and authenticated **Datei ansehen** succeeded without a new `production.ERROR`. The unused test asset was then deleted through the CMS: its active row disappeared, soft-deleted history remained, its canonical original was physically removed, and no incoming source remained.

Post-upgrade verification recorded PHP 8.4 FastCGI under Apache with `memory_limit=512M`, `max_execution_time=180`, `max_input_time=60`, `post_max_size=128M`, `upload_max_filesize=128M`, uploads enabled, display errors disabled, and log errors enabled. The admin document root is the Laravel `app/backend/public` directory in the FastCGI namespace. CLI remains PHP 8.4.24 at `/usr/local/php84/bin/php` with its separate non-browser limits. These facts do not yet verify a real preview chunk or representative large-file SFTP import timing.

`PIANISSIMO_PREVIEW_WORKFLOW_ENABLED` is configured/enabled. `PIANISSIMO_PRODUCTION_WORKFLOW_ENABLED` remains `false`. Production publication and public activation remain disabled. The public site has **not** been cut over; `/httpdocs` and any existing WordPress/public hosting state must be inspected and backed up before any separately authorized cutover. No current host-backup inventory was independently verified in this task.

## Next steps after local review

1. Verify the current branch, working-tree status, local HEAD, and remote state; review the recorded test results and ensure the intended revision is committed and pushed before any authorized deployment.
2. Choose final application/SFTP-import maxima only after representative FastCGI import timing; keep the conservative 65,536 KiB examples for now.
3. If authorized, deploy only the reviewed backend/workflow revision while preserving `.env`, database, storage, and public files; set `CMS_SOURCE_REVISION` to the exact pushed SHA and run `artisan optimize:clear`, never shared CLI `config:cache`.
4. After an authorized deployment of the contract-v2 performance patch, run a new private preview and record package bytes/time, result bytes/time, total wait, authenticated media/assets, expiry/invalidation, and changed-draft behavior. Do not change the production workflow gate or public release pointer.
5. Separately verify one controlled failed SFTP import and representative large-file import timing; the successful `pianissimo-sftp-test` record has already been cleaned up.
6. Keep the production workflow disabled. Successful media import, preview, backend deployment, or completed hosting upgrade does not authorize public cutover, which requires separate explicit authorization and rollback planning.

The content-addressed unchanged-draft build cache and terminal GitHub failure callback remain follow-up work. The generic session-authenticated `/draft-preview/assets/{path}` route removes the numeric preview ID from new build output, but no cross-preview result cache is activated. A cache must be keyed by `sourceRevision + packageSha256`, revalidate the complete cached inventory, and never serve a result whose package identity differs. A future failure callback should accept only an allowlisted failure code and clean up with preview expiry/invalidation.

