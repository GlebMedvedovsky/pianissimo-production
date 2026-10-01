# Pianissimo deployment checklist

This is a strict operator procedure. Preview success never authorizes public production activation.

## 1. Before editing

- Read `docs/NETCUP_RUNBOOK.md` and `docs/PRODUCTION_STATUS.md`.
- Inspect the current branch, `git status`, local HEAD, and `origin/main` without discarding changes.
- Preserve all existing uncommitted work. Stop if its ownership or purpose is unclear.
- Confirm the requested scope. Do not change DNS, hosting plan, real `.env`, workflow gates, or public release state without explicit authorization.

## 2. Local validation

- Run PHP lint for every changed PHP file.
- Run focused backend tests for the affected feature, then the complete backend PHPUnit suite.
- Run the frontend/unit suite, Astro/typecheck, normal production build, and package-preview build when preview/frontend code changed.
- Run `git diff --check`.
- Inspect unstaged and staged paths separately. Exclude `.env`, credentials, private media, generated archives/chunks, logs, caches, test fixtures, and temporary diffs.
- Confirm `PIANISSIMO_PRODUCTION_WORKFLOW_ENABLED` was not enabled and no activation command entered the preview workflow.

PowerShell (local examples):

```powershell
git status --short
git diff --check
docker exec -w /var/www/html pianissimo-cms-app-1 php vendor/bin/phpunit
npm test
npm run check
npm run build
```

Use the repository's current scripts if names differ; record exact commands and results.

## 3. Commit and push (only when explicitly authorized)

- Review the complete intended diff and staged-file list.
- Commit only reviewed source/docs/tests; never secrets or generated runtime material.
- Record the exact commit SHA from `git rev-parse HEAD`.
- Push deliberately, then verify local HEAD, remote branch, and `origin/main` agree.
- Reconfirm the production workflow gate is still `false`.

## 4. Backend deployment (only when explicitly authorized)

- Preserve the real `.env`, database, relative storage symlink/target, uploaded media, preview/private runtime data, and current public files.
- Create and verify a recoverable backup of every runtime file set being replaced, or use a previously verified atomic deployment package/rollback method.
- Do not assume Netcup provides GNU utilities. Prefer PHP/application operations documented by the deployed revision.
- Run PHP syntax checks with the verified binary.
- Set real `CMS_SOURCE_REVISION` to the exact reviewed/pushed lowercase 40-character SHA.
- Run migrations only when reviewed migrations actually exist; back up the database first.
- Clear runtime caches; never freeze the SSH namespace into FastCGI configuration.

Bash (Netcup SSH):

```bash
cd /pianissimo-production.de/app/backend
/usr/local/php84/bin/php artisan optimize:clear
/usr/local/php84/bin/php artisan cms:production-preflight --check-database
```

Do **not** run `config:cache` or a blind `artisan optimize` over SSH.

Before the first large-media deployment, set explicit reviewed values for `CMS_MEDIA_APPLICATION_MAX_KB`, `CMS_MEDIA_UPLOAD_MAX_KB`, `CMS_MEDIA_IMPORT_MAX_KB`, and `CMS_MEDIA_INCOMING_ROOT=/pianissimo-production.de/private/incoming/media`. The production example intentionally keeps the three limits at the currently known 65,536 KiB until larger values are justified by live timing/storage checks. The application and import ceilings may later be raised independently of the browser limit; the import ceiling must never exceed the application ceiling.

The application aligns Livewire's global temporary-upload `max` rule with `CMS_MEDIA_UPLOAD_MAX_KB`, and preflight rejects a contradiction. The effective browser limit is still the lowest of PHP/FastCGI transport, multipart overhead, Livewire, Filament, and the application ceiling. Do not raise the 65,536 KiB browser example merely because FastCGI reports 128M for `post_max_size` and `upload_max_filesize`.

Bash (Netcup SSH, only during an authorized deployment and after confirming the exact target):

```bash
/usr/local/php84/bin/php -r '$p="/pianissimo-production.de/private/incoming/media"; if (is_link($p) || (file_exists($p) && !is_dir($p))) { fwrite(STDERR,"unsafe media incoming path\n"); exit(1); } if (!is_dir($p) && !mkdir($p,0750,true)) { fwrite(STDERR,"cannot create media incoming path\n"); exit(1); } if (!is_readable($p) || !is_writable($p)) { fwrite(STDERR,"media incoming path is not usable\n"); exit(1); } echo "media incoming path ready\n";'
```

This root has no marker file because application cleanup is never recursive: only the selected, revalidated regular incoming file is consumed after a successful import. Do not add unrelated files or symlinks to it.

## 5. FastCGI/browser verification

