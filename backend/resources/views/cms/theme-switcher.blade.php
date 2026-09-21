@auth
    {{-- The native buttons call close() when used in a dropdown. Here they stay visible. --}}
    <div class="cms-topbar-theme" x-data="{ close() {} }">
        <x-filament-panels::theme-switcher />
    </div>
@endauth
