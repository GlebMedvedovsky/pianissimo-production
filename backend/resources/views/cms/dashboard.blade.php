<x-filament-panels::page>
    <x-filament::section heading="Pianissimo Production · lokale Redaktion">
        <p>{{ \App\Models\Project::count() }} Projekte · {{ \App\Models\Track::count() }} Tracks · {{ \App\Models\MediaAsset::count() }} Medien</p>
        <p>Entwurf speichern → Export-Vorschau erstellen → für Frontend ausdrücklich übernehmen.</p>
        <p>Speichern und Export ändern die Website nicht. Die separate lokale Apply-Aktion übernimmt einen geprüften Stand auf Dev 4321; Rollback stellt das vorherige Manifest wieder her. Production Released: false. Netcup und öffentlicher Audio-Release sind nicht freigegeben.</p>
    </x-filament::section>
    <x-filament::section heading="Offene Freigaben">
        <p>Au milieu de l’été: MP3 bereits erhalten und technisch vorbereitet. Bestätigung der konkreten Playback-Version sowie Dauer-/Waveform-Prüfung (ca. 0,18 s Differenz) bleiben offen. Keine erneute Zusendung erforderlich.</p>
        <p>Kinnhakenhank: ausschließlich ausgewählter V2-Trailer. Rechtstexte und Production-Freigabe bleiben offen.</p>
    </x-filament::section>
</x-filament-panels::page>
