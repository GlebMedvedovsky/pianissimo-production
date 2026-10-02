# Netcup production runbook

Read this file before changing or diagnosing Pianissimo production. It records stable hosting facts; it is not authorization to deploy or publish.

## Verified environment facts (2026-10-01)

- Provider: Netcup shared Webhosting, not a VPS. **Webhosting 2000 is active and verified.** WCP showed a 75 GB webspace quota, about 1.8 GB / 2% used and about 73.2 GB available at verification time; those usage/free observations are dated and will change. WCP showed traffic as unlimited.
- SSH: `hosting213834@hosting213834.a2e58.netcup.net`, port `22`.
- SSH is chrooted. Its site root is `/pianissimo-production.de`; Laravel is `/pianissimo-production.de/app/backend`.
- CLI PHP is `/usr/local/php84/bin/php`; verified version: PHP `8.4.24`, SAPI `cli`, `memory_limit=128M`, `max_execution_time=0`, `max_input_time=-1`, `post_max_size=8M`, `upload_max_filesize=2M`, and empty `open_basedir`. CLI upload/post values do not describe browser HTTP capacity.
- FastCGI sees the same site at `/var/www/vhosts/hosting213834.a2e58.netcup.net/pianissimo-production.de`.
- `admin.pianissimo-production.de` uses PHP 8.4 as a FastCGI application under Apache with `memory_limit=512M`, `max_execution_time=180`, `max_input_time=60`, `post_max_size=128M`, `upload_max_filesize=128M`, `file_uploads=on`, `display_errors=off`, and `log_errors=on`.
- Its verified document root is `/var/www/vhosts/hosting213834.a2e58.netcup.net/pianissimo-production.de/app/backend/public`.
- Observed FastCGI `open_basedir`:
  `/var/www/vhosts/hosting213834.a2e58.netcup.net/:/tmp/:/var/lib/php/sessions`.
- The CMS URL is `https://admin.pianissimo-production.de`. The public URL is `https://pianissimo-production.de`.
- The CMS document root is the Laravel `public` directory below `app/backend`. The configured public-site root is `/pianissimo-production.de/httpdocs`; a public cutover is a separate, explicitly authorized operation.
- MySQL connectivity, migrations, Laravel/Filament login, CMS import, published Filament assets, production-media reads, and private preview-snapshot writes have been verified. Database passwords are not recorded here.
- `backend/storage` is a relative symlink: `../../private/runtime/cms/storage`. Keep it relative; an SSH-absolute target does not resolve correctly in FastCGI's namespace.
- The shell was observed without `unlink`, `stat`, or `df`. `/usr/bin/sha256sum` was separately verified for the gated production publisher. Do not infer availability of a normal GNU/Linux userspace; PHP filesystem APIs provide the preview/application runtime operations.
- PHP `disk_total_space()` / `disk_free_space()` on `/pianissimo-production.de` reported the shared host filesystem (about 4.1 TiB total and 462 GiB free), not Sebastian's account quota. Never use those figures as account capacity; WCP's 75 GB webspace quota is authoritative for the account.

## Dual filesystem namespace

Production `.env` paths remain in their SSH form under `/pianissimo-production.de`. `CmsConfiguration` derives the runtime site root with `dirname(base_path(), 2)` and translates only that exact configured prefix:

| Context | `base_path()` | Runtime site root |
| --- | --- | --- |
| SSH/CLI | `/pianissimo-production.de/app/backend` | `/pianissimo-production.de` |
| FastCGI | `/var/www/vhosts/hosting213834.a2e58.netcup.net/pianissimo-production.de/app/backend` | `/var/www/vhosts/hosting213834.a2e58.netcup.net/pianissimo-production.de` |

Filesystem security checks must begin at the runtime site root. Under FastCGI they must never probe `/var`, `/var/www`, or `/var/www/vhosts`. Private package, incoming, and result roots must remain below the runtime site root, reject descendant symlinks, and remain outside public roots.

Do not run `config:cache` or `artisan optimize` over SSH and share that cached configuration with FastCGI: it would freeze SSH-form paths for the web namespace. Deployment uses:

```bash
/usr/local/php84/bin/php artisan optimize:clear
```

