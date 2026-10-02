<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Website-Vorschau wird erstellt</title>
    <style>
        :root { color-scheme: dark; font-family: Inter, ui-sans-serif, system-ui, sans-serif; background: #111; color: #f4f1ea; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; }
        main { width: min(34rem, calc(100% - 3rem)); text-align: center; }
        h1 { font-family: Georgia, serif; font-weight: 400; font-size: clamp(2rem, 6vw, 3.5rem); margin: 0 0 1rem; }
        p { color: #c8c3b8; line-height: 1.6; }
        .spinner { width: 2rem; height: 2rem; margin: 2rem auto; border: 2px solid #4b4841; border-top-color: #f4f1ea; border-radius: 50%; animation: spin .9s linear infinite; }
        [role="alert"] { color: #ffd0c8; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) { .spinner { animation-duration: 2s; } }
    </style>
</head>
<body>
<main>
    <h1>Website-Vorschau wird erstellt</h1>
    <p>Die aktuelle Vorschau wird sicher vorbereitet.<br>Das dauert normalerweise weniger als eine Minute.</p>
    <div class="spinner" aria-hidden="true"></div>
    <p id="state" aria-live="polite">Vorschau wird erstellt</p>
</main>
<script>
(() => {
    const statusUrl = @json($statusUrl);
    const state = document.getElementById('state');
    let stopped = false;
    const poll = async () => {
        if (stopped) return;
        try {
            const response = await fetch(statusUrl, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                stopped = true;
                state.setAttribute('role', 'alert');
                state.textContent = data.message || 'Die Website-Vorschau ist nicht mehr verfügbar.';
                document.querySelector('.spinner')?.remove();
                return;
            }
            if (data.state === 'ready') {
                stopped = true;
                state.textContent = 'Vorschau ist bereit';
                window.location.reload();
                return;
            }
        } catch (_) {
            state.textContent = 'Verbindung wird erneut geprüft';
        }
        window.setTimeout(poll, 2500);
    };
    window.setTimeout(poll, 1500);
})();
</script>
</body>
</html>
