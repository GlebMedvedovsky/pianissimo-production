<x-filament-panels::page>
    @if(($data['provider']??'none')==='none')<x-filament::section heading="Keine Besucherstatistik"><p>Es werden keine Besucherstatistiken erhoben.</p></x-filament::section>@endif
    @if(($data['enabled']??false) && !((\App\Models\Setting::read('consent')['analytics']??false)))<x-filament::section heading="Analyse-Einwilligung fehlt"><p>Analytics ist konfiguriert, aber die Kategorie „Analyse“ ist unter Cookie / Consent ausgeschaltet. Deshalb wird kein Tracking geladen.</p><x-filament::link :href="\App\Filament\Pages\Consent::getUrl()">Cookie / Consent prüfen</x-filament::link></x-filament::section>@endif
    <x-filament::section heading="So funktioniert es"><p>Die CMS speichert keine eigenen Besucherprofile. Sie verbindet nach Prüfung nur den gewählten Anbieter. Ohne gültige öffentliche Kennung und Zustimmung des Besuchers wird nichts geladen.</p></x-filament::section>
    <form wire:submit="save" class="space-y-6">{{ $this->form }}</form>
</x-filament-panels::page>
