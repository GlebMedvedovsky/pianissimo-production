# Production status

Last documentation review: 20 October 2026.

Open this checkpoint together with [NETCUP_RUNBOOK.md](NETCUP_RUNBOOK.md) and [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md) before production work. This file distinguishes current owner-provided status from older, independently recorded implementation checkpoints.

## Current owner-provided status

The following values were supplied by the project owner for this documentation update. They were **not independently queried or verified against the production CMS, database, filesystem or GitHub Actions**:

- the project is publicly in Production;
- active production history includes **Publication no. 6**;
- last public publication: **8 October 2026, 07:44**;
- last Apply: **8 October 2026, 08:10**;
- further saved changes exist that have not yet been reviewed and applied;
- Dashboard counts reported on 19 October 2026: **16 Projekte, 18 Tracks, 106 Medien**.

These facts supersede the older statement below that the production publisher and public site were inactive. Before any operational action, verify the current active publication UUID, sequence, source revision, package/release digests, current release target and physical `httpdocs` state through an explicitly authorised read-only production check.

## Current architecture represented by the repository

The repository implements the complete controlled path:

`CMS Draft → private Preview → semantic Review → explicit Apply → immutable Production package → exact-revision GitHub Actions build → validated Netcup upload → server-side activation`

The accepted website state is derived from the latest valid Apply or rollback receipt. A production package contains a release-mode manifest and the referenced public media inventory. GitHub Actions has read-only access to repository contents, downloads the immutable package from the authenticated CMS API, builds the exact configured Git revision and transfers a signed static release for server-side activation.

The public frontend is static and does not query MariaDB at runtime. The production CMS, publication lifecycle and content/media storage remain server-side.

## Code synchronisation and content synchronisation

- **Code:** Git/GitHub is the source of truth. Publication is bound to one exact `CMS_SOURCE_REVISION`.
- **CMS content:** MariaDB and private storage are the source of truth for drafts, originals, managed media and publication records.
- **Accepted website state:** active manifest, Apply/revision receipts and managed public media.
- **Published website state:** active `ProductionPublication`, retained static release and physical public directory.

CMS edits are not automatically committed or pushed to GitHub. Updating code does not update production content, and cloning/pulling the repository does not retrieve the latest CMS draft or active publication. A safe content synchronisation therefore requires a separately authorised, checksummed database/storage snapshot; a safe code deployment must preserve that runtime state.

## Operational ownership still outside this verification

SEO fields, robots/noindex controls, social metadata and consent-aware Analytics configuration exist in the CMS. Final indexing decisions, search-engine submission, provider identifiers, legal/consent review and Analytics operation are left to the site owner. This is an operational boundary, not a known implementation defect. This documentation update does not claim that external SEO indexing or Analytics collection is fully configured or independently verified.

No production request, database query, storage inspection, deployment, rollback, DNS change, SMTP change or tracking activation was performed during this documentation update.

## Historical checkpoint — 2 October 2026

The remainder of this file preserves the earlier private-preview checkpoint as dated evidence. Statements that the production workflow was disabled or that public cutover had not happened describe **2 October 2026 only** and are not the current status.

- Repository: `GlebMedvedovsky/pianissimo-production`; the current branch for this work is `main`.
- Private-preview contract v2 was deployed successfully to Netcup on 2026-10-02 from source revision `0f2d1982deacf34b615a66ae9e9bff8b0b9e1514`.
- The prior media-heavy production preview run #9 used `CMS_SOURCE_REVISION=cf97951aeda4f7db5d98db60b6cc6d004ac1f975`; the first successful live contract-v2 verification was run #10 at the deployed revision above.
- Historical state on 2 October 2026: the production workflow was disabled, `releases/static/current` was not activated, and the public site remained untouched.
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

The first real private-preview dispatch reached GitHub with repository, workflow, active-state, ref, and dispatch permission checks succeeding, but GitHub rejected the workflow before creating a run because job-level `env` referenced the unavailable `${{ runner.temp }}` context. This branch moves preview temporary-path initialization to runner step time through `$RUNNER_TEMP` and `$GITHUB_ENV`. The same latent parser defect was found and corrected proactively in the production publisher workflow while `PIANISSIMO_PRODUCTION_WORKFLOW_ENABLED` was `false`; the 2 October instructions required the gate to remain disabled at that time. Before the successful dispatch, local real-build testing also found that frontend code invented undeclared `-480.webp` paths and that preview CSS used public-root `/_astro/` font URLs. The implementation now uses only canonical manifest media paths and a session-authenticated `/draft-preview/assets/{path}` base; production build URLs remain unchanged.

This branch implements the owner-facing large-media path: FileZilla/SFTP upload to `/pianissimo-production.de/private/incoming/media` → authenticated CMS `Medien → Große Datei importieren` selection → streamed change detection and existing `MediaStorage` validation → canonical private original → media record. Browser, application, and SFTP-import limits are separately configurable. A controlled production import for `pianissimo-sftp-test` verified incoming listing and consumption, canonical private storage, and the `MediaAsset` row. Its initial edit redirect exposed Filament local-disk hydration; after patch `3ef1372`, the edit page returned HTTP 200, a harmless metadata save succeeded, and authenticated **Datei ansehen** succeeded without a new `production.ERROR`. The unused test asset was then deleted through the CMS: its active row disappeared, soft-deleted history remained, its canonical original was physically removed, and no incoming source remained.

Post-upgrade verification recorded PHP 8.4 FastCGI under Apache with `memory_limit=512M`, `max_execution_time=180`, `max_input_time=60`, `post_max_size=128M`, `upload_max_filesize=128M`, uploads enabled, display errors disabled, and log errors enabled. The admin document root is the Laravel `app/backend/public` directory in the FastCGI namespace. CLI remains PHP 8.4.24 at `/usr/local/php84/bin/php` with its separate non-browser limits. Run #10 has now verified real preview package download, result upload/finalization, and browser serving; representative large-file SFTP import timing remains separate.

Historical state on 2 October 2026: `PIANISSIMO_PREVIEW_WORKFLOW_ENABLED` was enabled, `PIANISSIMO_PRODUCTION_WORKFLOW_ENABLED` was `false`, production activation was disabled and the public site had not been cut over. This is retained only as dated evidence; it does not establish the current gate or release state.

## Remaining follow-up

1. Choose final application/SFTP-import maxima only after representative FastCGI import timing; keep the conservative 65,536 KiB examples for now.
2. Separately verify one controlled failed SFTP import and representative large-file import timing; the successful `pianissimo-sftp-test` record has already been cleaned up.
3. At that checkpoint, keep the production workflow disabled. Successful media import, preview, backend deployment, or hosting upgrade did not authorize public cutover.

The content-addressed unchanged-draft build cache and terminal GitHub failure callback remain follow-up work. The generic session-authenticated `/draft-preview/assets/{path}` route removes the numeric preview ID from new build output, but no cross-preview result cache is activated. A cache must be keyed by `sourceRevision + packageSha256`, revalidate the complete cached inventory, and never serve a result whose package identity differs. A future failure callback should accept only an allowlisted failure code and clean up with preview expiry/invalidation.

