<?php
namespace App\Services;

use Illuminate\Support\HtmlString;

class IconRailPreview
{
    private const PATHS=[
        'mail'=>'M3 5h18v14H3Zm0 0 9 8 9-8','instagram'=>'M7 3h10a4 4 0 0 1 4 4v10a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V7a4 4 0 0 1 4-4Zm9 9a4 4 0 1 1-8 0 4 4 0 0 1 8 0Zm1-5h.01',
        'imdb'=>'M3 7h18v10H3Zm3 3v4m3-4v4m0-4 2 4 2-4m0 4v-4m3 4v-4h1.5a2 2 0 0 1 0 4H16Z','spotify'=>'M4 12a8 8 0 1 1 16 0 8 8 0 0 1-16 0Zm3.5-2.7c3.4-1 7.7-.7 10.5.9m-9.8 2.3c2.8-.8 6.4-.5 8.8.8m-8.1 2.2c2.2-.6 4.9-.4 6.8.6',
        'music-hub'=>'M4 16V8l4-3 4 3 4-3 4 3v8l-4 3-4-3-4 3-4-3Zm4-5v5m8-5v5m-4-8v8','crew-united'=>'M15 8.5a4.5 4.5 0 1 0 0 7M9 8v5a3 3 0 0 0 6 0V8m-3-5v3m0 12v3M3 12h3m12 0h3',
        'tidal'=>'m12 3 3 3-3 3-3-3 3-3ZM6 9l3 3-3 3-3-3 3-3Zm12 0 3 3-3 3-3-3 3-3Zm-6 6 3 3-3 3-3-3 3-3Z','apple-music'=>'M7 4h10a3 3 0 0 1 3 3v10a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3Zm3 12V9.5l6-1V14m-6-1.5 6-1M10 16c0 1.8-3.5 2.3-3.5.3S10 14 10 16Zm6-2c0 1.8-3.5 2.3-3.5.3S16 12 16 14Z',
        'samples'=>'M5 12V7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v5M3 10v8h18v-8M3 14h18M5 18v3m14-3v3','deezer'=>'M4 16h3v3H4Zm4.5-4h3v7h-3Zm4.5-3h3v10h-3Zm4.5-4h3v14h-3Z',
        'youtube'=>'M5.5 6.5h13a3 3 0 0 1 3 3v5a3 3 0 0 1-3 3h-13a3 3 0 0 1-3-3v-5a3 3 0 0 1 3-3Zm4.5 3 5 2.5-5 2.5Z','vimeo'=>'M4 8c1.5-1.7 3.6-2.7 4.7-1.8 1 .8.5 3.4 1.5 7.2.8 2.8 1.4 3.3 2.1 3.3 1.3 0 3.5-2.8 4.2-4.2.4-.8-.1-1.2-.9-1-1 .2-1.9.8-2.5 1.6.8-3.1 3.5-4.6 5.9-3.2 2.8 1.6 1.3 6.1-2.6 10.7-2.8 3.4-5.9 5.7-8.2 5.7-2.8 0-4.2-3.7-5.4-8.1-.8-2.9-1.2-3.4-2.2-3.4-.5 0-1.2.4-1.8.8L4 8Z',
        'soundcloud'=>'M3 15v2m3-5v5m3-8v8m3-10v10m2.5 0H20a2.5 2.5 0 0 0 .3-5 4.5 4.5 0 0 0-8.3-1.8','letterboxd'=>'M8 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0Zm8 0a4 4 0 1 1-8 0 4 4 0 0 1 8 0Zm8 0a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
        'website'=>'M5 19 19 5M8 5h11v11','other'=>'M5 19 19 5M8 5h11v11',
    ];

    public static function html(array $rail): HtmlString
    {
        $rail=IconRail::normalize($rail);$icons='';
        foreach ($rail['items'] as $item) {
            $disabled=!$item['visible'];$path=self::PATHS[$item['type']]??self::PATHS['other'];$label=e($item['label']);
            $icons.='<span class="rail-preview__item'.($disabled?' is-hidden':'').'" title="'.$label.'"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="'.e($path).'"/></svg><small>'.$label.'</small></span>';
        }
        return new HtmlString('<div class="rail-preview rail-preview--'.e($rail['style']).'" aria-label="Vorschau der Symbolleiste">'.$icons.'</div><style>
        .rail-preview{display:flex;align-items:center;gap:.55rem;overflow-x:auto;padding:1rem;border-radius:.75rem;background:#141414;color:#f3f0ea}.rail-preview__item{display:grid;justify-items:center;gap:.25rem;min-width:3.25rem}.rail-preview__item svg{width:2.15rem;height:2.15rem;padding:.42rem;fill:none;stroke:currentColor;stroke-width:1.5;stroke-linecap:round;stroke-linejoin:round}.rail-preview__item small{max-width:5rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.62rem}.rail-preview__item.is-hidden{opacity:.22;filter:grayscale(1)}.rail-preview--rounded .rail-preview__item svg{border-radius:999px;background:#2b2b2b;stroke-width:1.7}.rail-preview--filled .rail-preview__item svg{border-radius:.35rem;background:#f3f0ea;color:#141414;stroke-width:2}.rail-preview--accent .rail-preview__item svg{border:1px solid #bd895c;border-radius:999px;background:#2b190f;color:#d2ac84;stroke-width:1.8}
        </style>');
    }
}
