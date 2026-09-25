<x-filament-panels::page>
    <x-filament::section heading="Wie können wir helfen?">
        <label for="handbook-search" style="display:block;font-weight:600;margin-bottom:.5rem">Im Handbuch suchen</label>
        <input id="handbook-search" type="search" wire:model.live.debounce.250ms="search" placeholder="Im Handbuch suchen..." style="width:100%;border:1px solid currentColor;border-radius:.5rem;padding:.75rem" />
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1rem"><button type="button" wire:click="$set('category','Alle')">Alle</button>@foreach($this->categories() as $item)<button type="button" wire:click="$set('category',@js($item))">{{ $item }}</button>@endforeach</div>
    </x-filament::section>
    <div style="display:grid;gap:1rem">
        @forelse($this->articles() as $article)
            <x-filament::section :heading="$article['title']" :id="$article['id']">
                <p><strong>Wofür ist das?</strong><br>{{ $article['purpose'] }}</p>
                <p><strong>So geht es:</strong></p><ol style="list-style:decimal;padding-left:1.25rem">@foreach($article['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>
                <p><strong>Was sollte ich beachten?</strong><br>{{ $article['tips'] }}</p>
                @if($article['example'])<p><strong>Beispiel:</strong><br><code style="white-space:normal">{{ $article['example'] }}</code></p>@endif
                <x-filament::button tag="a" :href="$article['url']">{{ $article['label'] }}</x-filament::button>
            </x-filament::section>
        @empty
            <x-filament::section heading="Keine passenden Artikel"><p>Versuchen Sie einen anderen Suchbegriff oder wählen Sie „Alle“.</p></x-filament::section>
        @endforelse
    </div>
</x-filament-panels::page>
