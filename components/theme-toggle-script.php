<?php include __DIR__ . '/account-status-popup-loader.php'; ?>
<?php require_once __DIR__ . '/../includes/theme_context.php'; ?>
<script>
(function(){
    const html = document.documentElement;
    const icon = document.getElementById('themeIcon');
    const toggle = document.getElementById('darkToggle');
    const forceLight = <?= themeIsAdminArea() ? 'true' : 'false' ?>;
    if (!toggle || !icon) return;

    function renderThemeIcon(){
        icon.innerHTML = html.classList.contains('dark')
            ? '<circle cx="12" cy="12" r="4" stroke-width="1.8"/><path stroke-width="1.8" d="M12 2v2m0 16v2m10-10h-2M4 12H2m15.36 6.36-1.42-1.42M8.05 8.05 6.64 6.64m10.72 0-1.42 1.41M8.05 15.95l-1.41 1.41"/>'
            : '<path stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A9 9 0 1111.2 3 7 7 0 0021 12.8z"/>';
    }

    function applyThemeFromPreference(){
        if (forceLight) {
            html.classList.remove('dark');
            return;
        }
        if (localStorage.theme === 'light') {
            html.classList.remove('dark');
        } else {
            html.classList.add('dark');
        }
    }

    applyThemeFromPreference();
    renderThemeIcon();

    toggle.addEventListener('click', function(){
        if (forceLight) return;
        html.classList.toggle('dark');
        localStorage.theme = html.classList.contains('dark') ? 'dark' : 'light';
        renderThemeIcon();
    });
})();
</script>
