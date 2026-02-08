/**
 * Theme Toggle
 * Manages dark/light theme with persistence in localStorage
 */
(function() {
    const toggle = document.getElementById('theme-toggle');
    const root = document.documentElement;
    const stored = localStorage.getItem('theme');

    function applyTheme(name) {
        if (name === 'dark') {
            root.classList.add('dark-theme');
        } else {
            root.classList.remove('dark-theme');
        }
        toggle.textContent = name === 'dark' ? '☀️' : '🌙';
    }

    applyTheme(stored === 'dark' ? 'dark' : 'light');

    toggle.addEventListener('click', () => {
        const current = root.classList.contains('dark-theme') ? 'dark' : 'light';
        const next = current === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        localStorage.setItem('theme', next);
    });
})();
