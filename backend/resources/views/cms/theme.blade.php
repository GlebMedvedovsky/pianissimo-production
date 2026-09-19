<style>
    .fi-body { background: #F3F0EA; }
    .dark .fi-body { background: #141414; }
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
