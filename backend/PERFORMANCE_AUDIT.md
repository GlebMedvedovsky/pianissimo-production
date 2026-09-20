# Backend Performance Audit

Дата: 20.09.2026, Europe/Berlin. Ветка: `feature/backend-performance-audit`.

**Вывод:** главный подтверждённый инфраструктурный дефект — высокая стоимость файловых обращений PHP через Windows bind mount. Длительная инициализация Laravel/Filament и рендер выполняются даже при отсутствии SQL. Дополнительную задержку при входе через `/admin` даёт отдельный redirect. Гипотезы о включённом Xdebug, отсутствии Opcache, медленной MariaDB и N+1 на проверенных списках не подтвердились.

## 1. Методика и границы

- Конфигурация, зависимости, данные CMS и production не менялись; контейнеры не перезапускались, кэши не очищались и команды оптимизации не выполнялись.
- Реальный HTTP: последовательные `curl` к `127.0.0.1:8080`, TTFB и полное время ответа. Первый замер сделан на уже работающих около 17 часов контейнерах: это **не контролируемый cold start**.
- Внутренние страницы: по три отдельных FastCGI-запроса к существующему PHP-FPM. Временный probe запускает HTTP kernel, учитывает bootstrap, render, SQL через `DB::listen` и CPU через разность `getrusage`.
- Для закрытых страниц существующий пользователь установлен только в памяти процесса; сессия `array`, SQL-записи блокируются диагностическим обработчиком. Пароли не выводились и не менялись. Это server-side baseline, **не браузерный authenticated TTFB**: Nginx, cookie authentication и файловая сессия в нём не измеряются.
- Livewire: три POST через тот же kernel к зарегистрированному update route, с настоящим snapshot формы Site, CSRF и `$refresh`, без updates и сохранения.
- Probe выполнялся из `/tmp`, вне web root; временные скрипты, snapshot и промежуточные результаты удалены. Обычные runtime-логи и прогретые Blade/Opcache-кэши не откатывались. Ошибки настройки первоначальной версии probe исключены из baseline.

## 2. Baseline timings

Реальный HTTP, секунды; время скачивания HTML практически совпадает с TTFB:

| URL | HTTP | Последовательные TTFB | Медиана |
|---|---:|---|---:|
| `/admin/login` | 200 | 9.713 / 9.628 / 10.049 / 4.312 | 9.671 |
| `/admin`, без авторизации, без follow redirects | 302 | 3.848 / 3.371 / 2.899 / 7.189 | 3.610 |
| `/up` | 200 | 6.501 / 2.716 / 2.825 | 2.825 |
| `/avatar.svg` | 200 | 0.0105 / 0.0049 / 0.0126 | 0.0105 |

TCP connect: примерно 1 мс. Для `/up` и статического файла явно отключён клиентский proxy через `--noproxy '*'`.

Изолированные запросы PHP-FPM, полное серверное время в секундах; SQL включает диагностическую загрузку пользователя:

| Страница | HTTP | Три замера | Медиана | SQL: количество / суммарные мс |
|---|---:|---|---:|---|
| Login | 200 | 7.464 / 7.707 / 4.018 | 7.464 | 0 / 0 |
| `/admin`, авторизованный redirect | 302 | 3.802 / 3.054 / 3.021 | 3.054 | 1 / 14.7–31.1 |
| Dashboard | 200 | 4.141 / 4.568 / 4.191 | 4.191 | 4 / 15.9–19.9 |
| Projects | 200 | 5.625 / 5.327 / 4.740 | 5.327 | 3 / 17.1–42.0 |
| Tracks | 200 | 6.217 / 5.003 / 6.158 | 6.158 | 3 / 17.5–31.8 |
| Media assets | 200 | 5.201 / 5.064 / 4.624 | 5.064 | 3 / 20.1–23.0 |
| Site | 200 | 5.745 / 4.531 / 4.566 | 4.566 | 2 / 15.7–17.2 |
| Home | 200 | 8.578 / 4.532 / 4.482 | 4.532 | 5 / 17.3–31.2 |
| Livewire Site `$refresh` | 200 | 3.788 / 4.383 / 3.789 | 3.789 | 1 / 14.8–16.6 |

