/**
 * 主题管理模块 - 支持自动/亮色/暗色三种模式
 */

var THEME_KEY = 'theme_mode';

function getThemeMode() {
    return localStorage.getItem(THEME_KEY) || 'auto';
}

function setThemeMode(mode) {
    if (!['auto', 'light', 'dark'].includes(mode)) mode = 'auto';
    localStorage.setItem(THEME_KEY, mode);
    applyTheme();
    updateThemeIcon();
    updateThemeModeSelect();
}

function applyTheme() {
    var mode = getThemeMode();
    var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

    if (mode === 'dark' || (mode === 'auto' && prefersDark)) {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
}

function updateThemeIcon() {
    var icon = document.getElementById('themeIcon');
    if (!icon) return;
    var isDark = document.documentElement.classList.contains('dark');
    icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
}

function updateThemeModeSelect() {
    var select = document.getElementById('themeModeSelect');
    if (select) {
        select.value = getThemeMode();
    }
}

function toggleTheme() {
    var mode = getThemeMode();
    if (mode === 'auto') {
        var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        setThemeMode(prefersDark ? 'light' : 'dark');
    } else if (mode === 'dark') {
        setThemeMode('light');
    } else {
        setThemeMode('dark');
    }
}

function onThemeModeChange(value) {
    setThemeMode(value);
}

function initTheme() {
    applyTheme();
    updateThemeIcon();

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function() {
        if (getThemeMode() === 'auto') {
            applyTheme();
            updateThemeIcon();
        }
    });
}

initTheme();
