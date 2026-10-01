# Production status — 2026-10-01

Open this checkpoint together with [NETCUP_RUNBOOK.md](NETCUP_RUNBOOK.md) at the start of future production work.

- Repository: `GlebMedvedovsky/pianissimo-production`; the hardening work is on branch `feature/netcup-production-hardening`.
- Last pushed `main` before the current compatibility work: `7ae749ac83667a8df1faa79dedd5e66703135a2c`.
- The branch contains the Netcup compatibility, chunked-preview, large-media SFTP import, test, workflow, and documentation work described here. It has been locally reviewed and tested, but is not deployed to production. Before deployment, operators must verify the current Git status, local HEAD, and remote branch state.
- The current context confirms the pushed SHA above, but does not independently prove the value currently stored in the server's real `CMS_SOURCE_REVISION`. Verify it on-host before the next deployment.
- CMS: `https://admin.pianissimo-production.de`; public URL: `https://pianissimo-production.de`.
- Hosting: **Webhosting 2000 was verified active on 2026-10-01**. WCP showed 75 GB webspace, about 1.8 GB / 2% used, about 73.2 GB available at that moment, and unlimited traffic. Usage/free observations will change over time.

## Working production state

Laravel/Filament, admin login, MySQL, all migrations, idempotent CMS import, Filament assets, FastCGI production-media reads, and private Draft Preview snapshot creation work. SSH and FastCGI use different absolute namespaces; `CmsConfiguration` performs the exact runtime-root translation. Production storage uses the relative `../../private/runtime/cms/storage` symlink.

The prior package Draft Preview blocker under FastCGI was caused by symlink detection probing `/var` above `open_basedir`. This branch addresses it by starting at `dirname(base_path(), 2)`, rejecting roots outside it before creation, checking the runtime root and descendants, and adding regression coverage including a no-ancestor-probe assertion.

Package preview remains private and expiring: CMS snapshot/package → exact-revision GitHub Actions Astro build → authenticated result upload → strict archive/inventory validation → private Laravel serving. This branch implements result return as authenticated 4 MiB chunks plus finalization instead of one potentially large POST. It does not involve the production release pointer.

This branch implements the owner-facing large-media path: FileZilla/SFTP upload to `/pianissimo-production.de/private/incoming/media` → authenticated CMS `Medien → Große Datei importieren` selection → streamed change detection and existing `MediaStorage` validation → canonical private original → media record. Browser, application, and SFTP-import limits are separately configurable. A controlled production import for `pianissimo-sftp-test` successfully verified incoming-file consumption, canonical private storage, and the `MediaAsset` database row. The subsequent edit-page redirect exposed a Filament defect: the shared upload component hydrated the canonical `storage_path` through Laravel's `local` disk. This branch fixes that boundary by keeping the create upload component out of the edit schema and resolving authenticated managed-media reads through `MediaStorage`. The existing production test record still requires edit/open re-verification after reviewed patch deployment.

Post-upgrade verification recorded PHP 8.4 FastCGI under Apache with `memory_limit=512M`, `max_execution_time=180`, `max_input_time=60`, `post_max_size=128M`, `upload_max_filesize=128M`, uploads enabled, display errors disabled, and log errors enabled. The admin document root is the Laravel `app/backend/public` directory in the FastCGI namespace. CLI remains PHP 8.4.24 at `/usr/local/php84/bin/php` with its separate non-browser limits. These facts do not yet verify a real preview chunk or representative large SFTP import.

`PIANISSIMO_PREVIEW_WORKFLOW_ENABLED` is configured/enabled. `PIANISSIMO_PRODUCTION_WORKFLOW_ENABLED` remains `false`. Production publication and public activation remain disabled. The public site has **not** been cut over; `/httpdocs` and any existing WordPress/public hosting state must be inspected and backed up before any separately authorized cutover. No current host-backup inventory was independently verified in this task.

## Next steps after local review

1. Verify the current branch, working-tree status, local HEAD, and remote state; review the recorded test results and ensure the intended revision is committed and pushed before any authorized deployment.
2. Choose final application/SFTP-import maxima only after representative FastCGI import timing; keep the conservative 65,536 KiB examples for now.
3. If authorized, deploy only the reviewed backend/workflow revision while preserving `.env`, database, storage, and public files; set `CMS_SOURCE_REVISION` to the exact pushed SHA and run `artisan optimize:clear`, never shared CLI `config:cache`.
4. After deploying the reviewed patch, open and edit the existing `pianissimo-sftp-test` record and verify **Datei ansehen** through the FastCGI application context before cleaning up the record.
5. Test a real authenticated 4 MiB preview chunk and finalization timing, one controlled failed SFTP import, representative large-file import timing, and an end-to-end private package preview.
6. Keep the production workflow disabled. Successful media import, preview, backend deployment, or completed hosting upgrade does not authorize public cutover, which requires separate explicit authorization and rollback planning.

