<style>
    .fi-body { background: #F3F0EA; }
    .dark .fi-body { background: #141414; }
    .cms-topbar-theme { flex-shrink: 0; }
    .fi-topbar-end { min-width: 0; }
    .fi-topbar .fi-global-search-ctn { min-width: 0; }
    @media (max-width: 639px) {
        .fi-topbar-end { flex: 1; margin-inline-start: .75rem; gap: .5rem; }
        .fi-topbar .fi-global-search-ctn { flex: 1; }
        .fi-topbar .fi-global-search { min-width: 0; }
        .fi-topbar .fi-global-search .fi-input-wrp { min-width: 0; }
        .fi-topbar .fi-global-search input { width: 100%; min-width: 0; }
        .cms-topbar-theme .fi-theme-switcher { gap: 0; }
        .cms-topbar-theme .fi-theme-switcher-btn { padding: .375rem; }
    }
</style>
@auth
<script>
    // Filament's switcher and system-media listener remain authoritative.
    // Seed localStorage BEFORE Filament/Alpine starts, from this user's DB choice.
    localStorage.setItem('theme', @js(auth()->user()->admin_theme));
    window.addEventListener('theme-changed', async (event) => {
        const theme = typeof event.detail === 'string' ? event.detail : event.detail?.theme;
        if (!['dark', 'light', 'system'].includes(theme)) return;
        const response = await fetch(@js(url('/admin-theme')), {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token())},
            body: JSON.stringify({theme})
        });
        if (!response.ok) window.dispatchEvent(new CustomEvent('cms-theme-error'));
    });
</script>
@endauth
