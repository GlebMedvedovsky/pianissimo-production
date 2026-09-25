<x-filament-panels::page>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,19rem),1fr));gap:1rem">
        <x-filament::section heading="Website-Status">
            <p><strong>{{ $owner['status']['hasDraftChanges'] ? 'Änderungen noch nicht übernommen' : 'Website und CMS sind auf demselben Stand' }}</strong></p>
            <p>Speichern allein ändert die Website nicht. Änderungen werden erst nach Ihrer Prüfung übernommen.</p>
            <p>Letzte Übernahme: {{ $owner['status']['lastApply'] ? $owner['status']['lastApply']->format('d.m.Y H:i') : 'Noch keine Übernahme protokolliert' }}</p>
            @if($owner['status']['candidatePending'])<p>Eine Vorschau wartet auf Ihre Prüfung.</p>@endif
            <p>Öffentliche Website: noch nicht verbunden.</p>
            <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1rem"><x-filament::button tag="a" :href="$owner['links']['publication']">Änderungen veröffentlichen</x-filament::button><x-filament::link :href="$owner['links']['publication']">Zur Veröffentlichung</x-filament::link></div>
        </x-filament::section>
        <x-filament::section heading="Inhalte">
            <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem">
                <a href="{{ $owner['links']['projectIndex'] }}"><strong>{{ $owner['counts']['projects'] }}</strong><br>Projekte</a>
                <a href="{{ $owner['links']['trackIndex'] }}"><strong>{{ $owner['counts']['tracks'] }}</strong><br>Tracks</a>
                <a href="{{ $owner['links']['mediaIndex'] }}"><strong>{{ $owner['counts']['media'] }}</strong><br>Medien in der Bibliothek<br>@if($owner['counts']['packageMedia']!==null)<strong>{{ $owner['counts']['packageMedia'] }}</strong> Medien im aktuellen Website-Paket<small style="display:block">Dateien, auf die veröffentlichte Inhalte oder Website-Einstellungen verweisen.</small>@else<small style="display:block">Website-Paket derzeit nicht prüfbar.</small>@endif</a>
                <a href="{{ $owner['links']['cvIndex'] }}"><strong>{{ $owner['counts']['cv'] }}</strong><br>CV-Einträge</a>
            </div>
            <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.25rem"><x-filament::link :href="$owner['links']['projectCreate']">Projekt hinzufügen</x-filament::link><x-filament::link :href="$owner['links']['mediaCreate']">Medium hochladen</x-filament::link><x-filament::link :href="$owner['links']['about']">About bearbeiten</x-filament::link><x-filament::link :href="$owner['links']['social']">Social Links bearbeiten</x-filament::link></div>
        </x-filament::section>
        <x-filament::section heading="SEO-Status">
            <ul><li>Indexierung: {{ $owner['seo']['indexing'] }}</li><li>Meta-Beschreibung: {{ $owner['seo']['description'] }}</li><li>Canonical: {{ $owner['seo']['canonical'] }}</li><li>OG-Bild: {{ $owner['seo']['og'] }}</li></ul>
            <x-filament::link :href="$owner['links']['seo']">SEO bearbeiten</x-filament::link>
        </x-filament::section>
        <x-filament::section heading="Analytics">
            <p><strong>{{ $owner['analytics']['provider'] }}</strong></p><p>Status: {{ $owner['analytics']['active'] ? 'Konfiguration aktiv' : 'Inaktiv' }}</p>
            <div style="display:flex;gap:.75rem;flex-wrap:wrap"><x-filament::link :href="$owner['links']['analytics']">Analytics öffnen</x-filament::link><x-filament::link :href="\App\Filament\Pages\Help::getUrl(['search'=>'Analytics'])">Anleitung anzeigen</x-filament::link></div>
        </x-filament::section>
    </div>
    <x-filament::section heading="Hinweise und Aufgaben">
        <div style="display:grid;gap:1rem">
            @forelse($owner['hints'] as $hint)<article><strong>{{ $hint['title'] }}</strong><p>{{ $hint['body'] }}</p><x-filament::link :href="$hint['url']">Jetzt prüfen</x-filament::link></article>@empty<p>Aktuell gibt es keine offenen Hinweise.</p>@endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
