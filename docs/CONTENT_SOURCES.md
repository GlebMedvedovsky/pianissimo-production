# Источники и границы использования

[Текущее состояние после 02D и основная инструкция — README.md](../README.md). Ниже сохранено описание первоначальных источников этапа 01; локальные исследовательские материалы не входят в репозиторий.

Прочитано 17.09.2026. Сначала список проектов на https://pianissimo-production.de/, затем восемь выбранных публичных страниц. HTML-снимки для аудита лежат в `docs/research`; сборка не делает сетевых запросов к старому сайту.

| Запись | Источник | Что использовано |
|---|---|---|
| Abszission | https://pianissimo-production.de/index.php/portfolio/abszission-official-motion-picture-soundtrack/ | Описание, credits, награда фильма, In the Club, Spotify album |
| Au milieu de l’été | https://pianissimo-production.de/index.php/portfolio/au-milieu-de-lete/ | Название, synopsis ко-продукции, composers/director/producers/musicians, Spotify track; явная атрибуция Instagram @sebastian_greim |
| Dough with a hole | https://pianissimo-production.de/index.php/portfolio/dough-with-a-hole/ | Game soundtrack 2025, Rike Ziegler, credits, название композиции, bossa nova / uplifting. Категория Games не выдумана |
| Kinnhakenhank | https://pianissimo-production.de/index.php/portfolio/kinnhakenhank/ | Synopsis сокращён без изменения смысла; Director/Film Music Sebastian Greim, остальные выбранные credits, 2021–2023, YouTube, stills |
| Karussell | https://pianissimo-production.de/index.php/portfolio/karussell/ | 2019, synopsis, soundtrack/camera/postproduction, Jeanine Lang / Joanna Lutz, четыре номинации и награда актрисы. Режиссёрская роль Себастиа́ну не приписана |
| Leaving Earth | https://pianissimo-production.de/index.php/portfolio/leaving-earth/ | Song, production/camera/collaboration, synopsis, Spotify track, YouTube. Год не придуман |
| Lilly’s Lullaby | https://pianissimo-production.de/index.php/portfolio/lillys-lullaby/ | Composition/orchestra/conducting, September 2025, Spotify track. Приблизительные «4,5 минуты» не выданы за точную duration файла |
| Orchestra photo | https://pianissimo-production.de/index.php/portfolio/filmorchester-babelsberg/ | Ранее временный фон composerMenu; заменён пользовательским split-menu, не отдельная карточка |
| Biography / studio | https://pianissimo-production.de/ | Краткая редакция английской биографии; прежний studio hero заменён пользовательским фоном |

Точное соответствие каждого импортированного изображения исходному URL, id и размеру: `content/imported-assets.json`. Полные исходные загрузки: `content/imported`; выбранные WebP и 480px-производные: `frontend/public/media`. Скачано 11 небольших/средних изображений; видео и музыка не скачивались. Все media.state пока temporary — требуется утверждение финального использования.

Первоначальные пользовательские материалы: все четыре PNG осмотрены. `Sebastian.png` → `sebastian-about.webp` / `sebastian-about-480.webp` только для временного About. `Sebastian3.png` и `Sebastian4.png` не включены в public. `images/reference/pianissimo-logo-reference-from-pdf.png` использован как геометрический референс при ручной реконструкции двух SVG-кривых. Содержимое оригиналов не менялось; исходные контрольные SHA-256: `docs/research/original-hashes.json`. Позднейшее разрешённое перемещение logo-reference отражено в `docs/research/background-asset-moves.json`.

Обновление фонов по указанию владельца: `hero-piano-temp1.png` переименован в `images/source/home-hero-poster.png`, `composer-menu-temp.jpg` — в `images/source/split-menu-background.jpg`. Эти два чистых изображения используются через производные `home-hero-poster.webp` и `split-menu-background.webp`. Точные размеры/хеши: `content/home-background-assets.json`. PNG не маскируется расширением JPG и не перекодируется в исходной папке. Затемнение делается только CSS-overlay.

`hero-piano-temp.png` → `images/reference/homepage-layout-reference.png`; `composer-menu-temp_logo.png` → `images/reference/split-menu-logo-reference.png`. Оба содержат впечатанный интерфейс, поэтому служат только визуальными референсами и не копируются в public. `Rollen_am_Klavier.png` просмотрен, не подключён. Все пять разрешённых переименований проверены SHA-256 до/после; содержимое файлов совпало. Логотип, подписи и ссылки сайта остаются отдельными SVG/HTML.

CV перенесён с PDF S10: 11 пунктов, исходные даты и смысл. Samples unterm Sofa и преподаватель — точные ссылки PDF. `tba` сохранено с needs-confirmation. About-портрет из чужого референса PDF не использован.

Шрифты: npm `@fontsource/cormorant-garamond@5.3.0` и `@fontsource/inter@5.3.0`; OFL лицензии сохранены в `docs/licenses`. Браузер получает локальные woff/woff2, без Google Fonts/CDN. Savoye LET не установлен; Cormorant italic — provisional fallback.

Внешние визуальные референсы открыты в headless Chrome через Playwright: Pianissimo, Hauschka (#projects-volker-bertelmann), Brenner, Vincent Ruhl. Первые снимки (`reference-*.png`) показывают загрузочные/вступительные состояния, у Hauschka — newsletter overlay. Это подтверждает доступность страниц и некоторых состояний, **но не подтверждает точного просмотра всей анимационной последовательности**. Поведение реализовано по PDF; чужие шаблоны/произведения/портреты/биография не копировались. Неподтверждённые движения не объявлены воспроизведёнными один в один.

Технические первоисточники: https://docs.astro.build/en/install-and-setup/, https://docs.astro.build/en/guides/view-transitions/, https://gsap.com/docs/v3/Plugins/ScrollTrigger/. Проверены требования Astro >= Node 22.12.0; установленная 7.3.3 совместима с имеющимся Node 26.5.0. Реальные типы/lifecycle дополнительно сверены с установленным пакетом.
