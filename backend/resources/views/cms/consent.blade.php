<x-filament-panels::page>
    <x-filament::section heading="Was steuert diese Seite?"><p>Hier legen Sie fest, welche optionalen Dienste Besucher auswählen können. Notwendige Funktionen bleiben immer aktiv. Speichern allein ändert die Website nicht.</p></x-filament::section>
    <form wire:submit="save" class="space-y-6">{{ $this->form }}</form>
</x-filament-panels::page>
