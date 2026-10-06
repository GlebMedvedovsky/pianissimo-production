<x-filament-panels::page>
    <x-filament::section heading="Änderungen veröffentlichen">
        <p><strong>Speichern allein ändert die Website nicht.</strong> Öffnen Sie die Website-Vorschau für eine visuelle Kontrolle. „Änderungen prüfen“ bereitet anschließend alle notwendigen Sicherheitsprüfungen vor. Erst „Für Website übernehmen“ ändert den sichtbaren lokalen Website-Stand.</p>
        <p>„Nicht veröffentlichte Änderungen verwerfen“ setzt nur den CMS-Entwurf auf den zuletzt übernommenen Stand zurück. „Letzte Änderung zurücksetzen“ bleibt ausschließlich für einen bereits übernommenen Website-Stand.</p>
        <ol style="list-style:decimal;padding-left:1.25rem"><li>Entwurf speichern</li><li>Website-Vorschau öffnen</li><li>Änderungen prüfen</li><li>Für Website übernehmen</li><li>Jetzt öffentlich veröffentlichen</li></ol>
        @if(app(\App\Services\DraftPreviewService::class)->hasCurrentForUser((int)auth()->id()))
            <p role="status"><strong>Eine aktuelle private Website-Vorschau ist verfügbar.</strong></p>
        @else
            <p role="status"><strong>Noch keine aktuelle Vorschau vorhanden.</strong></p>
        @endif
        @php($production=app(\App\Services\ProductionPublicationCoordinator::class)->status())
        <div role="status" wire:poll.5s>
            <p><strong>{{ match($production['state']) {'ready'=>'Bereit zur öffentlichen Veröffentlichung','queued'=>'Veröffentlichung wird gestartet','in_progress'=>'Veröffentlichung läuft','published'=>'Erfolgreich veröffentlicht','failed'=>'Veröffentlichung fehlgeschlagen',default=>'Öffentliche Veröffentlichung noch nicht verfügbar'} }}</strong></p>
            <p>{{ $production['message'] }}</p>
            @if(isset($production['sequence']))<p>Veröffentlichung Nr. {{ $production['sequence'] }}</p>@endif
            @if(isset($production['publishedAt']))<p>Veröffentlicht am {{ $production['publishedAt']->format('d.m.Y H:i') }}</p>@endif
            @if(isset($production['lastPublishedAt']) && $production['state']!=='published')<p>Letzte erfolgreiche Veröffentlichung: Nr. {{ $production['lastPublishedSequence'] }} · {{ $production['lastPublishedAt']->format('d.m.Y H:i') }}</p>@endif
            @if(isset($production['failureMessage']))<p role="alert">{{ $production['failureMessage'] }}</p>@endif
        </div>
        @php($reviewState=app(\App\Services\FrontendPublication::class)->currentReview())
        @if($reviewState['kind']==='stale')
            <p role="status"><strong>Die geprüften Änderungen sind nicht mehr aktuell.</strong> Wählen Sie „Änderungen erneut prüfen“. Eine veraltete Prüfung kann nicht übernommen werden.</p>
        @elseif($reviewState['kind']==='current')
            <p role="status"><strong>Die Änderungen wurden geprüft und können übernommen werden.</strong></p>
        @elseif($reviewState['kind']==='applied')
            <p role="status"><strong>Der geprüfte Stand wurde bereits übernommen.</strong></p>
        @else
            <p role="status"><strong>Die Änderungen wurden noch nicht geprüft.</strong></p>
        @endif
        @if(isset($result['error']))
            <p role="alert">{{ $result['error'] }}</p>
        @elseif($result)
            @if(isset($result['projects']))
                <p style="margin-top:1rem"><strong>Geprüft:</strong> {{ $result['projects'] }} Projekte · {{ $result['tracks'] }} Tracks · {{ $result['assets'] }} Medien</p>
            @endif
            @if(($result['kind']??null)==='review')
                <p><strong>{{ $result['changeCount'] }} Änderungen geprüft</strong></p>
                <ul>
                    @forelse($result['descriptions'] as $description)
                        <li>{{ $description }}</li>
                    @empty
                        <li>Keine Änderungen am aktiven Frontend.</li>
                    @endforelse
                </ul>
            @endif
            @if(($result['kind']??null)==='draft-discarded')
                <p><strong>CMS-Entwurf zurückgesetzt.</strong> Die Website wurde nicht verändert.</p>
                <ul>
                    @foreach($result['descriptions'] as $description)
                        <li>{{ $description }}</li>
                    @endforeach
                </ul>
            @endif
            @if(($result['kind']??null)==='production')
                <p><strong>Öffentliche Veröffentlichung Nr. {{ $result['sequence'] }} wurde gestartet.</strong></p>
            @endif
            @if(isset($result['path']))
                <p>Der technische Paketstand wurde sicher gespeichert.</p>
            @endif
        @endif
    </x-filament::section>
    <x-filament::section heading="Übernehmen oder zurücksetzen">
        <p>Nach Ihrer Bestätigung wird ausschließlich die geprüfte Vorschau übernommen. „Letzte Änderung zurücksetzen“ stellt den vorherigen Website-Stand wieder her; Ihre gespeicherten CMS-Entwürfe bleiben erhalten.</p>
        @php($lastApply=\App\Models\AuditEvent::where('object_type','publication')->where('action','apply')->latest('id')->first())
        <p>Letzte Übernahme: {{ $lastApply?->created_at?->format('d.m.Y H:i') ?? 'Noch keine Übernahme protokolliert' }}</p>
    </x-filament::section>
    <details>
        <summary>Technische Details und Downloads</summary>
        <p>Diese Angaben und internen Paket-Aktionen sind nur für Fehlersuche und Support gedacht. Die technischen Aktionen befinden sich im Menü „Technische Details“ oben.</p>
        @if(isset($result['candidateHash']))
            <p>Candidate-ID: <code title="{{ $result['candidateHash'] }}">{{ $result['candidateHash'] }}</code></p>
        @endif
        @if(isset($result['planHash']))
            <p>Prüf-ID: <code>{{ $result['planHash'] }}</code></p>
        @endif
        @if(isset($result['hash']))
            <p>Prüfsumme: <code>{{ $result['hash'] }}</code></p>
        @endif
        @php($historyFile=storage_path('app/private/frontend-apply/history.jsonl'))
        @php($history=is_file($historyFile)?array_slice(file($historyFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES),-8):[])
        <h3>Technischer Verlauf</h3>
        <ul>
            @foreach($history as $line)
                @php($entry=json_decode($line,true))
                @if(is_array($entry))
                    <li>{{ $entry['timestamp'] ?? '' }} · {{ $entry['operation'] ?? '' }} · {{ $entry['status'] ?? '' }} · {{ substr($entry['resultingManifestHash'] ?? '',0,12) }}</li>
                @endif
            @endforeach
        </ul>
        <h3>Lokale Pakete</h3>
        <ul>
            @foreach(\App\Models\Publication::latest()->limit(10)->get() as $package)
                <li>{{ $package->created_at->format('d.m.Y H:i') }} · {{ $package->status }} · {{ substr($package->hash,0,12) }} · <x-filament::link :href="route('admin.export',['publication'=>$package,'file'=>'manifest.json'])">Inhalt herunterladen</x-filament::link> · <x-filament::link :href="route('admin.export',['publication'=>$package,'file'=>'cms-settings.json'])">Einstellungen herunterladen</x-filament::link></li>
            @endforeach
        </ul>
    </details>
</x-filament-panels::page>
