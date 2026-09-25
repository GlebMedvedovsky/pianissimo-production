<x-filament-panels::page>
    <x-filament::section heading="Entwurf → geprüftes Paket">
        <p>Entwurf speichern → Export-Vorschau → ausdrücklich für Frontend übernehmen. Speichern und Export ändern die Website nicht. Production Released: false — Netcup ist gesperrt.</p>
        <p>Neue SEO-/Consent-Einstellungen werden im Begleitvertrag vorbereitet; ihre Frontend-Anbindung ist noch nicht Teil dieses Schritts.</p>
        @if(isset($result['error']))
            <p role="alert">{{ $result['error'] }}</p>
        @elseif($result)
            @if(isset($result['projects']))<p style="margin-top:1rem"><strong>Geprüft:</strong> {{ $result['projects'] }} Projekte · {{ $result['tracks'] }} Tracks · {{ $result['assets'] }} Medien</p>@endif
            @if(isset($result['candidateHash']))<p>Kandidat: <code title="{{ $result['candidateHash'] }}">{{ substr($result['candidateHash'],0,12) }}</code> · {{ $result['timestamp'] ?? '' }} · {{ $result['validation'] ?? '' }} · Admin {{ $result['exportingAdminId'] ?? '' }}</p>@endif
            @if(($result['kind']??null)==='review')
                <p><strong>{{ $result['changeCount'] }} Änderungen geprüft</strong> · Prüfung <code>{{ substr($result['planHash'],0,12) }}</code></p>
                <ul>@forelse($result['descriptions'] as $description)<li>{{ $description }}</li>@empty<li>Keine Änderungen am aktiven Frontend.</li>@endforelse</ul>
            @endif
            @if(isset($result['hash']))<p>Prüfsumme: <code style="overflow-wrap:anywhere" title="{{ $result['hash'] }}">{{ $result['hash'] }}</code></p>@endif
            @if(isset($result['path']))<p>Das lokale Paket wurde gespeichert. Die Dateien stehen unten zum Download bereit.</p>@endif
        @endif
    </x-filament::section>
    <x-filament::section heading="Für Frontend übernehmen / Rollback">
        <p>Prüfen, bestätigen und zurücksetzen erfolgt hier im CMS. Apply ändert ausschließlich das aktive Manifest, <code>media/cms/</code> und private Revisions-Backups. Kein Build und kein Netcup-Deploy.</p>
        <p>Rollback stellt Manifest und verwaltete Mediendateien bytegenau wieder her; CMS-Entwürfe bleiben erhalten.</p>
        @php
            $historyFile=storage_path('app/private/frontend-apply/history.jsonl');
            $history=is_file($historyFile)?array_slice(file($historyFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES),-8):[];
        @endphp
        <ul>@foreach($history as $line)
            @php($entry=json_decode($line,true))
            @if(is_array($entry))<li>{{ $entry['timestamp'] ?? '' }} · {{ $entry['operation'] ?? '' }} · {{ $entry['status'] ?? '' }} · {{ substr($entry['resultingManifestHash'] ?? '',0,12) }}</li>@endif
        @endforeach</ul>
        <p>Die PowerShell-Werkzeuge bleiben ausschließlich als Entwickler-Notfallweg erhalten.</p>
    </x-filament::section>
    <x-filament::section heading="Letzte lokale Pakete">
        <ul>@foreach(\App\Models\Publication::latest()->limit(10)->get() as $package)
            <li>{{ $package->created_at->format('d.m.Y H:i') }} · {{ $package->status==='published'?'Veröffentlichter lokaler Stand':'Kandidat' }} · {{ substr($package->hash,0,12) }} · {{ $package->summary['projects'] }} Projekte
                <x-filament::link :href="route('admin.export',['publication'=>$package,'file'=>'manifest.json'])">Manifest herunterladen</x-filament::link> · <x-filament::link :href="route('admin.export',['publication'=>$package,'file'=>'cms-settings.json'])">Einstellungen herunterladen</x-filament::link>
            </li>
        @endforeach</ul>
    </x-filament::section>
    <x-filament::section heading="CMS-Verlauf">
        <ul>@forelse(\App\Models\AuditEvent::where('object_type','publication')->latest('id')->limit(10)->get() as $event)
            <li>{{ $event->created_at?->format('d.m.Y H:i') }} · {{ $event->action }} · Admin {{ $event->user_id ?? '—' }} · {{ substr($event->result_hash ?? '',0,12) }}</li>
        @empty<li>Noch keine Apply-/Rollback-Aktion aus dem CMS.</li>@endforelse</ul>
    </x-filament::section>
</x-filament-panels::page>