- Verify `https://admin.pianissimo-production.de` login, Filament CSS/JS/assets, and an authenticated DB-backed page.
- Verify production media read and private runtime read/write as the FastCGI user.
- Confirm runtime path translation and that no code probes ancestors above the runtime site root.
- Confirm the SSH media incoming path translates under FastCGI to the same site-relative `/private/incoming/media` directory and is readable/writable without exposing either physical path publicly.
- Upload a small supported test file through SFTP, wait for completion, open `Inhalte → Medien`, choose **SFTP-Liste aktualisieren** and **Große Datei importieren**, and verify the file is selectable without typing a path.
- Complete the import; verify the incoming item disappears, the canonical private original and media row exist, preview/use work normally, and no staging file remains. Also verify a controlled unsupported file is preserved in incoming while no media row/canonical original is created.
- Open `Hilfe / Handbuch → Große Dateien per SFTP hochladen` and confirm the owner instructions, host/port/user, exact upload folder, completion warning, and no secrets are present.
- Create a private preview; verify package dispatch, waiting state, authenticated result serving, media/assets, navigation, session isolation, `noindex`, `no-store`, and authorization failures.
- Inspect application/server logs for errors without copying tokens, credentials, cookies, paths containing secrets, or archive content into tickets/chat.

## 6. GitHub private-preview workflow

- Verify variable names: `PIANISSIMO_CMS_URL`, `PIANISSIMO_PREVIEW_WORKFLOW_ENABLED`.
- Verify secret name: `PIANISSIMO_PUBLISHER_API_TOKEN`. Never print its value.
- Confirm checkout uses the exact `CMS_SOURCE_REVISION`, permissions remain `contents: read`, and the preview gate is `true`.
- Confirm the result archive is uploaded as sequential 4 MiB chunks to `/result/chunks/{index}`, then finalized at `/result/finalize`; no whole-archive POST is present.
- Confirm the workflow cannot run production activation and cleans runner temporary files on success/failure.
- Test exact-chunk retry, final workflow success, private readiness, stale/expired rejection, and cleanup.

## 7. Verified upgrade facts and remaining live checks

Verified on 2026-10-01: Webhosting 2000 is active; WCP webspace is 75 GB with about 1.8 GB / 2% used and about 73.2 GB available at that moment; traffic is shown as unlimited. FastCGI uses PHP 8.4 under Apache with `memory_limit=512M`, `max_execution_time=180`, `max_input_time=60`, `post_max_size=128M`, `upload_max_filesize=128M`, uploads on, display errors off, and log errors on. The verified admin document root is `/var/www/vhosts/hosting213834.a2e58.netcup.net/pianissimo-production.de/app/backend/public`; `open_basedir` remains consistent with the recorded FastCGI namespace. CLI remains PHP 8.4.24 at `/usr/local/php84/bin/php` with separate CLI limits.

Do not use PHP `disk_total_space()` / `disk_free_space()` values as account capacity: they exposed the shared host filesystem (about 4.1 TiB total / 462 GiB free), not the WCP account quota. The shell also lacks `stat`, `unlink`, and `df`.

A controlled production SFTP import for `pianissimo-sftp-test` verified the incoming-file selection and consumption, canonical private original, and `MediaAsset` database row. Its redirect to the edit page then exposed the Filament local-disk hydration defect addressed by `feature/netcup-production-hardening`: canonical `storage_path` state must not enter a create-upload component on edit, and authenticated managed-media reads must use `MediaStorage`. After reviewed patch deployment, re-open that existing record, save harmless metadata, and verify **Datei ansehen** before deleting the test record.

The following still require live verification after deployment:

- account-quota headroom and temporary double-space needs while a large incoming file is copied/validated;
- streamed SFTP-import timing under FastCGI with representative media, including timeout behavior and the final agreed application/import limits;
- successful 4 MiB raw chunk request and finalization duration;
- absence of an SSH-generated config cache after `optimize:clear`;
- end-to-end package download, chunk upload/finalization, private serving, expiry, invalidation, and cleanup;
- the existing `pianissimo-sftp-test` edit/open path after this patch, plus incomplete-transfer rejection, failed-source preservation, and canonical-original cleanup for a controlled failed import.

Stop if any measured environment differs from `NETCUP_RUNBOOK.md`; update the runbook through review before improvising.

## 8. Public production cutover

- Treat public cutover as a completely separate, explicitly authorized change.
- Keep `PIANISSIMO_PRODUCTION_WORKFLOW_ENABLED=false` until that authorization includes backups, validation, activation, and rollback.
- Back up and inventory `/httpdocs` and any existing WordPress/public installation first.
- Never infer that a successful preview, backend deployment, or hosting upgrade authorizes switching `releases/static/current`, the document root, DNS, or public content.
- Never infer that a successful SFTP media import authorizes a public release or changes production publication state.

## 9. Rollback and emergency handling

- Preserve the prior backend revision, `.env`, database backup, storage/media, release directories, current pointer, and public-root backup.
- Roll back only to an identified, verified revision/configuration pair. Run `optimize:clear` after backend rollback.
- Do not overwrite the database or private storage with code-only backups.
- Do not delete an unknown lock, pending directory, chunk set, release, or current pointer until its owner/process and recovery role are understood.
- If paths, permissions, PHP limits, database state, or hosting behavior differ from the runbook, stop and gather read-only evidence rather than inventing a live fix.

