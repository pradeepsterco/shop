@fluxAppearance
<script>
    (function () {
        const applyTheme = () => {
            const theme = (window.Flux && window.Flux.appearance) || localStorage.getItem('flux.appearance') || 'system';
            const isDark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', isDark);
        };
        applyTheme();
        document.addEventListener('livewire:navigated', applyTheme);
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
            const theme = (window.Flux && window.Flux.appearance) || localStorage.getItem('flux.appearance') || 'system';
            if (theme === 'system') {
                document.documentElement.classList.toggle('dark', e.matches);
            }
        });
    })();
</script>

