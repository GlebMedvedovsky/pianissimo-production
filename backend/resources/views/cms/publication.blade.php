<x-filament-panels::page>
    <x-filament::section heading="Änderungen veröffentlichen">
        <p><strong>Speichern allein ändert die Website nicht.</strong> Prüfen Sie zuerst das Paket, erstellen Sie danach eine Vorschau und kontrollieren Sie die Änderungsliste. Erst „Für Website übernehmen“ ändert den sichtbaren lokalen Website-Stand.</p>
        <p>„Nicht veröffentlichte Änderungen verwerfen“ setzt nur den CMS-Entwurf auf den zuletzt übernommenen Stand zurück. „Letzte Änderung zurücksetzen“ bleibt ausschließlich für einen bereits übernommenen Website-Stand.</p>
        <ol style="list-style:decimal;padding-left:1.25rem"><li>Entwurf speichern</li><li>Paket prüfen</li><li>Vorschau erstellen</li><li>Änderungen prüfen</li><li>Für Website übernehmen</li></ol>
        <p>Eine öffentliche Production-Website ist noch nicht verbunden.</p>
        @php($candidateState=$this->candidateState())
        @if($candidateState['kind']==='stale')<p role="status"><strong>Diese Vorschau ist nicht mehr aktuell.</strong> Erstellen Sie nach Ihren Änderungen eine neue Vorschau. Die alte Vorschau kann nicht übernommen werden.</p>
        @elseif($candidateState['kind']==='current')<p role="status"><strong>Eine aktuelle Vorschau ist vorhanden.</strong></p>
        @else<p role="status"><strong>Noch keine aktuelle Vorschau vorhanden.</strong> Erstellen Sie zuerst eine Vorschau.</p>@endif
        @if(isset($result['error']))
            <p role="alert">{{ $result['error'] }}</p>
        @elseif($result)
            @if(isset($result['projects']))<p style="margin-top:1rem"><strong>Geprüft:</strong> {{ $result['projects'] }} Projekte · {{ $result['tracks'] }} Tracks · {{ $result['assets'] }} Medien</p>@endif
            @if(isset($result['candidateHash']))<p><strong>Vorschau bereit.</strong> Erstellt: {{ $result['timestamp'] ?? '' }} · Prüfung: {{ $result['validation'] ?? '' }}</p>@endif
            @if(($result['kind']??null)==='review')
                <p><strong>{{ $result['changeCount'] }} Änderungen geprüft</strong></p>
                <ul>@forelse($result['descriptions'] as $description)<li>{{ $description }}</li>@empty<li>Keine Änderungen am aktiven Frontend.</li>@endforelse</ul>
            @endif
            @if(($result['kind']??null)==='draft-discarded')
                <p><strong>CMS-Entwurf zurückgesetzt.</strong> Die Website wurde nicht verändert.</p>
                <ul>@foreach($result['descriptions'] as $description)<li>{{ $description }}</li>@endforeach</ul>
            @endif
            @if(isset($result['path']))<p>Die Vorschau wurde sicher gespeichert und kann geprüft werden.</p>@endif
        @endif
    </x-filament::section>
    <x-filament::section heading="Übernehmen oder zurücksetzen">
        <p>Nach Ihrer Bestätigung wird ausschließlich die geprüfte Vorschau übernommen. „Letzte Änderung zurücksetzen“ stellt den vorherigen Website-Stand wieder her; Ihre gespeicherten CMS-Entwürfe bleiben erhalten.</p>
        @php($lastApply=\App\Models\AuditEvent::where('object_type','publication')->where('action','apply')->latest('id')->first())
        <p>Letzte Übernahme: {{ $lastApply?->created_at?->format('d.m.Y H:i') ?? 'Noch keine Übernahme protokolliert' }}</p>
    </x-filament::section>
    <details><summary>Technische Details und Downloads</summary><p>Diese Angaben sind nur für Fehlersuche und Support gedacht.</p>@if(isset($result['candidateHash']))<p>Vorschau-ID: <code title="{{ $result['candidateHash'] }}">{{ $result['candidateHash'] }}</code></p>@endif @if(isset($result['planHash']))<p>Prüf-ID: <code>{{ $result['planHash'] }}</code></p>@endif @if(isset($result['hash']))<p>Prüfsumme: <code>{{ $result['hash'] }}</code></p>@endif
        @php($historyFile=storage_path('app/private/frontend-apply/history.jsonl')) @php($history=is_file($historyFile)?array_slice(file($historyFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES),-8):[])
        <h3>Technischer Verlauf</h3><ul>@foreach($history as $line) @php($entry=json_decode($line,true)) @if(is_array($entry))<li>{{ $entry['timestamp'] ?? '' }} · {{ $entry['operation'] ?? '' }} · {{ $entry['status'] ?? '' }} · {{ substr($entry['resultingManifestHash'] ?? '',0,12) }}</li>@endif @endforeach</ul>
        <h3>Lokale Pakete</h3><ul>@foreach(\App\Models\Publication::latest()->limit(10)->get() as $package)<li>{{ $package->created_at->format('d.m.Y H:i') }} · {{ $package->status }} · {{ substr($package->hash,0,12) }} · <x-filament::link :href="route('admin.export',['publication'=>$package,'file'=>'manifest.json'])">Inhalt herunterladen</x-filament::link> · <x-filament::link :href="route('admin.export',['publication'=>$package,'file'=>'cms-settings.json'])">Einstellungen herunterladen</x-filament::link></li>@endforeach</ul>
    </details>
</x-filament-panels::page>
