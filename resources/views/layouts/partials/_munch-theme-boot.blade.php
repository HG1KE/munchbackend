{{-- FOUC-safe theme boot: localStorage → cookie → prefers-color-scheme --}}
<script>
(function () {
    var KEY = 'munch-theme';
    var theme = null;
    try {
        theme = window.localStorage.getItem(KEY);
    } catch (e) {}
    if (theme !== 'dark' && theme !== 'light') {
        var match = document.cookie.match(/(?:^|;\s*)munch_theme=(dark|light)/);
        theme = match ? match[1] : null;
    }
    if (theme !== 'dark' && theme !== 'light') {
        theme = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)
            ? 'dark'
            : 'light';
    }
    document.documentElement.setAttribute('data-munch-theme', theme);
    document.documentElement.style.colorScheme = theme === 'dark' ? 'dark' : 'light';
    window.__MUNCH_THEME__ = theme;
})();
</script>
