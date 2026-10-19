# Pianissimo Production

Production portfolio platform for composer and director Sebastian: a static, media-rich Astro frontend, an editorial Laravel/Filament CMS, and a controlled publication pipeline from reviewed CMS content to Netcup hosting.

- Public website: `https://pianissimo-production.de`
- CMS: `https://admin.pianissimo-production.de`

## Project status

Pianissimo is in production. The repository contains the application code, content contracts, publication tooling, automated checks, and operational documentation. Editorial data created in the production CMS is deliberately stored outside Git in MariaDB and private storage.

According to information supplied by the project owner on 19 October 2026, the active production history includes **Publication no. 6**, published on **8 October 2026 at 07:44** and last applied at **08:10**. The owner also reported additional changes that had not yet been reviewed and applied. These values have not been independently verified against the production server in this documentation update; see [Production status](docs/PRODUCTION_STATUS.md).

## Architecture

| Layer | Technology | Responsibility |
| --- | --- | --- |
| Public frontend | Astro, TypeScript, static HTML/CSS/JavaScript | Portfolio pages, responsive media presentation, project routes, filters, playback, consent-aware external media and legal pages |
| Editorial CMS | Laravel, Filament, PHP | German-language administration for projects, tracks, media, pages, SEO, legal content, consent settings and publication control |
| Editorial persistence | MariaDB | Draft content, relationships, settings, audit records and publication lifecycle records |
| Media persistence | Private server storage | Originals, managed derivatives, preview data, apply/rollback state and immutable publication packages |
| Build and publication | GitHub Actions | Exact-revision checkout, package validation, isolated Astro build, release integrity generation and controlled Netcup transfer |
| Hosting | Netcup Webhosting | Laravel CMS, private runtime storage, retained static releases and the physical public document root |

The public site has no live database dependency. A publication is converted into a validated static release before activation.

## Editorial and production workflow

The owner-facing workflow is intentionally staged:

1. **Draft speichern** — saves editorial changes in MariaDB/private storage; the active website does not change.
2. **Website-Vorschau öffnen** — builds or opens a private preview of the current saved draft.
3. **Änderungen prüfen** — creates a review plan against the active website state.
4. **Für Website übernehmen** — explicitly applies the reviewed manifest and managed-media changes to the accepted website state.
5. **Jetzt öffentlich veröffentlichen** — creates an immutable production package, dispatches the production workflow, builds the exact source revision, validates and transfers the release, then activates it on the server.

Saving, applying and publishing are separate actions. Failed validation or activation must leave the preceding public release available; rollback is an explicit operator action.

Detailed references:

- [CMS handbook](docs/PIANISSIMO_CMS_HANDBUCH_DE.md)
- [Production environment and publication contract](docs/PRODUCTION_ENVIRONMENT.md)
- [Deployment checklist](docs/DEPLOYMENT_CHECKLIST.md)
- [Netcup runbook](docs/NETCUP_RUNBOOK.md)

## Code synchronisation vs. content synchronisation

Code and production content have different sources of truth:

- **Code synchronisation** uses Git and GitHub. A production publication records and builds one exact 40-character source revision.
- **Content synchronisation** uses the production MariaDB database and private storage. CMS edits, uploaded originals, managed media, active-manifest state and apply/rollback receipts do not automatically become Git commits.
- GitHub Actions receives an immutable publication package from the authenticated CMS API. The production workflow has read-only repository contents permission and does not commit or push CMS content back to GitHub.

Consequently, `git pull` or a fresh clone can update application code but cannot recover the current production draft or published content by itself. Before backend deployment or local content recovery, preserve the production database, private storage, active manifest, apply state and retained release metadata as separate verified backups. Never replace those runtime assets with repository files during a code-only deployment.

## Repository structure

| Path | Purpose |
| --- | --- |
| `frontend/` | Astro application and public static assets |
| `backend/` | Laravel/Filament CMS, services, migrations, commands and backend tests |
| `content/` | Repository content baseline used for development and initialisation; not an automatic mirror of Production |
| `contracts/` | Shared manifest schema and TypeScript validation contracts |
| `scripts/` | Deterministic build, transport, validation and test utilities |
| `.github/workflows/` | Private-preview and production-publisher workflows |
| `tests/unit/` | Node/TypeScript unit and publication-contract tests |
| `tests/e2e/` | Playwright browser regression tests |
| `docs/` | Current operations, architecture and historical design/source records |

Stable project, track and asset IDs form part of the content contract and must be preserved across imports and publications.

## Local development

Requirements:

- Node.js `>=22.12.0` and npm;
- PHP `^8.4` and Composer for a native backend setup, or Docker for the provided local stack;
- MariaDB for the CMS;
- Chrome for the configured Playwright projects.

Frontend:

```powershell
Set-Location D:\Projects\Sebastian
npm.cmd ci
npm.cmd run dev
```

The frontend is available at `http://127.0.0.1:4321/`.

Local CMS:

```powershell
Set-Location D:\Projects\Sebastian
pwsh -File backend/Initialize-Local.ps1
pwsh -File backend/Start-Local.ps1
docker compose -f backend/compose.yaml exec app php artisan migrate
```

The local CMS is available at `http://127.0.0.1:8080/admin`. Local credentials, database contents and runtime storage are ignored and must never be committed.

See [backend/README.md](backend/README.md) for installation, persistence and CMS commands.

## Validation and tests

Available project commands include:

```powershell
npm.cmd test
npm.cmd run test:publication-cross-runtime
npm.cmd run check
npm.cmd run build
npm.cmd run test:e2e
docker compose -f backend/compose.yaml exec app php vendor/bin/phpunit
```

The suites cover content contracts, public-content projection, preview and production package handling, workflow constraints, transport extraction, CMS behaviour, publication lifecycle, release integrity, activation/rollback boundaries and representative browser behaviour.

These commands are listed as repository capabilities. They were **not executed as part of the October 2026 documentation-only update**, so this document does not claim a new passing test run. Use the [deployment checklist](docs/DEPLOYMENT_CHECKLIST.md) to select checks proportionate to a future change.

## Production safety

- Production publishing, deployment, rollback, DNS, SMTP, analytics and tracking changes require explicit authorization.
- Never commit `.env` files, credentials, tokens, private keys, database dumps, private media or runtime archives.
- Preserve the real production `.env`, MariaDB data, private storage, active manifest/apply state, retained releases and public-root backup before backend changes.
- Keep code deployment separate from content restoration. Database migrations require a reviewed migration set and a verified backup.
- Do not bypass the CMS Draft → Preview → Review → Apply workflow by editing the active production manifest directly.
- Publication workflows validate immutable identities and digests, but this is not a claim of absolute security or absence of defects.

## SEO and analytics

The CMS provides fields and consent-aware configuration points for SEO metadata, robots/noindex choices, social previews and supported analytics providers. Final search-engine indexing decisions, provider setup, identifiers, consent/legal review and ongoing analytics operation are intentionally left to the site owner.

This is not an implementation defect. It is an operational ownership boundary. The repository does not claim that external indexing, search-console integration, analytics collection or every provider-specific production setting has been independently verified.

## Technische Projektübersicht für Bewerbungen

Pianissimo Production ist eine produktive Portfolio-Plattform mit statischem Astro-/TypeScript-Frontend und einem deutschsprachigen Laravel-/Filament-CMS. Inhalte, Beziehungen und Veröffentlichungsstatus werden in MariaDB verwaltet; Originale, verwaltete Medien, Vorschauen und unveränderliche Veröffentlichungspakete liegen in privatem Storage. Der redaktionelle Ablauf trennt Entwurf, private Vorschau, Review, explizite Übernahme und öffentliche Veröffentlichung.

GitHub Actions checkt für jede Veröffentlichung eine exakt festgelegte Git-Revision aus, validiert das vom CMS bereitgestellte Paket, erzeugt einen isolierten statischen Build und überträgt einen mit Prüfsummen versehenen Release zu Netcup. Serverseitige Aktivierung und Rollback arbeiten mit behaltenen Releases, Integritätsprüfungen und einem physischen öffentlichen Verzeichnis. Das Projekt enthält Unit-, Integrations-, Contract-, Workflow- und Browser-Regressionstests; konkrete Testergebnisse werden nur dokumentiert, wenn der jeweilige Lauf tatsächlich ausgeführt und protokolliert wurde.

SEO-Indexierung und Analytics sind technisch vorbereitete, aber bewusst vom Website-Inhaber zu konfigurierende Betriebsaufgaben. Eine vollständige externe Verifikation dieser Dienste wird nicht behauptet.

## Documentation

- [Production status](docs/PRODUCTION_STATUS.md)
- [CMS handbook](docs/PIANISSIMO_CMS_HANDBUCH_DE.md)
- [Production environment](docs/PRODUCTION_ENVIRONMENT.md)
- [Deployment checklist](docs/DEPLOYMENT_CHECKLIST.md)
- [Netcup runbook](docs/NETCUP_RUNBOOK.md)
- [Content contract](contracts/README.md)
- [Design decisions](docs/DESIGN_DECISIONS.md)
- [Content sources](docs/CONTENT_SOURCES.md)