## Production directory map (SSH namespace)

| Purpose | Path |
| --- | --- |
| Laravel backend | `/pianissimo-production.de/app/backend` |
| Laravel storage symlink target | `/pianissimo-production.de/private/runtime/cms/storage` |
| Schema and active manifest | `/pianissimo-production.de/private/config` |
| Production/public media source | `/pianissimo-production.de/private/storage/media` |
| Managed media | `/pianissimo-production.de/private/storage/media/managed` |
| Original/private media | `/pianissimo-production.de/private/storage/media/originals` |
| Large-media SFTP intake | `/pianissimo-production.de/private/incoming/media` |
| Prepared private media | `/pianissimo-production.de/private/storage/media/prepared` |
| Preview snapshots | `/pianissimo-production.de/private/runtime/draft-previews` |
| Immutable preview packages | `/pianissimo-production.de/private/packages/preview` |
| Preview chunks/assembly | `/pianissimo-production.de/private/incoming/preview` |
| Validated preview results | `/pianissimo-production.de/private/runtime/preview-results` |
| Production packages | `/pianissimo-production.de/private/packages/production` |
| Production incoming | `/pianissimo-production.de/private/incoming/production` |
| Retained static releases | `/pianissimo-production.de/releases/static` |
| Configured public-site root | `/pianissimo-production.de/httpdocs` |

FastCGI uses the same suffixes below its physical runtime site root. None of the `private`, package, incoming, runtime, or release roots may be exposed by either document root.

## Large-media SFTP import

Ordinary media continues through the authenticated CMS browser upload. Sebastian uses FileZilla Client (SFTP, not ordinary FTP) only when a file is too large for that route. The single owner upload target is `/pianissimo-production.de/private/incoming/media/`; FastCGI translates `CMS_MEDIA_INCOMING_ROOT` to `/var/www/vhosts/hosting213834.a2e58.netcup.net/pianissimo-production.de/private/incoming/media`. It must exist, be private, readable/writable by the applicable SSH and FastCGI users, remain below the runtime site root, contain no symlink component, and never overlap either document root. The importer accepts only a CMS-listed basename and never an operator-entered path. No safety marker is used because the importer never recursively deletes this root; it consumes only the selected, revalidated regular file after successful database creation.

Three limits are intentionally distinct:

- `CMS_MEDIA_UPLOAD_MAX_KB`: ordinary browser ceiling used by Filament and the global Livewire temporary-upload `max` rule, and therefore still subject to PHP/FastCGI multipart limits;
- `CMS_MEDIA_APPLICATION_MAX_KB`: absolute ceiling for any managed medium used by validation, preview, and publication;
- `CMS_MEDIA_IMPORT_MAX_KB`: controlled server-side SFTP import ceiling, which must not exceed the application ceiling.

`CMS_MEDIA_MAX_KB` remains only a backward-compatible fallback if the explicit values are absent. Production preflight requires the three explicit variables and verifies that Livewire's effective temporary-upload maximum exactly matches `CMS_MEDIA_UPLOAD_MAX_KB`. The production example remains conservatively at 65,536 KiB. FastCGI's verified 128M POST/file settings do not justify advertising a 128 MiB CMS upload: multipart overhead and actual request behavior still apply, and larger media should use SFTP. Raising the application/import values remains a separate decision after representative import timing.

Import uses PHP filesystem APIs only. It streams in 1 MiB buffers, checks size while copying, hashes the bytes, compares source device/inode/type/size/mtime/ctime before and after the copy, validates MIME/image readability through `MediaStorage`, and promotes to canonical private storage. Immediately before the database transaction it atomically renames the verified incoming file to an unpredictable hidden claim; transaction failure restores the original owner-visible name, while success consumes the claim so the import cannot be repeated accidentally. Validation failure preserves the SFTP source and removes staging/canonical leftovers. The owner handbook explicitly requires waiting until FileZilla reports completion.

## Preview transport

Netcup runs PHP/MySQL/private storage/private serving. GitHub Actions runs Node and Astro; Node, npm, Docker, SSH access, and a long-running queue worker are not required on Netcup for preview builds.

