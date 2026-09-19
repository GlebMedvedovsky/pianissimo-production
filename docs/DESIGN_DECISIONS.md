# Решения этапа 01

[Текущее состояние после 02D и основная инструкция — README.md](../README.md). Ниже сохранены исторические решения этапа 01.

Основание: задание от 17.09.2026 → текст PDF → макеты PDF → внешние эффекты. Прочитаны текст/ссылки и просмотрены все 17 страниц; рендеры `research/pdf-01.png` … `pdf-17.png`, текст `research/pdf-text.txt`. Имена оригинальных файлов сохранены, включая Unicode-нормализацию ä.

| Область | Реализация | Основание / статус |
|---|---|---|
| Главная | Одноэкранный poster, малый знак и wordmark; далее SVG увеличивается по native scroll | PDF 11–12 |
| Большой знак | Два отдельно нарисованных cubic SVG-path по PNG, левая тёмная / правая кремовая часть | PNG + PDF 12; реконструкция, не авторский вектор; нужно визуальное утверждение |
| Прокрутка | CSS sticky ограничен сценой 225svh, ScrollTrigger меняет transform/opacity, полностью обратимо | PDF 12–14; без virtual scroll / wheel capture |
| Фоны | Новый пользовательский hero и общий split-menu ЗА знаком; CSS-overlay без изменения картинки. На портретном экране две CSS-области сохраняют левую/правую сцены одного фона. Раздельные video slots сохраняются | Уточнение владельца после этапа 01; reference-изображения не встроены в сайт |
| Направления | `/film-music/` тёмный; `/directing/` кремовый; обычные реальные ссылки | PDF 13–14 |
| Переходы | Film Music приходит слева, Directing справа; обратный ход зависит от исходного раздела; Astro ClientRouter/View Transitions | PDF 12; короткий сдвиг и opacity 420ms; fallback — обычная навигация |
| Сетки | Music: 3/2/1 колонки, квадратные cover. Directing: максимум 2, на телефоне 1, cover 16:9 | PDF 3, 7; плотность Music provisional |
| Фильтры | Music — все 5 из PDF. Directing — Director / Assistant Director, default Director | PDF 3, 7; default Directing provisional/config |
| Двойные роли | Kinnhakenhank: одна запись, два membership, отдельные cover/order | Явные Director и Film Music на публичной странице; владелец подтверждает для релиза |
| Окно проекта | Native dialog с фокусом, Escape, inert фоном; Back закрывает, Forward открывает; прямой `/projects/slug/` — полноценный HTML | PDF 4, 8; одинаковый компонент ProjectDetail |
| About | Один `/#about`, CV из PDF 10; тема из section/context history. Прямой вход dark | PDF 9–10 + provisional-решение владельца |
| Каталог | Одно значение genre + одно emotion, пересечение, reset; те же track IDs, что в soundtrack | PDF 5–6; выбор одиночных фильтров — решение реализации |
| Аудио | Один persistent audio и coordinator, стабильная очередь, next/previous/shuffle, seek/volume, platforms/details/X | PDF 6, 11; без WaveSurfer: измеренные SVG peaks + общая seek-шкала достаточны |
| Пауза | About (включая прокрутку) и Directing ставят на паузу с позицией; возврат не запускает звук | Provisional, централизовано в config |
| Адаптивность | Мобильные подписи всегда видны, боковая вкладка становится широкой ссылкой, плеер резервирует место | Проверяется 1440, 768, 390, 320 и landscape 844 |
| Reduced motion | Нет долгой sticky-сцены и анимированных переходов, обе ссылки сразу доступны | Пользовательская доступность; native scroll сохранён |
| Шрифты | Локальные Cormorant Garamond + Inter через Fontsource/OFL; italic Cormorant как временный акцент | PDF 15; не Savoye LET |
| Цвет | #141414, #F3F0EA, #A66A3F и semantic tokens | PDF 16 |
| Contact / consent | Локальная валидация без доставки; внешние iframe только после согласия и клика, отзыв выгружает | PDF 2, 17 |

`2023–2027 / Diploma in Film Music` сохранено как период/формулировка, диплом не объявлен полученным. `2026 / World Premiere of Cello at tba` сохранено и помечено needs-confirmation. Оркестры, награды и люди из референсов в портфолио не переносились.

Загрузка показывается малым знаком только во время навигационного ожидания, без искусственной паузы и полноэкранной блокировки. Исходные фотографии, PDF, служебные файлы не копируются в dist. Media slots отделены от компонентов. Backend, login, CMS, deployment, Netcup и реальные отправки не входят в этап.
