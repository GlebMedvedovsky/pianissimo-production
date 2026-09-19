<x-filament-panels::page>
    <x-filament::section heading="Entwurf → geprüftes Paket">
        <p>Entwurf speichern → Export-Vorschau → ausdrücklich für Frontend übernehmen. Speichern und Export ändern die Website nicht. Production Released: false — Netcup ist gesperrt.</p>
        <p>Neue SEO-/Consent-Einstellungen werden im Begleitvertrag vorbereitet; ihre Frontend-Anbindung ist noch nicht Teil dieses Schritts.</p>
        @if(isset($result['error']))
            <p role="alert">{{ $result['error'] }}</p>
        @elseif($result)
            <p style="margin-top:1rem"><strong>Geprüft:</strong> {{ $result['projects'] }} Projekte · {{ $result['tracks'] }} Tracks · {{ $result['assets'] }} Medien</p>
            <p>Prüfsumme: <code style="overflow-wrap:anywhere" title="{{ $result['hash'] }}">{{ $result['hash'] }}</code></p>
            @if(isset($result['path']))<p>Das lokale Paket wurde gespeichert. Die Dateien stehen unten zum Download bereit.</p>@endif
        @endif
    </x-filament::section>
    <x-filament::section heading="Für Frontend übernehmen / Rollback">
        <p>Separate lokale Aktion in PowerShell im Projektverzeichnis. Erst Diff prüfen, dann den angezeigten Plan-Hash bestätigen. Der gemeinsame Dev auf 4321 kann über Ports sichtbar sein. Kein Build und kein Netcup-Deploy.</p>
        <pre style="white-space:pre-wrap;overflow-wrap:anywhere">node scripts/cms-frontend.mjs review &lt;candidate-hash&gt;
node scripts/cms-frontend.mjs apply &lt;plan-hash&gt;
node scripts/cms-frontend.mjs rollback</pre>
        <p>Rollback stellt das vorherige Manifest wieder her; CMS-Entwürfe bleiben erhalten. Begleitende SEO-/Consent-Daten werden noch nicht aktiviert.</p>
        @php
            $historyFile=storage_path('app/private/frontend-apply/history.jsonl');
            $history=is_file($historyFile)?array_slice(file($historyFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES),-8):[];
        @endphp
        <ul>@foreach($history as $line)
            @php($entry=json_decode($line,true))
            @if(is_array($entry))<li>{{ $entry['timestamp'] ?? '' }} · {{ $entry['operation'] ?? '' }} · {{ $entry['status'] ?? '' }} · {{ substr($entry['resultingManifestHash'] ?? '',0,12) }}</li>@endif
        @endforeach</ul>
        <p>Applied to Frontend wird nur durch die separate lokale Aktion erreicht; ein späterer Rollback hebt diesen Stand auf.</p>
    </x-filament::section>
    <x-filament::section heading="Letzte lokale Pakete">
        <ul>@foreach(\App\Models\Publication::latest()->limit(10)->get() as $package)
            <li>{{ $package->created_at->format('d.m.Y H:i') }} · {{ $package->status==='published'?'Veröffentlichter lokaler Stand':'Kandidat' }} · {{ substr($package->hash,0,12) }} · {{ $package->summary['projects'] }} Projekte
                <x-filament::link :href="route('admin.export',['publication'=>$package,'file'=>'manifest.json'])">Manifest herunterladen</x-filament::link> · <x-filament::link :href="route('admin.export',['publication'=>$package,'file'=>'cms-settings.json'])">Einstellungen herunterladen</x-filament::link>
            </li>
        @endforeach</ul>
    </x-filament::section>
</x-filament-panels::page>
