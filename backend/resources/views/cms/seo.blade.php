<x-filament-panels::page>
    @if(str_starts_with((string)($data['robots']??''),'noindex'))<x-filament::section heading="Hinweis zur späteren Veröffentlichung"><p>Suchmaschinen sind derzeit bewusst ausgeschlossen. Das ist für Vorbereitung und Prüfung richtig. Vor einer späteren öffentlichen Freigabe muss diese Einstellung noch einmal geprüft werden.</p></x-filament::section>@endif
    <form wire:submit="save" class="space-y-6">{{ $this->form }}</form>
</x-filament-panels::page>