Заявленные 12–13 с именно холодного первого входа отдельно не воспроизводились: сброс кэшей и restart изменили бы исходное состояние. Браузерный waterfall и время отрисовки не измерены — подключённая браузерная сессия недоступна.

## 3. Bottlenecks по фактическому влиянию

### 1. Windows bind mount + многочисленные файловые обращения — главный кандидат на ускорение

- **A. Измерение:** два прогона по 500 операций над одним небольшим PHP-файлом: `stat` на bind mount 692–707 мс, в `/tmp` 0.390–0.395 мс; чтение 1868–2037 мс против 2.651–2.788 мс. Копировался только один диагностический образец, не рабочая копия проекта.
- **B. Причина:** `backend/compose.yaml` монтирует весь backend с `D:\Projects\Sebastian\backend` в `/var/www/html`, включая vendor, bootstrap/cache и storage/framework. PHP/Laravel продолжают обращаться к файловой системе при работающем Opcache.
- **C. Доказательство:** runtime mount подтверждён `docker inspect`; запросы включают примерно 1180–1340 PHP-файлов. В основной серии bootstrap занимает 1.98–5.22 с, но CPU всего запроса — только 0.21–0.59 с при wall time 3.02–8.58 с. Login не использует SQL; `/up` тоже медленный, статический Nginx быстрый. Микротест подтверждает стоимость файлового пути, однако точная доля всех секунд и итоговый выигрыш без A/B переноса не установлены. [Docker документирует это ограничение Windows filesystem mounts](https://docs.docker.com/desktop/features/wsl/best-practices/).
- **D. Предложение:** следующим отдельным этапом проверить Linux-backed named volume для `vendor` и отдельно для выбранных пересоздаваемых runtime-кэшей. Единственный исходный проект оставить в `D:\Projects\Sebastian`; `storage/app`, CMS-данные и экспортные пакеты не переносить вместе с кэшами.
- **E. Риск:** средний — синхронизация Composer-зависимостей, права www-data, маскирование содержимого mount и корректная инвалидация кэшей. Нужны обратимый compose override и сравнение тех же URL. Численное ускорение пока не обещается.

### 2. Вход через `/admin` оплачивает лишний полный bootstrap

- **A. Измерение:** авторизованный `/admin` возвращает 302 за 3.02–3.80 с; Location отдельно подтверждён: `/admin/dashboard`. Dashboard затем требует ещё 4.14–4.57 с. Ориентир суммарного пути по медианам — около 7.25 с; это сумма отдельных замеров, не измеренный redirect chain.
- **B. Причина:** корневой URL панели перенаправляет на фактическую страницу Dashboard.
- **C. Доказательство:** HTTP status и Location из kernel, зарегистрированные маршруты `admin` и `admin/dashboard`. Неавторизованный `/admin` также тратит несколько секунд до redirect на login.
- **D. Предложение:** для повседневного входа после авторизации использовать прямой bookmark `/admin/dashboard`. Если нужен именно `/admin`, маршрутизацию рассмотреть отдельно после ускорения bootstrap.
- **E. Риск:** bookmark — минимальный, без изменения кода; изменение маршрутов — средний из-за возможного конфликта маршрутов Filament и login redirect.

### 3. Повторная инициализация и проверки файлов при отсутствии application caches

- **A. Измерение:** в контрольных probe загрузка environment + configuration занимает около 0.20–0.54 с; RegisterProviders 0.45–1.26 с, BootProviders 1.28–3.26 с. Непосредственный обход двух каталогов Filament в примерах — 57–72 мс. На Tracks только exists/mtime скомпилированных views — около 129 мс плюс проверки исходных шаблонов.
- **B. Причина:** config/routes/events/Filament component caches отсутствуют; discovery выполняется на запросах. Blade-кэш **существует** и используется, но source/compiled timestamps проверяются. Этот пункт пересекается с файловым overhead пункта 1: времена нельзя складывать как независимые потери.
- **C. Доказательство:** runtime cache flags false; bootstrap/cache содержит packages.php и services.php; Filament `hasCachedComponents=false`; зарегистрированы реальные вызовы обхода каталогов и lastModified.
- **D. Предложение:** отдельно A/B-проверить config/route/component caches, по одному, с явным процессом очистки при разработке. Весь bootstrap нельзя считать устранимым кэшированием. [Filament предупреждает, что component cache скрывает новые компоненты до rebuild/clear](https://filamentphp.com/docs/5.x/deployment); [Laravel описывает отдельные config/route/view caches](https://laravel.com/framework/docs/deployment).
- **E. Риск:** низкий–средний — устаревшие `.env`/маршруты/новые страницы. Не включать полный `optimize` без проверки developer workflow.

Низкий приоритет: Blade Icons cache отсутствует, manifest действительно строится, но повторное штатное сканирование 556 иконок заняло лишь 15.1–15.3 мс. Три media-select на Home выполняют три выборки суммарно примерно за 2.2–6.8 мс. Их оптимизация не объясняет и не устранит многосекундную задержку.

## 4. Что проверено и что остаётся гипотезой

| Проверка | Факт / ограничение |
|---|---|
| Debug / dev extensions | `APP_DEBUG=false`, env local. Xdebug и профилирующие расширения отсутствуют в реальном FPM. |
| Opcache | Включён в FPM, hit rate около 98%, cache_full=false, свободно около 85–86 MiB из 128 MiB, OOM/hash restarts=0. CLI enable_cli=Off не описывает web runtime. |
| Opcache timestamps | validate_timestamps=On, revalidate_freq=2, realpath_cache_size=4 MiB, ttl=120. Отдельный вклад проверок и истечения realpath cache не изолирован. Отключать timestamp validation локально без процедуры обновления кода не предлагается. [Семантика параметров PHP](https://www.php.net/manual/en/opcache.configuration.php). |
| MariaDB latency | Повторный `SELECT 1` ~0.20–0.33 мс; DNS `db` 0.18–0.68 мс; новый PDO connection после загрузки классов 0.51–0.87 мс. Первый Laravel connect 12.8–32.4 мс включает клиентскую инициализацию. Многосекундной SQL/network задержки не найдено. |
| MariaDB slow queries | slow_query_log=OFF, performance_schema=OFF, long_query_time=10 с, Slow_queries=0. Это не доказывает отсутствия всех исторических медленных SQL; журнал не включался. |
| MariaDB storage / объём | Данные на named volume, не Windows bind mount. На момент проверки: 17 projects, 20 tracks, 102 media assets, 14 settings. Buffer pool 128 MiB; оснований увеличивать его по этим замерам нет. |
| N+1 | На первых страницах списков Projects/Tracks/Media по 3 SQL: user, count, выборка до 10 записей. N+1 не обнаружен. `canDelete` в Tracks/Media содержит потенциальные построчные проверки, но они не выполнялись в измеренных GET; это гипотеза для других действий, не доказанный bottleneck. |
| Settings / Manifest | Site: одна загрузка Setting; Home: одна Setting + три media-select. Site refresh не перечитывает Setting. Автоматического экспорта или пересборки content manifest на проверенных GET нет; действия публикации не запускались. |
| Livewire / polling | В проверенном HTML 0 wire:poll/wire:init; database notifications отключены. В десятиминутном окне access log только восемь исходных audit GET, фоновых POST не было. Это не заменяет браузерный waterfall при действиях пользователя. |
| Theme | Backend theme listener делает POST только на theme-changed; штатная инициализация темы не dispatch-ит это событие. Автоматический цикл запросов по просмотренному коду не найден. |
| CPU / память | Docker: 16 CPU, 7.687 GiB. App во время выборочных замеров ~6.4–7.8% CPU, 79–86 MiB; DB обычно ~0.01%, единичный sample 3.94%, ~170 MiB. Высокая постоянная нагрузка DB не воспроизведена. |
| Ограничения / FPM | CPU quota отсутствует, nr_throttled=0, OOM=0, pressure avg=0 в снимке. pm.dynamic, max_children=5, start_servers=2; предупреждений max_children/busy в проверенном окне нет. Конкурентный нагрузочный тест не выполнялся. |
| Proxy / network | Nginx передаёт запросы прямо app:9000, LocalCmsAccess делает локальные проверки host/proxy. Минимальный FPM probe — 1.7 мс; Nginx static — 5–13 мс. Сетевой/proxy bottleneck не подтверждён. |
| Host filesystem / Defender | Большой файловый overhead доказан; доли Docker file sharing, host I/O, antivirus и колебаний host load отдельно не установлены. Настройки Windows/security не менялись. |

## 5. Следующий отдельный этап

1. Наибольший ожидаемый эффект: обратимый A/B vendor/runtime-cache volumes на Linux filesystem, с сохранением текущего project root и данных. Сначала сравнить login, `/up`, Dashboard, Tracks и Livewire refresh.
2. Без изменения кода можно использовать прямой URL `/admin/dashboard`, чтобы после авторизации избежать лишнего redirect.
3. Проверить по одному application caches, зафиксировать правила rebuild/clear и повторить ту же выборку. Не считать `icons:cache` главным решением.
4. Только если останется существенная задержка — browser waterfall с реальной сессией и отдельное измерение file sessions / FPM queue. MariaDB tuning, рефакторинг Settings/Manifest, увеличение Opcache и постоянное отключение timestamp checks текущими данными не обоснованы.

## 6. Просмотренные и изменённые файлы; проверки

Просмотрены точно или целевыми фрагментами:

- `AGENTS.md`; `backend/compose.yaml`, `Dockerfile`, `composer.json`, `docker/nginx.conf`.
- `backend/bootstrap/app.php`, `bootstrap/providers.php`; состав `bootstrap/cache`; `config/cms.php`, `config/dev-access.php`; `routes/web.php`.
- `app/Providers/AppServiceProvider.php`, `app/Providers/Filament/AdminPanelProvider.php`, `app/Http/Middleware/LocalCmsAccess.php`.
- `app/Filament/SettingsPage.php`, `SettingsForm.php`, `Fields.php`, `Pages/Dashboard.php`, `Pages/Publication.php`, `Resources/{Project,Track,MediaAsset}Resource.php`; `app/Models/{Setting,User}.php`.
- `resources/views/cms/{dashboard,theme}.blade.php`; ограниченный поиск polling/SQL/Manifest в `app/Filament`, `app/Models`, `app/Providers`, `resources/views`.
- Только связанные dependency-фрагменты: Composer installed/autoload; Laravel Filesystem, View Compiler/BladeCompiler/ViewServiceProvider и view config; Filament HasComponents/HasNotifications/DisableBladeIconComponents, ListRecords/action code и dark-mode.js; Livewire HandleRequests и config; Blade Icons Factory/IconsManifest/ServiceProvider.
- Runtime PHP/FPM/Docker/MariaDB и ограниченные окна журналов; из Laravel log только сообщения ошибок диагностического probe. Frontend, материалы, content manifest и исторические QA не читались.

Версии: PHP 8.4.25, Laravel 13.32.0, Filament 5.8.2, Livewire 4.4.5, MariaDB image 11.8.9, Docker Engine 29.7.2.

**Единственный оставленный файл:** `backend/PERFORMANCE_AUDIT.md`. Временные `.performance-audit-*` и `/tmp/sebastian-performance-audit-*` удалены. Исходный код и runtime configuration не изменены.

**Фактически выполнены:** HTTP timings, 24 основных FPM GET, контрольные запросы, три успешных Livewire refresh, SQL listener, два файловых микротеста, DNS/PDO/SELECT probes, три icon scans, Docker stats/cgroup/FPM/Opcache проверки, PHP lint временного probe, итоговый `git diff --check`. PHPUnit и исторические QA не запускались: функциональных изменений нет.

**Нерешённое:** точный выигрыш от volumes/caches, browser waterfall, настоящий cold start и причина ранее наблюдавшейся высокой CPU MariaDB требуют отдельного этапа. Production-изменений нет.