Contract-v2 runner transport contains only `manifest.json` and `preview.json`. The media inventory in `preview.json` is cryptographically bound into `packageSha256`; original draft media remains in the immutable private snapshot and is served only through the authenticated CMS media route. The runner builds a thin deterministic result containing generated HTML/CSS/JS/fonts/static assets and no `media/preview/*` payload files, then uploads fixed **4 MiB (4,194,304-byte)** chunks over the authenticated publisher API. Limits remain 512 chunks and 2 GiB total. The server serializes writes/finalization, re-verifies package media bytes at the final trust boundary, assembles and hashes by streaming, and applies the dedicated preview-result validator. Production `StaticReleaseArchive` semantics remain unchanged and self-contained.

Private-preview contract v2 was deployed on 2026-10-02 at source revision `0f2d1982deacf34b615a66ae9e9bff8b0b9e1514`. Production preflight afterward reported 0 failures and one warning only: `Laravel paths (web user) — requires FastCGI verification`. All five deployed runtime files matched the reviewed staging files byte-for-byte. The temporary deployment archive and staging directory were removed; rollback backup `/pianissimo-production.de/private/backups/backend-before-preview-v2-20261002.tar.gz` was retained.

Run #9 used the prior media-heavy contract and took roughly 191 seconds: about 14 seconds to download the package, about 7 seconds for Astro, and about 148 seconds to upload/finalize the media-heavy result. Run #10 was the first successful live contract-v2 verification and completed successfully at the deployed revision. The user-visible wait was approximately 45.78 seconds; the workflow took about 43 seconds, including about 3 seconds for metadata-only package download, about 5 seconds for `npm ci`, 1.21 seconds for Astro's 21-page build, below 0.2 seconds for validation/archive creation, and about 7 seconds for result upload/finalization. This proves that removing duplicate original-media transfer in both directions removed the dominant live bottleneck.

The status/spinner waiting screen rendered without the old three-second full-page blinking and navigated automatically when ready. Homepage, Film Music, Directing, project routes, CSS, fonts, authenticated media, navigation, and the private-preview banner were verified. After saving **“Kinnhakenhank TEST”**, a new preview displayed that value, proving changed package identity produces current output rather than reusing the old result.

## GitHub configuration names (values are secret or environment-specific)

Variables:

- `PIANISSIMO_CMS_URL`
- `PIANISSIMO_PREVIEW_WORKFLOW_ENABLED` (currently verified `true`)
- `PIANISSIMO_PRODUCTION_WORKFLOW_ENABLED` (currently verified `false`)
- `NETCUP_SSH_HOST`, `NETCUP_SSH_USER`, `NETCUP_SSH_PORT`
- `NETCUP_PIANISSIMO_PRODUCTION_INCOMING_ROOT`, `NETCUP_PIANISSIMO_BACKEND_ROOT`, `NETCUP_PHP_BIN`

Secrets:

- `PIANISSIMO_PUBLISHER_API_TOKEN`
- `NETCUP_PRODUCTION_SSH_PRIVATE_KEY`
- `NETCUP_SSH_KNOWN_HOSTS`

Never log or share secret values, `.env`, `APP_KEY`, database credentials, bearer/GitHub tokens, SSH private keys, session cookies, password-reset links, private archive contents, or filesystem diagnostics containing secrets.

## Remaining live verification after the completed Webhosting 2000 upgrade

The product, WCP quota, PHP/SAPI limits, document root, dual filesystem namespace, CLI binary/settings, and contract-v2 private-preview pipeline listed above were verified by 2026-10-02. The following remain genuinely unverified:

- SFTP/SSH and FastCGI access to `/private/incoming/media`, including effective ownership and permissions;
- streamed import duration for representative large media, FastCGI timeout behavior, account-quota headroom during staging, and an agreed `CMS_MEDIA_APPLICATION_MAX_KB`/`CMS_MEDIA_IMPORT_MAX_KB` pair;
- CMS listing/import of a completed SFTP test file, source consumption after success, and source preservation after a controlled failure;
- deployed incoming-media canonical cleanup and retry behavior;
- config cache remains absent after `optimize:clear`;
- preview expiry and cleanup remain lifecycle checks for a future controlled verification; package download, upload/finalization timing, authenticated browser serving, and changed-draft correctness are now verified.

