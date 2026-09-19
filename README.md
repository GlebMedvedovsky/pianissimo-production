# Pianissimo Production

[English](#english) · [Русский](#русский)

## English

### Purpose and current status

Pianissimo Production is an English-language portfolio for **Film Music** and **Directing**, built as a static Astro + TypeScript frontend. Stage **03A** adds a local Laravel/Filament CMS with German administration, authentication, MariaDB and validated candidate exports in `backend`; **03B** adds separate reviewed local apply and rollback commands. The public frontend reads its active manifest. SMTP delivery, automatic activation on CMS save and production deployment are **not implemented**.

This README describes the current **04A draft** and Gleb’s **single-port shared frontend workflow**. The 4321 workflow supersedes earlier 4321/4322 viewing instructions. Stage 04A separately authorises the existing CMS 8080 tunnel. `README_RU.md` links to this current bilingual guide above its preserved historical 02D text. Historical QA reports remain unchanged.

Implemented: expanding two-part SVG logo and split navigation; dark Film Music and cream Directing; responsive grids, filters, project dialogs/direct routes; shared About, keyboard navigation and reduced motion; coordinated playback. The active draft has **15 unique projects, 13 Film Music cards, 5 Directing cards (4 Director + 1 Assistant Director), 17 catalog tracks with 15 local playback files**, and 6 hover clips. Shared projects retain one identity. CMS also holds 2 additional hidden project drafts and 3 Der Statist tracks. Waveforms remain in the manifest.

The temporary hero plays its complete approximately 84-second edit with native `loop`, initially muted. Hero and the bottom panel control the same video. Explicit sound selection survives repetition; manual pause is respected, and Directing/About pause playback; returning to the hero resumes a navigation pause while preserving an explicit manual pause and the selected sound state. The Kinnhakenhank project uses only the selected V2 trailer, without automatic looping. **Do not connect the full film.** External media require consent and explicit activation. The contact form validates locally and sends no messages.

Sebastian explicitly approved `FINAL_MASTER_Au milieu de l'été_v2.mp3` for Au milieu de l’été in 04A. The received/prepared MP3 is now the playback file, byte-for-byte unchanged, tagged Jazz / Songs. Container duration is 212.1179 s; decoded waveform duration is 212.297142857 s (difference 0.179243 s). Browser timeline/seek/end and waveform checks are recorded in the [04A report](docs/qa/04A/REPORT_RU.md); no trim or re-encoding was performed. No replacement file is expected.

**04A content:** Cello is visible only in Directing (Director, 2026, Still_1 cover and four gallery images), without invented synopsis/credits. Bubblegum Blast is visible under Assistant Director (2024; director Madlen Medvedovskyy), with its explicitly temporary cover; the incoming kitchen video is not connected. Entering Wonderland has a Film Music card, the seven original Babelsberg photos and source text, without audio. Letterboxd remains a CMS draft awaiting approved screenshots, with video blocked. Der Statist remains a draft with all three prepared tracks, Acoustic only, confirmed emotions, nullable soundtrack order and missing metadata; its audio files are private, previewable only by an authenticated admin. Kleopatras Krönung and Kurz vor Abflug – Main Theme are ordinary standalone Music Library entries. Calm Piano and Sensual Feelings are absent; no Demos & Sketches collection exists.

Confirmed display years, priority groups, Directing order, About credit “Samples unterm Sofa” and the single piano-teacher link are applied. The static menu photographs are the accepted working concept. Pending Sebastian materials: Kinnhakenhank’s complete OST/order, Abszission’s complete tracks/order, Leaving Earth’s separate final audio, Entering Wonderland audio, a replacement hero, Der Statist’s cover/text/credits/year/order, approved Letterboxd screenshots and final Bubblegum Blast material. The saved reply names Monday for the audio/hero deliveries and Monday evening for Der Statist; these are delivery estimates, not completed imports. No fake media or guessed order is supplied. Final legal review, integration of additional CMS settings, email delivery and separately authorised production release are separate future tasks.

**04A interface:** cover cropping also holds on hover; generic slogans are removed; the cue reads Scroll. Larger menu labels and whole-half hover retain the SVG geometry. The organic portfolio switch spans most desktop height and the slide lasts 800 ms. A native-scroll GSAP enhancement zooms through the logo gap toward About; mobile uses the simpler scene and reduced motion skips zoom. Director credits come first; Kinnhakenhank uses The Story and its image is in Behind the Scenes. The desktop player floats at about one-third viewport width, 24 px above the edge; the same panel moves inside the native project dialog to remain clickable and keyboard-accessible. Waveforms flex across desktop space and stay hidden on mobile. See [04A QA](docs/qa/04A/REPORT_RU.md) for actual checks and limits.

### Structure

| Path | Role |
|---|---|
| `frontend/src/` | Astro pages/components, styles, configuration, navigation and media controls |
| `frontend/public/` | Browser files, selected web media, favicon and robots file |
| `content/manifest.json` | Active versioned content, asset references, track order and waveform data |
| `content/*-assets.json` | Retained source/derivative metadata; not a reason to rerun historical generators |
| `contracts/` | TypeScript/Zod contract, generated JSON Schema and public-content boundary |
| `scripts/` | Project-owned launch, validation, build, import, research and QA utilities |
| `tests/` | Unit/browser test source and explicitly synthetic audio fixtures |
| `docs/` | Retained design/source history and font licences; private reports/results are ignored |
| `backend/` | Local Laravel/Filament CMS, MariaDB models, import/export, Docker configuration and tests |
| `frontend/dist/` | Ignored historical build, retained for optional technical checks; preview is stopped |
| `.local-builds/02D/dist/` | Ignored candidate build for optional technical checks; not needed for shared viewing |

Frontend dependencies are fixed in the root `package-lock.json` (lockfile version 3), using the `frontend` npm workspace; PHP dependencies are fixed in `backend/composer.lock`. See the [content contract](contracts/README.md), [local CMS guide](backend/README.md), [historical design decisions](docs/DESIGN_DECISIONS.md) and [initial source record](docs/CONTENT_SOURCES.md). Historical notes do not describe the current content; `contracts/content.ts` is authoritative for the current schema, including Games and calm filters.

### Environment and initial setup

Required for local development/builds: **Node.js >=22.12.0 and npm >=9.6.5** (root and locked Astro requirements). The existing environment tested in 02D is Windows / PowerShell **7.6.6**, Node.js **26.5.0**, npm **11.17.0**, Astro **7.3.3**, TypeScript **6.0.3**, GSAP **3.15.0**. No global Astro installation is needed. Do not reinstall Node.js on each run.

The permanent working directory is `D:\Projects\Sebastian`; do not create another working copy. On a fresh authorised checkout with all web media present, install dependencies once from the root:

```powershell
Set-Location D:\Projects\Sebastian
npm.cmd ci
```

Dependencies are already installed on the current machine. Repeat installation only when needed for the lockfile/environment. `.npmrc` keeps the npm cache in `.cache/npm`. `scripts/astro.mjs` disables Astro telemetry for its own process. These settings do not modify system-wide tools.

The additional browser tests use installed Google Chrome through Playwright. Python, Pillow/PyMuPDF and FFmpeg are optional research/media-processing tools, **not prerequisites for serving or building the prepared site**. Downloaded Python packages in `.tools/python` are ignored; project-owned scripts remain in Git. PHP, MySQL and Docker are not required to run the existing frontend; the local CMS uses Docker as described below.

### Daily use: one server on 4321

**4321 is the single server for local development and temporary shared viewing.** Gleb views it locally; Sebastian views the same current dev site through VS Code Ports. Changes update without a separate build. No build, preview or 4322 refresh is needed to show current work.

Check the port owner before starting a server:

```powershell
Set-Location D:\Projects\Sebastian
Get-NetTCPConnection -State Listen -LocalPort 4321 -ErrorAction SilentlyContinue |
    Select-Object LocalAddress,LocalPort,OwningProcess
```

If the query is denied, inspect the owner through available system tools. Keep an already running, healthy project dev server. Do not start a second copy or terminate unrelated processes. If another process owns 4321, stop and resolve ownership; do not switch ports. Vite uses strictPort to reject an occupied port.

Only when this project's dev is not already running:

```powershell
Set-Location D:\Projects\Sebastian
npm.cmd run dev
```

Open **http://127.0.0.1:4321/**. The server remains HTTP on loopback, never 0.0.0.0. The old project preview on 4322 has been stopped; its build and commands are retained for technical checks. Do not use or forward 4322 for ordinary viewing. Its historical build still contains the old full-film Kinnhakenhank link and must not be shown.

In **VS Code → Ports**, remove/stop forwarding 4322 and forward **4321 for the frontend** (the separately authorised CMS 8080 is described below). Select **HTTP** as the local forwarding protocol. The generated external URL remains **HTTPS**. Gleb manually enables/disables **Public/Öffentlich** visibility and closes forwarding when sharing ends. Desktop Ports controls are not available to this agent; these panel actions are manual. No extension, new tunnel service, firewall or router change is needed.

Use the actual URL shown by Ports; do not guess a tunnel address. If Astro rejects its hostname, permit only that exact hostname in server.allowedHosts in frontend/astro.config.mjs. Never use allowedHosts: true or a wildcard for devtunnels.ms. Keep CORS, TLS verification and filesystem protection enabled.

Astro/Vite use their default WebSocket/HMR settings on the same forwarded port. The remote browser should use wss on the actual HTTPS tunnel hostname, never its own localhost. Change WebSocket settings only after reproducing a proxy problem; do not open another public HMR port. The supplied 4321 tunnel has now been tested in Chrome desktop/mobile emulation: WSS connects on that same hostname, HMR triggers a browser module refresh, and no browser-local localhost requests occur. The default hostname/WebSocket configuration works without changes. Repeat these checks when the tunnel URL or forwarding settings change; an HTTP 200 alone is insufficient.

### Optional build checks

Build/preview commands remain available for separately requested technical checks. They are **not daily sharing steps**. This change does not build or start another server. The existing isolated build command preserves frontend/dist:

```powershell
Set-Location D:\Projects\Sebastian
npm.cmd run build:local-draft
```

It writes .local-builds/02D/dist, validates content, builds Astro and checks the output boundary. Content validation also regenerates contracts/manifest.schema.json. The retained node scripts/serve-local-draft.mjs helper uses 4323 for explicitly requested local tests; do not start or forward it for shared viewing. The legacy browser suite also uses 4323.

npm.cmd run build replaces frontend/dist; npm.cmd run preview serves an existing build on 127.0.0.1:4322 without building it. Neither is required for this workflow. Any future technical use must respect occupied ports and must not reopen the obsolete public preview. Netcup/production release remains unauthorised.

### Editing the site

| Change | Actual location |
|---|---|
| Main dark/light/accent colours and About backgrounds | `frontend/src/styles/tokens.css`: `--color-dark`, `--color-light`, `--color-accent`, `--about-bg-dark`, `--about-bg-light` |
| About theme, pause/panel behaviour, transition duration, directing default filter and contact fallback | `frontend/src/config/site.config.ts` |
| Project text, credits, memberships/order, tracks, public contacts/CV and asset assignments | `content/manifest.json` |
| Hero/menu/About media | Manifest `site.mediaSlots`; selected files in `frontend/public/media/`, including `02d/` and `04a/` |
| Overlay, cropping and responsive presentation | `frontend/src/styles/home-backgrounds.css`, `local-draft.css`, `feedback-04a.css` and `global.css` |
| Logo geometry and hero markup | `frontend/src/components/Logo.astro`, `Hero.astro`, `MediaSlot.astro` |
| Playback/loop lifecycle and shared controls | `frontend/src/lib/media/local-video.ts`, `coordinator.ts`, `player.ts` |
| Scroll transition and route behaviour | `frontend/src/lib/navigation/scene.ts`, `modals.ts`, `filters.ts` |

About uses `themeMode: 'follow-section'` by default: Film Music is dark, Directing light and direct entry dark. `'dark'` or `'light'` forces a theme. Keep text contrast and reduced motion intact. Edit approved content through stable IDs and the contract; do not copy questionnaires, private contact fields or credentials into the manifest. Media changes require matching asset references and validation; historical generators can overwrite current content and are not daily startup steps.

### Media and reproducibility

The proposed repository retains **all 175 prepared files in `frontend/public/media` (224,659,483 bytes)**, covering the 99 retained manifest assets and responsive variants, plus the waveform data in `content/manifest.json`. No blanket image/audio/video exclusion is used. The largest candidates are:

| File under `frontend/public/media/02d/` | Bytes | Approx. MiB |
|---|---:|---:|
| `local-f-27d30882cf7b7bde.mp4` — hero | 63,296,756 | 60.36 |
| `local-f-87d401c4a58387a5.mp4` — selected trailer | 37,064,590 | 35.35 |
| `local-f-28ac759ca427c440.mp4` — hover clip | 16,911,759 | 16.13 |
| `local-f-add6eb7c34fc1e72.mp4` — hover clip | 15,238,047 | 14.53 |

Before a future commit/push, decide repository access, media distribution permission and storage strategy. Selection for the next local draft does not authorise public audio or a public source repository. No new media licence is granted, and Git LFS has not been installed or configured. Large original films and archives stay outside the proposed repository.

Local-only materials include the original/reference images under `images`, downloaded originals under `content/imported`, design PDF, task/STARTER packages, questionnaires, answers, research captures, historical instructions and private reports. The separate `D:\Projects\Sebastian_Materials` archive is outside this Git root; this project's `.gitignore` cannot govern a repository created elsewhere.

Ordinary development, validation and building use the committed manifest and prepared public files, not the private archive or STARTER packages. After an authorised clone, retain those web files and install dependencies; do not regenerate them from private originals. If media are later removed from Git, an explicitly documented delivery/restore step into their exact public paths will be necessary before the site is reproducible.

**Reimporting 02D is a separate local workflow:** `npm.cmd run import:local-02d` requires the approved plan, answer records, candidate manifest, original/derivative hashes, source files and baseline backup in `Sebastian_Materials`; it also enforces the permanent project path. That archive and the 02C planner are not distributed here. Historical research/media-generation scripts need their ignored inputs and optional tools. The minimal solution is to retain the prepared web media for builds and keep the private import archive separately available to authorised maintainers. A clean-clone build has **not** been tested.

### Verification and known limits

Stage 04A results and screenshots are in [REPORT_RU.md](docs/qa/04A/REPORT_RU.md). Private CMS `reviewDraft` fields hold structured Impressum/Datenschutz drafts, explicitly requiring legal review. They are excluded from both export files and are not published as final text. Production remains **BLOCKED**. No Netcup, SMTP, analytics, Git commit/push or deployment was performed.

Before the rate limit, 04A completed the reviewed candidate apply, 22/22 unit tests, 29 PHP tests (194 assertions), two subsequent targeted PHP checks, content validation, responsive browser checks and CMS checks through the existing HTTPS tunnel. Successful hero/audio checks are retained, with earlier failures and their successful follow-ups distinguished in the report. Continuation checks and final zoom/About screenshots are recorded separately there; About-transition was not changed during continuation. Stage 04B has not started.

Completed in **02D**, not rerun for this documentation change: 18/18 unit tests; Astro/TypeScript check without errors; content validation; a separate 17-page candidate build; 48/48 tests of the separately stored 02C planner. Chrome desktop/tablet/mobile emulation checked navigation, filters, dialogs, direct routes, image loading, playback, consent, keyboard use, reduced motion and failure states. The legacy `npm.cmd run test:e2e` suite was not run in full for 02D; targeted `scripts/qa-02d-*.mjs` checks were used.

In the historical 02D check, hero repetition was observed through **three complete natural cycles at 1× on desktop and mobile emulation**, without seeking. The interval from the last displayed frame to the first was **66.5–150 ms**, with native browser waiting. The source edit visibly cuts from the final black logo screen to the opening game frame. No seamless loop is claimed; no trim, fade or crossfade was added. Element identity, source, logo position and scroll position stayed stable. Sound state, manual pause and section transitions were checked. Physical iPhone/Android devices, Safari, actual speaker output and external provider availability were not verified.

Existing commands for future local checks:

```powershell
Set-Location D:\Projects\Sebastian
npm.cmd run content:validate
npm.cmd run check
npm.cmd run test
npm.cmd run build:local-draft
npm.cmd run content:release
```

`content:release` currently **must fail**: prototype/temporary materials, missing audio and legal approval keep the production gate closed. Do not weaken it. Synthetic audio in `tests/fixtures` is test data, not Sebastian's work; see its [README](tests/fixtures/README.md).

The completed 02E audit covered documentation and Git candidates only. The later single-port pass stops the old preview, checks dev access/playback/HMR and updates server safeguards; it does not build, reinstall packages or change site content. Historical reports remain unchanged.

The single-port check verified local routes, project dialogs, images, hero/audio/V2-trailer playback and WebSocket updates in Chrome desktop/mobile emulation. A CSS timestamp-only event produced an HMR update and a new browser module request; CSS bytes were unchanged and timestamps restored. All 74 private-file HTTP probes and six virtual secret-name policy checks were blocked. The subsequently supplied HTTPS tunnel was checked with the same page/media scenarios, 74 blocked private-file probes and actual WSS/HMR on desktop/mobile emulation. Its first-visit Microsoft warning was continued through the normal button; TLS/security settings were not disabled. Physical mobile devices and speaker output were not tested in this pass.

### Local materials, Git and release permissions

The workflow is **local draft → verification → owner approval → separately authorised publication**. Gleb has now explicitly authorised temporary viewing of the current dev site through Ports; this does not authorise a public GitHub repository or final production deployment. `next_draft` is not `public`; M07 does not trigger publication. A Git push, opening a tunnel and releasing to the main domain are separate actions. Do not connect Netcup, DNS, SMTP or production services without the appropriate instruction.

`.gitignore` keeps local materials on their existing paths; it never authorises deletion or movement. Check every new service document, answer export, archive, backup or test result against ignore rules immediately. Keep credentials in ignored local files; only genuinely empty `.env.example`/`.env.template` files are eligible. Original private materials must never be placed in `frontend/public` or served build output. Git ignore rules do not protect HTTP access and do not remove already tracked files or secrets from history.

The dev filesystem allowlist is limited to application source/public files, dependencies and contracts. Default secret-file denies remain; private task packages, answers, local docs/originals/builds and the raw manifest are denied over HTTP. An early guard for /@fs/, physical /@id/ modules and absolute Windows file routes applies the same Vite policy before Astro import handlers, including ?import. Server-side content imports remain functional; visitors receive the public projection. Do not store private files in public: Vite’s public directory is served separately from fs.deny. This is a development server that exposes frontend modules, not a dist-only production host.

As of this pass, **no Git repository exists in this directory or its parents**. No `git init` was performed. Tracked/staged files, history and repository remotes therefore cannot be checked; `git check-ignore --no-index` also fails outside a repository. The candidate inventory and independent pattern checks are provisional, not a staged commit. After separately authorised repository creation, verify the real index, local/global exclusions and `git check-ignore -v --no-index` results before staging. Existing tracked private files would require separately approved, exact index-only removal; ignore rules alone are insufficient. No commit, push or deployment has been performed.

### Local CMS — stages 03A / 03B / 04A

**Admin: http://127.0.0.1:8080/admin.** Architecture: MariaDB → Laravel/Filament → validated private content package → a future separately authorised static Astro build. The public website never reads the database. Docker is only the local environment, not a future Netcup requirement. This foundation is not production-ready.

The installed stack uses PHP 8.4.25, Laravel 13.32.0, Filament 5.8.2 and MariaDB 11.8.9, with nginx on loopback 8080. Frontend 4321 and its existing shared-view tunnel remain unchanged. **04A authorises the existing CMS tunnel:** https://1nx12grf-8080.euw.devtunnels.ms/admin. Ports forwards local HTTP; the outside URL is HTTPS with no `:8080`. `config/dev-access.php` trusts only the observed Docker gateway `172.21.0.1` and allows loopback plus the exact supplied host. Laravel reads forwarded host/proto/port; external session cookies are Secure, local HTTP remains usable. Favicon URL generation is deferred until after proxy handling. Login/logout, Livewire/CSRF, assets, sessions and Dark/Light/System were tested through the actual HTTPS tunnel. If the Docker gateway or tunnel changes, inspect its headers and update only the exact allowlist; never trust all proxies/hosts.

Daily start / stop from the permanent root:

```powershell
Set-Location D:\Projects\Sebastian
docker desktop start
pwsh -File backend/Start-Local.ps1
# Stop only this CMS, retaining its database:
docker compose -f backend/compose.yaml stop
```

The start script checks 8080 and refuses another process's port; it never kills processes or silently switches ports. Compose project `pianissimo-cms` contains `app`, `web`, `db`. Named volume `pianissimo-cms_cms_database` persists the editorial and isolated test databases across stop/start. Do not remove the volume or regenerate an existing `.env`/APP_KEY.

For a first installation only (the current machine is already installed):

```powershell
pwsh -File backend/Initialize-Local.ps1
docker compose -f backend/compose.yaml build app
docker compose -f backend/compose.yaml run --rm --no-deps app composer install --no-interaction
pwsh -File backend/Start-Local.ps1
docker compose -f backend/compose.yaml exec app php artisan migrate
docker compose -f backend/compose.yaml exec app php artisan filament:assets
docker compose -f backend/compose.yaml exec app php artisan cms:admin
```

`cms:admin` securely prompts for a personal admin account; `cms:admin --reset` resets an existing password interactively. The verification admin's random credentials are stored only in ignored `backend/storage/app/private/local-admin.json`, never in this README. German editors cover projects/memberships, tracks, media metadata, About/CV, website settings, contact/social, SEO, legal/consent/services and users. New admins default to Dark; Dark/Light/System is saved per user and is independent of the public website theme.

```powershell
docker compose -f backend/compose.yaml exec app php artisan cms:import --dry-run
docker compose -f backend/compose.yaml exec app php artisan cms:import
docker compose -f backend/compose.yaml exec app php artisan cms:export --dry-run
docker compose -f backend/compose.yaml exec app php artisan cms:export
docker compose -f backend/compose.yaml exec app php vendor/bin/phpunit --testdox
```

Import reads the current manifest only, preserves stable IDs and reports conflicts with manual edits/deletions. Repeating it does not duplicate records. Private source registries, answers and original archives are not imported. **Entwurf speichern** changes the CMS database only. Candidate export writes `backend/storage/app/private/exports/candidates/<content-hash>/manifest.json` and a versioned `cms-settings.json` companion for new settings. The existing shared schema validates the manifest; new settings are prepared but not yet consumed by the frontend.

**Lokalen Paketstand sichern** requires confirmation and creates an immutable local package under `exports/published/`; it never builds, deploys or replaces the active manifest. `next_draft` is not public-use approval. Production validation remains blocked. No media is re-encoded; Kinnhakenhank remains V2-only and Au milieu uses the explicitly approved final MP3; its 0.179-second duration difference is documented in 04A.

### CMS → frontend: controlled local round trip (03B)

The four states are **Draft → Candidate → Applied to Frontend**, with **Production Released = false**. Save **Entwurf speichern** in the CMS, then use **Veröffentlichung → Export-Vorschau erstellen**. Saving and exporting do not change the live frontend. An authenticated export produces a private receipt containing an admin ID and package checksums, without credentials.

From the project root, use the full candidate hash shown by the CMS:

```powershell
node scripts/cms-frontend.mjs review <candidate-hash>
node scripts/cms-frontend.mjs apply <plan-hash>
node scripts/cms-frontend.mjs rollback
```

`review` validates the existing contract, references and web-media hashes and displays the complete public-content diff and a plan hash. `apply` is a separate explicit operation: read its summary, then type the complete confirmation hash. It revalidates the package and active baseline, saves an exact private backup, writes a validated temporary file beside the manifest and atomically renames it over the active file. CMS Docker mounts remain read-only for frontend content. Local filesystem access is required; no new HTTP endpoint, port or CMS tunnel is added.

Comparison uses stable IDs, ignores only documented export representation differences (private provenance, collection storage order, empty optional fields, PHP/PDO asset-duration precision), and preserves the local source registry, unused assets and exact unchanged values. Playlist order and track timelines remain significant. Unexpected identity changes, more than 20 changed field paths or a diff larger than 32 KiB are blocked without a force option; reconcile the candidate before continuing. An explicitly reviewed content batch may use `review <candidate-hash> --expected <expected-diff.json>`: the exact candidate hash, baseline hash and every before/after value must match the immutable expected document; apply rechecks its hash. This is the same pipeline, not a force bypass. The reviewed hash also blocks changes made after review. Repeating an already active candidate is a no-op.

`rollback` shows the previous/resulting hashes and separately requires confirmation. It restores the exact validated backup, refuses to overwrite an unrecognised later edit and leaves CMS drafts intact. Remove a temporary test edit explicitly in the CMS afterwards. Backups, plans, recovery journals and content-free audit history live under ignored `backend/storage/app/private/frontend-apply/`; the audit records the exporting admin ID, timestamps, hashes and status. The local command runs as the workstation operator, not a second web login. After a process crash, inspect the PID in `operation.lock` before removing only a confirmed stale lock; keep the revision journal and backup, then review/rollback. Never remove a live operation's lock.

4321 remains the single dev/shared-view server, so applying a draft can be visible through the existing Ports tunnel. The local 03B browser check observed Vite's WebSocket `full-reload`: open pages reloaded automatically after apply/rollback, without manual reload or server restart. No build or preview 4322 refresh is involved. Apply Frontend **does not deploy to Netcup** or grant public audio rights; production release stays blocked. The `cms-settings.json` companion is validated at export but not activated: new SEO/consent/colors/behavior fields need a separate integration step. Existing core fields already supported by the manifest are included.

Admin Theme is in the top-right user menu: Dark / Light / System. It is stored separately per user in `users.admin_theme`, defaults to Dark and changes only Filament. **Website-Farben** remains a separate public-site setting. See [03B verification](docs/qa/03B/REPORT_RU.md) for actual browser/automatic results and limitations.

Only `backend/public` is served by nginx. Database/PHP ports, `.env`, credentials, vendor, private exports and originals are not exposed. Existing dev filesystem protection stays enabled. Mail and tracking remain inactive. Later Netcup work requires separate approval, confirmation of PHP/MySQL hosting and document roots, TLS/access, backups, SMTP and a controlled static build/activation pipeline. See [detailed CMS instructions](backend/README.md) and [settings extension contract](backend/contracts/README.md).

## Русский

### Назначение и текущее состояние

Pianissimo Production — англоязычное портфолио **Film Music** и **Directing**, реализованное как статический frontend на Astro + TypeScript. Этап **03A** добавляет локальную CMS Laravel/Filament с немецким интерфейсом, авторизацией, MariaDB и проверяемым экспортом кандидатов в `backend`; **03B** — отдельные локальные команды применения проверенного плана и отката. Публичный frontend читает действующий manifest. Доставка через SMTP, автоматическая активация при сохранении CMS и публикация production **не реализованы**.

README описывает текущий **черновик 04A** и заданную Глебом **схему совместного просмотра frontend на одном порту**. Схема 4321 заменяет прежнее разделение просмотра на 4321/4322. Этап 04A отдельно разрешает существующий туннель CMS 8080. В начале `README_RU.md` дана ссылка на эту актуальную двуязычную инструкцию, ниже сохранён исторический текст 02D. Исторические QA-отчёты не изменены.

Реализованы: увеличение двухчастного SVG-логотипа и split-navigation; тёмный Film Music и кремовый Directing; адаптивные сетки, фильтры, окна и прямые адреса проектов; общий About, клавиатура и reduced motion; единое воспроизведение. В действующем черновике **15 уникальных проектов, 13 карточек Film Music, 5 карточек Directing (4 Director + 1 Assistant Director), 17 записей каталога с 15 локальными playback-файлами** и 6 hover-клипов. Общие проекты сохраняют один ID. В CMS дополнительно хранятся 2 скрытых проекта и 3 трека Der Statist. Waveform остаются в manifest.

Временный hero воспроизводит полный монтаж длительностью около 84 секунд со штатным `loop`, первоначально без звука. Hero и нижняя панель управляют одним video. Выбранное пользователем состояние звука сохраняется при повторении; ручная пауза соблюдается, Directing/About ставят воспроизведение на паузу; возврат к hero продолжает паузу, вызванную навигацией, сохраняя ручную паузу и выбранное состояние звука. Проект Kinnhakenhank использует только выбранный V2-трейлер без автоматического зацикливания. **Полный фильм не подключать.** Внешние медиа требуют согласия и явного запуска. Форма проверяет ввод локально и ничего не отправляет.

В 04A Себастиан явно подтвердил `FINAL_MASTER_Au milieu de l'été_v2.mp3` для Au milieu de l’été. Уже полученная и подготовленная MP3 теперь подключена как playback-файл без изменения байтов; жанры Jazz / Songs. Container duration — 212,1179 с, decoded waveform duration — 212,297142857 с (разница 0,179243 с). Браузерные проверки timeline/seek/end и waveform описаны в [отчёте 04A](docs/qa/04A/REPORT_RU.md); обрезка и перекодирование не выполнялись. Новый файл не ожидается.

**Контент 04A:** Cello показан только в Directing (Director, 2026, обложка Still_1 и четыре изображения галереи), без выдуманного synopsis/credits. Bubblegum Blast показан в Assistant Director (2024; режиссёр Madlen Medvedovskyy) с явно временной обложкой; полученное видео кухни не подключено. Entering Wonderland получил карточку Film Music, семь исходных фотографий Babelsberg и исходный текст, пока без аудио. Letterboxd остаётся черновиком CMS до получения разрешённых screenshots; видео заблокировано. Der Statist — черновик с тремя подготовленными треками, только Acoustic, подтверждёнными emotions, незаполненным порядком и метаданными; аудиофайлы приватны, preview доступен только авторизованному администратору. Kleopatras Krönung и Kurz vor Abflug – Main Theme добавлены отдельно в обычную Music Library. Calm Piano и Sensual Feelings отсутствуют; коллекция Demos & Sketches не создавалась.

Применены подтверждённые display years, группы приоритетов, порядок Directing, credit About «Samples unterm Sofa» и единственная ссылка на преподавателя фортепиано. Статичные фотографии меню — принятая рабочая концепция. От Себастиана ожидаются полный OST/порядок Kinnhakenhank, все треки/порядок Abszission, отдельное финальное аудио Leaving Earth, аудио Entering Wonderland, замена hero, обложка/текст/credits/год/порядок Der Statist, разрешённые screenshots Letterboxd и окончательные материалы Bubblegum Blast. В сохранённом ответе для аудио/hero указан понедельник, для Der Statist — вечер понедельника; это ориентиры доставки, а не выполненные импорты. Фиктивные медиа и угаданный порядок не добавлены. Юридический review, подключение дополнительных настроек CMS, доставка почты и отдельно разрешённый production release остаются самостоятельными будущими задачами.

**Интерфейс 04A:** crop обложек сохранён и при hover; generic slogans убраны; подсказка — Scroll. Крупные подписи меню и hover всей половины сохраняют геометрию SVG. Органический переключатель занимает большую часть высоты desktop, slide длится 800 мс. GSAP-анимация от обычной прокрутки приближает щель логотипа к About; mobile использует простую сцену, reduced motion отключает zoom. Director расположен первым в credits; у Kinnhakenhank — The Story, изображение перенесено в Behind the Scenes. Desktop-плеер занимает около трети ширины и отстоит от низа на 24 px; та же панель перемещается внутрь native dialog, оставаясь доступной мышью и клавиатурой. Waveform растягивается на desktop и скрыта на mobile. Фактические проверки и ограничения — в [QA 04A](docs/qa/04A/REPORT_RU.md).

### Структура

| Путь | Назначение |
|---|---|
| `frontend/src/` | Страницы/компоненты Astro, стили, конфигурация, навигация и управление медиа |
| `frontend/public/` | Файлы браузера, выбранные web-медиа, favicon и robots |
| `content/manifest.json` | Действующий версионированный контент, ссылки на ассеты, порядок треков и waveform |
| `content/*-assets.json` | Сохранённые метаданные исходников/производных; не повод повторно запускать старые генераторы |
| `contracts/` | Контракт TypeScript/Zod, генерируемая JSON Schema и граница публичного контента |
| `scripts/` | Собственные утилиты запуска, валидации, сборки, импорта, исследования и QA |
| `tests/` | Исходники unit/браузерных тестов и явно синтетические аудиофикстуры |
| `docs/` | Сохранённая история дизайна/источников и лицензии шрифтов; частные отчёты/результаты игнорируются |
| `backend/` | Локальная CMS Laravel/Filament, модели MariaDB, импорт/экспорт, Docker-конфигурация и тесты |
| `frontend/dist/` | Игнорируемая историческая сборка для возможных технических проверок; preview остановлен |
| `.local-builds/02D/dist/` | Игнорируемая сборка для возможных технических проверок; для совместного просмотра не нужна |

Зависимости frontend зафиксированы корневым `package-lock.json` (lockfile версии 3), используется npm workspace `frontend`; PHP-зависимости — в `backend/composer.lock`. См. [контентный контракт](contracts/README.md), [инструкцию локальной CMS](backend/README.md), [исторические решения дизайна](docs/DESIGN_DECISIONS.md) и [первоначальные источники](docs/CONTENT_SOURCES.md). Исторические заметки не описывают текущий контент; актуальная схема определяется `contracts/content.ts`, включая фильтры Games и calm.

### Окружение и первоначальная установка

Для локальной разработки/сборки нужны **Node.js >=22.12.0 и npm >=9.6.5** — требования корневого пакета и зафиксированного Astro. В 02D проверено существующее окружение: Windows / PowerShell **7.6.6**, Node.js **26.5.0**, npm **11.17.0**, Astro **7.3.3**, TypeScript **6.0.3**, GSAP **3.15.0**. Глобальная установка Astro не нужна. Не переустанавливайте Node.js при каждом запуске.

Постоянная рабочая папка — `D:\Projects\Sebastian`; другую рабочую копию создавать не нужно. После отдельно разрешённого клонирования, при наличии всех web-медиа, зависимости устанавливаются один раз из корня:

```powershell
Set-Location D:\Projects\Sebastian
npm.cmd ci
```

На текущей машине зависимости уже установлены. Повторять установку следует только при необходимости для lockfile/окружения. `.npmrc` направляет npm-кеш в `.cache/npm`. `scripts/astro.mjs` отключает телеметрию Astro только для своего процесса. Системные инструменты эти настройки не меняют.

Дополнительные браузерные тесты используют установленный Google Chrome через Playwright. Python, Pillow/PyMuPDF и FFmpeg — необязательные инструменты исследования/обработки медиа, **они не нужны для раздачи или сборки подготовленного сайта**. Загруженные Python-пакеты в `.tools/python` игнорируются; собственные скрипты остаются в Git. PHP, MySQL и Docker не требуются для работы существующего frontend; локальная CMS использует Docker по инструкции ниже.

### Ежедневный запуск: один сервер на 4321

**4321 — единый сервер разработки и временного совместного просмотра.** Глеб смотрит локально; Себастиан через VS Code Ports видит тот же текущий dev-сайт. Изменения обновляются без отдельной сборки. Для показа текущей работы не нужны build, preview и обновление 4322.

Перед запуском проверьте владельца порта:

```powershell
Set-Location D:\Projects\Sebastian
Get-NetTCPConnection -State Listen -LocalPort 4321 -ErrorAction SilentlyContinue |
    Select-Object LocalAddress,LocalPort,OwningProcess
```

Если запросу отказано в доступе, установите владельца доступными системными средствами. Сохраните уже работающий исправный dev проекта. Не запускайте вторую копию и не завершайте посторонние процессы. Если 4321 занят другим процессом, сначала разрешите вопрос владения; на другой порт не переходите. Vite использует strictPort и отклоняет запуск на занятом порту.

Только если dev данного проекта ещё не работает:

```powershell
Set-Location D:\Projects\Sebastian
npm.cmd run dev
```

Откройте **http://127.0.0.1:4321/**. Сервер остаётся HTTP на loopback, без 0.0.0.0. Старый preview проекта на 4322 остановлен; его сборка и команды сохранены для технических проверок. Для обычного просмотра 4322 не используйте и не перенаправляйте. Историческая сборка содержит старую ссылку полного Kinnhakenhank и не должна показываться.

В **VS Code → Ports** удалите/остановите перенаправление 4322 и перенаправьте **4321 для frontend** (отдельно разрешённая CMS 8080 описана ниже). Для локального протокола перенаправления выберите **HTTP**. Созданный внешний адрес остаётся **HTTPS**. Глеб вручную включает/выключает видимость **Public/Öffentlich** и закрывает перенаправление после просмотра. Управление настольной панелью Ports агенту недоступно; эти действия выполняются вручную. Расширение, новый сервис туннелей и изменения firewall/роутера не нужны.

Используйте фактический адрес из Ports; не выдумывайте адрес туннеля. Если Astro отклонит hostname, разрешите только это точное имя в server.allowedHosts файла frontend/astro.config.mjs. Не используйте allowedHosts: true и wildcard для devtunnels.ms. Сохраняйте CORS, проверку TLS и защиту файловой системы.

Astro/Vite используют штатные настройки WebSocket/HMR на том же перенаправленном порту. Внешний браузер должен использовать wss на фактическом HTTPS-домене туннеля, а не собственный localhost. Меняйте WebSocket-настройки только после воспроизводимой проблемы прокси; отдельный публичный HMR-порт не открывайте. Предоставленный туннель 4321 теперь проверен в Chrome desktop/mobile emulation: WSS подключается на том же hostname, HMR вызывает обновление модуля браузером, запросов к localhost браузера нет. Штатная конфигурация hostname/WebSocket работает без изменений. Повторяйте эти проверки при смене адреса туннеля или настроек перенаправления; одного HTTP 200 недостаточно.

### Необязательные проверки сборки

Команды build/preview сохранены для отдельно запрошенных технических проверок. Они **не входят в ежедневный показ сайта**. При смене схемы сборка и запуск другого сервера не выполняются. Существующая отдельная команда сборки сохраняет frontend/dist:

```powershell
Set-Location D:\Projects\Sebastian
npm.cmd run build:local-draft
```

Она пишет в .local-builds/02D/dist, валидирует контент, собирает Astro и проверяет границы выдачи. Валидация также генерирует contracts/manifest.schema.json. Сохранённая утилита node scripts/serve-local-draft.mjs использует 4323 для явно запрошенных локальных тестов; для совместного просмотра не запускайте и не перенаправляйте её. Прежний браузерный набор также использует 4323.

npm.cmd run build заменяет frontend/dist; npm.cmd run preview раздаёт существующую сборку на 127.0.0.1:4322, не собирая её. Для этой схемы обе команды не требуются. Будущие технические проверки должны учитывать занятые порты и не возвращать устаревший публичный preview. Выпуск на Netcup/production по-прежнему не разрешён.

### Где менять сайт

| Изменение | Фактический путь |
|---|---|
| Основные тёмный/светлый/акцентный цвета и фоны About | `frontend/src/styles/tokens.css`: `--color-dark`, `--color-light`, `--color-accent`, `--about-bg-dark`, `--about-bg-light` |
| Тема About, пауза/панель, длительность перехода, начальный фильтр Directing и резервный контакт | `frontend/src/config/site.config.ts` |
| Тексты проектов, credits, memberships/порядок, треки, публичные контакты/CV и назначения ассетов | `content/manifest.json` |
| Медиа hero/меню/About | `site.mediaSlots` в manifest; выбранные файлы в `frontend/public/media/`, включая `02d/` и `04a/` |
| Overlay, кадрирование и адаптивность | `frontend/src/styles/home-backgrounds.css`, `local-draft.css`, `feedback-04a.css` и `global.css` |
| Геометрия логотипа и разметка hero | `frontend/src/components/Logo.astro`, `Hero.astro`, `MediaSlot.astro` |
| Жизненный цикл воспроизведения/loop и общее управление | `frontend/src/lib/media/local-video.ts`, `coordinator.ts`, `player.ts` |
| Переход при прокрутке и поведение маршрутов | `frontend/src/lib/navigation/scene.ts`, `modals.ts`, `filters.ts` |

About по умолчанию использует `themeMode: 'follow-section'`: Film Music тёмный, Directing светлый, прямой вход тёмный. Значения `'dark'` и `'light'` фиксируют тему. Сохраняйте контраст текста и reduced motion. Меняйте согласованный контент через стабильные ID и контракт; не переносите анкеты, частные контактные поля и учётные данные в manifest. Замена медиа требует соответствующих ссылок на ассеты и валидации; исторические генераторы могут перезаписать текущий контент и не входят в ежедневный запуск.

### Медиа и воспроизводимость

В предполагаемом репозитории сохраняются **все 175 подготовленных файлов `frontend/public/media` (224 659 483 байт)** — 99 сохранённых ассетов manifest и адаптивные варианты, а также waveform в `content/manifest.json`. Общего исключения изображений/аудио/видео нет. Крупнейшие кандидаты:

| Файл в `frontend/public/media/02d/` | Байт | Примерно МиБ |
|---|---:|---:|
| `local-f-27d30882cf7b7bde.mp4` — hero | 63 296 756 | 60.36 |
| `local-f-87d401c4a58387a5.mp4` — выбранный трейлер | 37 064 590 | 35.35 |
| `local-f-28ac759ca427c440.mp4` — hover-клип | 16 911 759 | 16.13 |
| `local-f-add6eb7c34fc1e72.mp4` — hover-клип | 15 238 047 | 14.53 |

Перед будущими commit/push нужно решить доступность репозитория, разрешение на распространение медиа и способ хранения. Выбор для следующего локального черновика не разрешает публичное аудио или открытый репозиторий исходников. Новая лицензия на медиа не предоставляется, Git LFS не устанавливался и не настраивался. Большие оригинальные фильмы и архивы остаются вне предполагаемого репозитория.

Только локально остаются исходные/референсные изображения в `images`, исходные загрузки в `content/imported`, дизайн-PDF, задания/STARTER-пакеты, анкеты, ответы, исследовательские снимки, исторические инструкции и частные отчёты. Отдельный архив `D:\Projects\Sebastian_Materials` находится вне этого Git-корня; `.gitignore` проекта не управляет репозиторием, созданным в другом месте.

Обычные разработка, валидация и сборка используют сохранённый manifest и подготовленные public-файлы, а не частный архив или STARTER-пакеты. После разрешённого клонирования нужно сохранить эти web-файлы и установить зависимости; пересоздавать их из частных оригиналов не требуется. Если впоследствии медиа уберут из Git, для воспроизводимости понадобится явно описанный способ получения/восстановления в точные пути public.

**Повторный импорт 02D — отдельная локальная процедура:** `npm.cmd run import:local-02d` требует согласованный план, записи ответов, кандидат manifest, хеши исходников/производных, исходные файлы и базовую резервную копию в `Sebastian_Materials`; он также проверяет постоянный путь проекта. Этот архив и планировщик 02C здесь не распространяются. Историческим скриптам исследования/генерации медиа нужны игнорируемые входные материалы и дополнительные инструменты. Минимальное решение — сохранить подготовленные web-медиа для сборки, а частный архив импорта отдельно предоставлять уполномоченным участникам. Сборка после чистого клонирования **не проверялась**.

### Проверки и известные ограничения

Результаты и скриншоты 04A — в [REPORT_RU.md](docs/qa/04A/REPORT_RU.md). Приватные поля CMS `reviewDraft` содержат структурированные черновики Impressum/Datenschutz с обязательным юридическим review. Они исключены из обоих экспортов и не опубликованы как окончательные тексты. Production остаётся **BLOCKED**. Netcup, SMTP, analytics, Git commit/push и deploy не выполнялись.

До rate limit в 04A завершены проверенный candidate apply, 22/22 unit-теста, 29 PHP-тестов (194 assertions), две последующие целевые PHP-проверки, валидация контента, адаптивные браузерные проверки и проверки CMS через существующий HTTPS-туннель. Успешные hero/audio-проверки сохранены; ранние ошибки и успешные последующие проверки разделены в отчёте. Проверки продолжения и финальные кадры zoom/About записаны там отдельно; About-transition в продолжении не менялся. Этап 04B не начат.

Выполнено в **02D**, без повторного запуска ради документации: 18/18 unit-тестов; Astro/TypeScript без ошибок; валидация контента; отдельная сборка 17 страниц; 48/48 тестов отдельно хранимого планировщика 02C. В Chrome desktop/tablet/mobile emulation проверены навигация, фильтры, окна, прямые адреса, изображения, воспроизведение, consent, клавиатура, reduced motion и состояния ошибок. Старый набор `npm.cmd run test:e2e` в 02D полностью не запускался; использованы целевые проверки `scripts/qa-02d-*.mjs`.

В исторической проверке 02D повтор hero наблюдался в течение **трёх полных естественных циклов на скорости 1× на desktop и мобильной эмуляции**, без перемотки. Интервал между последним отображённым кадром и первым составил **66.5–150 мс**, с ожиданием штатного браузерного плеера. В исходном монтаже заметен переход от финального чёрного экрана с логотипом к игровому первому кадру. Бесшовность не заявляется; обрезка, fade и crossfade не добавлялись. Элемент, источник, положение логотипа и прокрутки сохранялись. Проверены состояние звука, ручная пауза и переходы между разделами. Физические iPhone/Android, Safari, реальный вывод на динамики и доступность внешних провайдеров не проверены.

Существующие команды для будущих локальных проверок:

```powershell
Set-Location D:\Projects\Sebastian
npm.cmd run content:validate
npm.cmd run check
npm.cmd run test
npm.cmd run build:local-draft
npm.cmd run content:release
```

`content:release` сейчас **должен завершаться ошибкой**: prototype/временные материалы, недостающий звук и согласование legal сохраняют блокировку production. Не ослабляйте её. Синтетическое аудио в `tests/fixtures` — тестовые данные, не произведения Себастиана; см. [README фикстур](tests/fixtures/README.md).

Завершённый аудит 02E касался только документации и кандидатов Git. Последующий переход на один порт останавливает старый preview, проверяет доступ/медиа/HMR dev и обновляет защиту сервера; сборка, установка пакетов и изменение контента не выполняются. Исторические отчёты сохраняются без изменений.

При переходе на один порт локально проверены маршруты, окна проектов, изображения, воспроизведение hero/аудио/V2-трейлера и обновления WebSocket в Chrome desktop/mobile emulation. Изменение только времени CSS-файла вызвало HMR и новый запрос модуля браузером; байты CSS не менялись, время восстановлено. Заблокированы все 74 HTTP-пробы частных файлов и шесть виртуальных имён секретных файлов при проверке политики. После получения фактического HTTPS-адреса через туннель проверены те же страницы/медиа, блокировка 74 проб частных файлов и настоящий WSS/HMR на desktop/mobile emulation. Первоначальное предупреждение Microsoft пройдено штатной кнопкой; TLS и настройки безопасности не отключались. Физические мобильные устройства и динамики в этом проходе не проверялись.

### Локальные материалы, Git и разрешения на выпуск

Порядок работы: **локальный черновик → проверка → согласование владельцем → отдельно разрешённая публикация**. Глеб теперь явно разрешил временный просмотр текущего dev через Ports; это не разрешение открытого GitHub-репозитория или окончательной production-публикации. `next_draft` не равен `public`; M07 не запускает публикацию. Git push, открытие туннеля и выпуск на основной домен — отдельные действия. Не подключайте Netcup, DNS, SMTP и production-сервисы без соответствующего задания.

`.gitignore` сохраняет локальные материалы по существующим путям; он никогда не разрешает удаление или перенос. Сразу проверяйте исключение каждого нового служебного документа, экспорта ответов, архива, резервной копии и результата теста. Учётные данные храните в игнорируемых локальных файлах; в репозиторий допустимы только действительно пустые `.env.example`/`.env.template`. Частным оригиналам не место в `frontend/public` или раздаваемой сборке. Git ignore не защищает HTTP-доступ и не удаляет уже отслеживаемые файлы или секреты из истории.

Файловый allowlist dev ограничен исходниками/public приложения, зависимостями и контрактами. Штатные запреты секретных файлов сохранены; частные пакеты заданий, ответы, локальные docs/оригиналы/сборки и сырой manifest запрещены по HTTP. Ранняя проверка /@fs/, физических модулей /@id/ и абсолютных Windows-маршрутов применяет ту же политику Vite до обработчиков импорта Astro, включая ?import. Серверные импорты контента продолжают работать; посетители получают публичную проекцию. Частные файлы нельзя хранить в public: Vite раздаёт этот каталог отдельно от fs.deny. Это dev-сервер с доступными frontend-модулями, а не production-раздача только dist.

На момент этого прохода **Git-репозитория нет ни здесь, ни в родительских каталогах**. `git init` не выполнялся. Поэтому проверить tracked/staged, историю и remotes репозитория невозможно; `git check-ignore --no-index` также не работает вне репозитория. Список кандидатов и независимая проверка шаблонов предварительны и не являются staged-коммитом. После отдельно разрешённого создания репозитория перед staging нужно проверить реальный индекс, локальные/глобальные исключения и результат `git check-ignore -v --no-index`. Если частные файлы уже отслеживаются, понадобится отдельно согласованное точечное исключение только из индекса; одного ignore недостаточно. Commit, push и deploy не выполнялись.

### Локальная CMS — этапы 03A / 03B / 04A

**Admin: http://127.0.0.1:8080/admin.** Архитектура: MariaDB → Laravel/Filament → проверенный приватный пакет контента → будущая отдельно разрешённая статическая сборка Astro. Публичный сайт не обращается к базе данных. Docker служит только локальным окружением и не становится требованием будущего Netcup. Эта основа не объявляется готовой к production.

Установлены PHP 8.4.25, Laravel 13.32.0, Filament 5.8.2 и MariaDB 11.8.9; nginx слушает loopback 8080. Frontend 4321 и его существующий туннель совместного просмотра остаются без изменений. **04A разрешает существующий туннель CMS:** https://1nx12grf-8080.euw.devtunnels.ms/admin. Ports перенаправляет локальный HTTP; снаружи — HTTPS без `:8080`. `config/dev-access.php` доверяет только наблюдавшемуся Docker gateway `172.21.0.1` и разрешает loopback плюс точный указанный host. Laravel учитывает forwarded host/proto/port; внешние session cookies имеют Secure, локальный HTTP продолжает работать. URL favicon вычисляется после обработки proxy. Через фактический HTTPS проверены вход/выход, Livewire/CSRF, assets, sessions и Dark/Light/System. При смене Docker gateway или туннеля сначала проверьте заголовки, затем обновите точный allowlist; не доверяйте всем proxy/host.

Ежедневный запуск / остановка из постоянного корня:

```powershell
Set-Location D:\Projects\Sebastian
docker desktop start
pwsh -File backend/Start-Local.ps1
# Остановить только CMS, сохранив базу:
docker compose -f backend/compose.yaml stop
```

Скрипт проверяет 8080 и отказывается использовать порт постороннего процесса; он не завершает процессы и не выбирает другой порт. Compose-проект `pianissimo-cms` содержит `app`, `web`, `db`. Именованный volume `pianissimo-cms_cms_database` сохраняет рабочую и отдельную тестовую базы после остановки/запуска. Не удаляйте volume и не пересоздавайте существующий `.env`/APP_KEY.

Только для первой установки (на текущей машине уже выполнена):

```powershell
pwsh -File backend/Initialize-Local.ps1
docker compose -f backend/compose.yaml build app
docker compose -f backend/compose.yaml run --rm --no-deps app composer install --no-interaction
pwsh -File backend/Start-Local.ps1
docker compose -f backend/compose.yaml exec app php artisan migrate
docker compose -f backend/compose.yaml exec app php artisan filament:assets
docker compose -f backend/compose.yaml exec app php artisan cms:admin
```

`cms:admin` безопасно запрашивает данные личной учётной записи; `cms:admin --reset` интерактивно меняет существующий пароль. Случайные данные администратора для проверки находятся только в игнорируемом `backend/storage/app/private/local-admin.json`, без вывода пароля в README. Немецкие редакторы охватывают проекты и направления, треки, метаданные медиа, About/CV, настройки сайта, контакты/social, SEO, legal/consent/services и пользователей. Новые администраторы получают Dark; Dark/Light/System сохраняется отдельно для каждого пользователя и не зависит от темы публичного сайта.

```powershell
docker compose -f backend/compose.yaml exec app php artisan cms:import --dry-run
docker compose -f backend/compose.yaml exec app php artisan cms:import
docker compose -f backend/compose.yaml exec app php artisan cms:export --dry-run
docker compose -f backend/compose.yaml exec app php artisan cms:export
docker compose -f backend/compose.yaml exec app php vendor/bin/phpunit --testdox
```

Импорт читает только текущий manifest, сохраняет стабильные ID и сообщает о конфликтах с ручными изменениями/удалениями. Повторный запуск не создаёт дубликаты. Приватный реестр источников, ответы и архивы оригиналов не импортируются. **Entwurf speichern** меняет только базу CMS. Экспорт кандидата записывает `backend/storage/app/private/exports/candidates/<content-hash>/manifest.json` и версионированный `cms-settings.json` для новых настроек. Manifest проверяется существующей общей схемой; новые настройки подготовлены, но пока не используются frontend.

**Lokalen Paketstand sichern** требует подтверждения и создаёт неизменяемый локальный пакет в `exports/published/`; сборка, deploy и замена действующего manifest не выполняются. `next_draft` не означает разрешение публичного использования. Блокировка production сохраняется. Медиа не перекодируются; для Kinnhakenhank разрешён только V2, а Au milieu использует явно подтверждённую финальную MP3; разница длительности 0,179 с зафиксирована в 04A.

### CMS → frontend: контролируемый локальный цикл (03B)

Состояния разделены: **Draft → Candidate → Applied to Frontend**, при этом **Production Released = false**. Сохраните **Entwurf speichern** в CMS, затем выберите **Veröffentlichung → Export-Vorschau erstellen**. Сохранение и экспорт не меняют действующий frontend. Авторизованный экспорт создаёт приватную квитанцию с ID администратора и контрольными суммами пакета, без учётных данных.

Из корня проекта используйте полный hash кандидата, показанный в CMS:

```powershell
node scripts/cms-frontend.mjs review <candidate-hash>
node scripts/cms-frontend.mjs apply <plan-hash>
node scripts/cms-frontend.mjs rollback
```

`review` проверяет существующий контракт, ссылки и хеши web-медиа, показывает полный diff публичного контента и hash плана. `apply` — отдельная явная операция: прочитайте сводку, затем введите полный hash подтверждения. Команда повторно проверит пакет и active baseline, сохранит точную приватную резервную копию, запишет проверенный временный файл рядом с manifest и атомарно заменит действующий файл через rename. Docker CMS сохраняет доступ к frontend только для чтения. Нужен локальный доступ к файловой системе; новый HTTP endpoint, порт или туннель CMS не создаётся.

Сравнение использует стабильные ID, учитывает только описанные различия представления экспорта (приватное происхождение, порядок хранения коллекций, пустые optional-поля, точность duration ассетов в PHP/PDO) и сохраняет локальный реестр источников, неиспользуемые ассеты и точные неизменённые значения. Порядок плейлистов и timeline треков значимы. Неожиданные изменения идентичности, более 20 изменённых путей или diff больше 32 КиБ блокируются без force-обхода; сначала нужно согласовать состояние кандидата. Для явно проверенного пакета контента доступен `review <candidate-hash> --expected <expected-diff.json>`: точные hash кандидата и baseline и все значения до/после должны совпасть с неизменяемым expected-документом; apply повторно проверяет его hash. Это тот же pipeline, без force-обхода. Hash плана также защищает от изменений после просмотра. Повтор уже действующего кандидата ничего не меняет.

`rollback` показывает предыдущий/результирующий hash и отдельно требует подтверждения. Он восстанавливает точную проверенную копию, отказывается перезаписывать неизвестную последующую правку и сохраняет черновики CMS. Временную тестовую правку после этого нужно явно отменить в CMS. Копии, планы, журнал восстановления и история без содержимого находятся в игнорируемом `backend/storage/app/private/frontend-apply/`; аудит содержит ID экспортировавшего администратора, время, хеши и статус. Локальная команда выполняется оператором компьютера, без второго web-входа. После аварийного завершения проверьте PID в `operation.lock` перед удалением только подтверждённо устаревшей блокировки; сохраните журнал revision и backup, затем выполните review/rollback. Блокировку работающей операции не удаляйте.

4321 остаётся единым dev/сервером совместного просмотра: применённый черновик может быть виден через существующий Ports. Локальная браузерная проверка 03B зафиксировала WebSocket `full-reload` Vite: открытые страницы автоматически перезагрузились после apply/rollback, без ручного reload и перезапуска сервера. Сборка и обновление preview 4322 не нужны. Apply Frontend **не является deploy на Netcup** и не разрешает публичное аудио; production заблокирован. `cms-settings.json` проверяется при экспорте, но не активируется: новые SEO/consent/цвета/поведение требуют отдельного подключения. Уже поддерживаемые manifest основные поля включаются в кандидат.

Admin Theme расположен справа вверху в меню пользователя: Dark / Light / System. Выбор хранится отдельно для пользователя в `users.admin_theme`, по умолчанию Dark, и меняет только Filament. **Website-Farben** остаётся отдельной настройкой публичного сайта. Фактические браузерные/автоматические результаты и ограничения — в [отчёте 03B](docs/qa/03B/REPORT_RU.md).

nginx раздаёт только `backend/public`. Порты БД/PHP, `.env`, пароли, vendor, приватные экспорты и оригиналы не открываются. Существующая защита файловой системы dev сохранена. Почта и tracking не активируются. Будущий Netcup потребует отдельного разрешения, проверки PHP/MySQL-хостинга и document root, TLS/доступа, резервных копий, SMTP и управляемой статической сборки/активации. См. [подробную инструкцию CMS](backend/README.md) и [контракт дополнительных настроек](backend/contracts/README.md).
