/**
 * Munch admin/branch dark mode — toggle, persistence, chart chrome helpers.
 * Preference: localStorage + cookie. System preference only when unset.
 */
(function (global) {
    'use strict';

    var STORAGE_KEY = 'munch-theme';
    var COOKIE_NAME = 'munch_theme';
    var COOKIE_DAYS = 400;

    function readCookie() {
        var match = document.cookie.match(/(?:^|;\s*)munch_theme=(dark|light)/);
        return match ? match[1] : null;
    }

    function writeCookie(theme) {
        var maxAge = COOKIE_DAYS * 24 * 60 * 60;
        document.cookie = COOKIE_NAME + '=' + theme + ';path=/;max-age=' + maxAge + ';SameSite=Lax';
    }

    function getStoredTheme() {
        try {
            var ls = global.localStorage.getItem(STORAGE_KEY);
            if (ls === 'dark' || ls === 'light') {
                return ls;
            }
        } catch (e) {}
        var cookie = readCookie();
        if (cookie === 'dark' || cookie === 'light') {
            return cookie;
        }
        return null;
    }

    function systemTheme() {
        return (global.matchMedia && global.matchMedia('(prefers-color-scheme: dark)').matches)
            ? 'dark'
            : 'light';
    }

    function resolveTheme() {
        return getStoredTheme() || systemTheme();
    }

    function applyTheme(theme, options) {
        var next = theme === 'dark' ? 'dark' : 'light';
        var root = document.documentElement;
        var body = document.body;
        root.setAttribute('data-munch-theme', next);
        root.style.colorScheme = next === 'dark' ? 'dark' : 'light';
        if (body) {
            body.setAttribute('data-munch-theme', next);
        }
        global.__MUNCH_THEME__ = next;

        if (!options || options.persist !== false) {
            try {
                global.localStorage.setItem(STORAGE_KEY, next);
            } catch (e) {}
            writeCookie(next);
        }

        syncToggleButtons(next);
        applyChartChrome(next);

        try {
            global.dispatchEvent(new CustomEvent('munch-theme-change', { detail: { theme: next } }));
        } catch (e) {}

        return next;
    }

    function toggleTheme() {
        var current = (document.documentElement.getAttribute('data-munch-theme') === 'dark') ? 'dark' : 'light';
        return applyTheme(current === 'dark' ? 'light' : 'dark');
    }

    function syncToggleButtons(theme) {
        var isDark = theme === 'dark';
        var nodes = document.querySelectorAll('[data-munch-theme-toggle]');
        for (var i = 0; i < nodes.length; i++) {
            var btn = nodes[i];
            btn.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            btn.setAttribute('title', isDark ? 'Switch to light mode' : 'Switch to dark mode');
            btn.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
            var sun = btn.querySelector('[data-munch-theme-icon="sun"]');
            var moon = btn.querySelector('[data-munch-theme-icon="moon"]');
            if (sun) {
                sun.hidden = !isDark;
            }
            if (moon) {
                moon.hidden = isDark;
            }
        }
    }

    function apexDarkPalette() {
        return {
            mode: 'dark',
            palette: 'palette1',
            monochrome: { enabled: false },
            colors: ['#f87171', '#60a5fa', '#34d399', '#fbbf24', '#a78bfa', '#fb7185'],
            background: 'transparent',
            foreColor: '#cbd5e1',
        };
    }

    function apexLightPalette() {
        return {
            mode: 'light',
            palette: 'palette1',
            monochrome: { enabled: false },
            colors: ['#E7032D', '#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899'],
            background: 'transparent',
            foreColor: '#334155',
        };
    }

    function applyChartChrome(theme) {
        var isDark = theme === 'dark';

        if (global.Apex) {
            global.Apex.theme = isDark ? apexDarkPalette() : apexLightPalette();
            global.Apex.chart = global.Apex.chart || {};
            global.Apex.chart.background = 'transparent';
            global.Apex.grid = global.Apex.grid || {};
            global.Apex.grid.borderColor = isDark ? '#334155' : '#e2e8f0';
            global.Apex.xaxis = global.Apex.xaxis || {};
            global.Apex.xaxis.labels = Object.assign({}, global.Apex.xaxis.labels || {}, {
                style: { colors: isDark ? '#94a3b8' : '#64748b' },
            });
            global.Apex.yaxis = global.Apex.yaxis || {};
            global.Apex.yaxis.labels = Object.assign({}, global.Apex.yaxis.labels || {}, {
                style: { colors: isDark ? '#94a3b8' : '#64748b' },
            });
            global.Apex.tooltip = Object.assign({}, global.Apex.tooltip || {}, {
                theme: isDark ? 'dark' : 'light',
            });
        }

        if (global.Chart && global.Chart.defaults) {
            var defaults = global.Chart.defaults;
            var tick = isDark ? '#94a3b8' : '#64748b';
            var grid = isDark ? 'rgba(148, 163, 184, 0.18)' : 'rgba(15, 23, 42, 0.08)';
            if (defaults.global) {
                defaults.global.defaultFontColor = tick;
            }
            if (defaults.color !== undefined) {
                defaults.color = tick;
            }
            if (defaults.scale) {
                defaults.scale.gridLines = Object.assign({}, defaults.scale.gridLines || {}, {
                    color: grid,
                    zeroLineColor: grid,
                });
                defaults.scale.ticks = Object.assign({}, defaults.scale.ticks || {}, {
                    fontColor: tick,
                });
            }
            if (defaults.plugins && defaults.plugins.tooltip) {
                defaults.plugins.tooltip.backgroundColor = isDark ? '#0f172a' : '#ffffff';
                defaults.plugins.tooltip.titleColor = isDark ? '#e2e8f0' : '#0f172a';
                defaults.plugins.tooltip.bodyColor = isDark ? '#cbd5e1' : '#334155';
                defaults.plugins.tooltip.borderColor = isDark ? '#334155' : '#e2e8f0';
            }
        }

        // Soft-refresh existing Apex instances when theme flips
        if (global.ApexCharts && typeof global.ApexCharts.exec === 'function') {
            // no global registry; try DOM-bound charts via updateOptions when present
        }
        var canvases = document.querySelectorAll('.apexcharts-canvas');
        for (var i = 0; i < canvases.length; i++) {
            var host = canvases[i].parentElement;
            if (!host || !host.id) {
                continue;
            }
            try {
                if (global.ApexCharts && host.__munchApex) {
                    host.__munchApex.updateOptions({
                        theme: isDark ? apexDarkPalette() : apexLightPalette(),
                        chart: { background: 'transparent' },
                        grid: { borderColor: isDark ? '#334155' : '#e2e8f0' },
                        tooltip: { theme: isDark ? 'dark' : 'light' },
                    }, false, true);
                }
            } catch (e) {}
        }
    }

    function bindToggles() {
        document.addEventListener('click', function (event) {
            var btn = event.target.closest('[data-munch-theme-toggle]');
            if (!btn) {
                return;
            }
            event.preventDefault();
            toggleTheme();
        });
    }

    function boot() {
        var theme = resolveTheme();
        applyTheme(theme, { persist: getStoredTheme() !== null });
        bindToggles();

        if (global.matchMedia) {
            var mq = global.matchMedia('(prefers-color-scheme: dark)');
            var onChange = function () {
                if (getStoredTheme() === null) {
                    applyTheme(systemTheme(), { persist: false });
                }
            };
            if (typeof mq.addEventListener === 'function') {
                mq.addEventListener('change', onChange);
            } else if (typeof mq.addListener === 'function') {
                mq.addListener(onChange);
            }
        }
    }

    global.MunchTheme = {
        resolve: resolveTheme,
        apply: applyTheme,
        toggle: toggleTheme,
        get: function () {
            return document.documentElement.getAttribute('data-munch-theme') === 'dark' ? 'dark' : 'light';
        },
        isDark: function () {
            return this.get() === 'dark';
        },
        apexTheme: function () {
            return this.isDark() ? apexDarkPalette() : apexLightPalette();
        },
        chartTooltipTheme: function () {
            return this.isDark() ? 'dark' : 'light';
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(typeof window !== 'undefined' ? window : this);
