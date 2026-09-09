/**
 * Live elapsed timers + SLA styling for express order cards only.
 * SLA math uses UTC unix seconds only — no Date.parse / ISO strings.
 */
(function () {
    'use strict';

    var SLA_DELAY_SEC = 30 * 60;
    var SLA_ESCALATED_SEC = 45 * 60;
    var SLA_CRITICAL_SEC = 60 * 60;

    var CARD_SLA_CLASSES = [
        'meatco-express-card--sla-delay',
        'meatco-express-card--sla-escalated',
        'meatco-express-card--sla-critical',
    ];

    var TIMER_SLA_CLASSES = [
        'meatco-express-card__timer--sla-delay',
        'meatco-express-card__timer--sla-escalated',
        'meatco-express-card__timer--sla-critical',
    ];

    var slaDebugLogged = 0;
    var slaDebugMaxLogs = 5;

    function isSlaDebugEnabled() {
        if (window.MEATCO_EXPRESS_SLA_DEBUG === true) {
            return true;
        }

        try {
            if (window.localStorage && window.localStorage.getItem('meatco_express_sla_debug') === '1') {
                return true;
            }
        } catch (e) {
            /* ignore */
        }

        return typeof window.location !== 'undefined'
            && window.location.search.indexOf('sla_debug=1') !== -1;
    }

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    function formatElapsed(totalSeconds) {
        var s = Math.max(0, Math.floor(totalSeconds));
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var sec = s % 60;
        return pad(h) + ':' + pad(m) + ':' + pad(sec);
    }

    /** Frozen dispatch KPI — matches server OrderDispatchedTime::formatElapsedDisplay */
    function formatFrozenElapsed(totalSeconds) {
        var s = Math.max(0, Math.floor(totalSeconds));
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var sec = s % 60;
        if (h > 0) {
            return h + ':' + pad(m) + ':' + pad(sec);
        }
        return m + ':' + pad(sec);
    }

    function isFrozenTimer(el) {
        return el.getAttribute('data-timer-frozen') === '1';
    }

    function readFrozenElapsed(el) {
        var frozen = Number(el.dataset.frozenElapsed || 0);
        if (!Number.isFinite(frozen) || frozen < 0) {
            return NaN;
        }
        return Math.floor(frozen);
    }

    function readPlacedAtUnix(el) {
        var placedAtUnix = Number(el.dataset.placedAt || 0);
        if (!Number.isFinite(placedAtUnix) || placedAtUnix <= 0) {
            return 0;
        }
        return Math.floor(placedAtUnix);
    }

    function resolveElapsedSeconds(el) {
        var placedAtUnix = readPlacedAtUnix(el);
        if (!placedAtUnix) {
            return NaN;
        }

        var nowUnix = Math.floor(Date.now() / 1000);
        return Math.max(0, nowUnix - placedAtUnix);
    }

    function slaTierFromSeconds(seconds) {
        if (seconds >= SLA_CRITICAL_SEC) {
            return 'critical';
        }
        if (seconds >= SLA_ESCALATED_SEC) {
            return 'escalated';
        }
        if (seconds >= SLA_DELAY_SEC) {
            return 'delay';
        }
        return 'normal';
    }

    function expressCardSlaClass(seconds) {
        var tier = slaTierFromSeconds(seconds);
        if (tier === 'critical') {
            return 'meatco-express-card--sla-critical';
        }
        if (tier === 'escalated') {
            return 'meatco-express-card--sla-escalated';
        }
        if (tier === 'delay') {
            return 'meatco-express-card--sla-delay';
        }
        return '';
    }

    function expressTimerSlaClass(seconds) {
        var tier = slaTierFromSeconds(seconds);
        if (tier === 'critical') {
            return 'meatco-express-card__timer--sla-critical';
        }
        if (tier === 'escalated') {
            return 'meatco-express-card__timer--sla-escalated';
        }
        if (tier === 'delay') {
            return 'meatco-express-card__timer--sla-delay';
        }
        return '';
    }

    function removeClasses(el, classes) {
        classes.forEach(function (className) {
            el.classList.remove(className);
        });
    }

    function isPrepSlaCard(card) {
        if (!card) {
            return false;
        }
        return card.getAttribute('data-meatco-express-sla') === '1'
            || !!card.closest('.meatco-express-section--prep')
            || !!card.closest('.meatco-express-section--pending')
            || !!card.closest('.meatco-express-section--packing');
    }

    function applyExpressCardSla(card, seconds) {
        removeClasses(card, CARD_SLA_CLASSES);

        if (!isPrepSlaCard(card)) {
            card.setAttribute('data-sla-tier', 'normal');
            return;
        }

        var cardClass = expressCardSlaClass(seconds);
        if (cardClass) {
            card.classList.add(cardClass);
        }

        card.setAttribute('data-sla-seconds', String(Math.floor(seconds)));
        card.setAttribute('data-sla-tier', slaTierFromSeconds(seconds));
    }

    function applyUrgencyToPanel(panel, seconds) {
        panel.classList.remove('meatco-express-detail-panel--warn', 'meatco-express-detail-panel--urgent');
        if (seconds >= SLA_CRITICAL_SEC) {
            panel.classList.add('meatco-express-detail-panel--urgent');
        } else if (seconds >= SLA_DELAY_SEC) {
            panel.classList.add('meatco-express-detail-panel--warn');
        }
    }

    function logSlaDebug(payload) {
        if (!isSlaDebugEnabled() || slaDebugLogged >= slaDebugMaxLogs) {
            return;
        }

        slaDebugLogged += 1;
        console.log('[MeatcoExpressSLA]', payload);
    }

    function tick() {
        var timers = document.querySelectorAll('[data-meatco-order-timer]');

        timers.forEach(function (el, index) {
            if (isFrozenTimer(el)) {
                var frozenSeconds = readFrozenElapsed(el);
                if (!isNaN(frozenSeconds)) {
                    el.textContent = formatFrozenElapsed(frozenSeconds);
                }
                removeClasses(el, TIMER_SLA_CLASSES);
                var frozenCard = el.closest('[data-meatco-express-card]') || el.closest('.meatco-express-card');
                if (frozenCard) {
                    removeClasses(frozenCard, CARD_SLA_CLASSES);
                    frozenCard.setAttribute('data-sla-tier', 'normal');
                }
                return;
            }

            var placedAtUnix = readPlacedAtUnix(el);
            var nowUnix = Math.floor(Date.now() / 1000);
            var seconds = resolveElapsedSeconds(el);

            if (isNaN(seconds)) {
                if (index === 0) {
                    logSlaDebug({
                        order_id: el.getAttribute('data-order-id'),
                        placedAtUnix: placedAtUnix,
                        nowUnix: nowUnix,
                        elapsedSeconds: null,
                        error: 'missing or invalid data-placed-at unix timestamp',
                    });
                }
                return;
            }

            el.textContent = formatElapsed(seconds);
            removeClasses(el, TIMER_SLA_CLASSES);

            var card = el.closest('[data-meatco-express-card]') || el.closest('.meatco-express-card');
            var prepSla = isPrepSlaCard(card);

            if (prepSla) {
                var timerSla = expressTimerSlaClass(seconds);
                if (timerSla) {
                    el.classList.add(timerSla);
                }
            }

            if (card) {
                applyExpressCardSla(card, seconds);
            }

            if (index === 0) {
                logSlaDebug({
                    order_id: el.getAttribute('data-order-id'),
                    placedAtUnix: placedAtUnix,
                    nowUnix: nowUnix,
                    elapsedSeconds: Math.floor(seconds),
                });
            }

            var panel = el.closest('[data-meatco-express-detail-panel]');
            if (panel) {
                applyUrgencyToPanel(panel, seconds);
            }
        });
    }

    function updateScheduledDeliveryRoutesLink(preferredGroup) {
        var link = document.querySelector('[data-meatco-delivery-routes-link]');
        if (!link) {
            return;
        }

        var routePath = link.getAttribute('data-route-path') || '/admin/delivery-routes';
        var openGroup = preferredGroup || document.querySelector('.meatco-scheduled-group.is-open');
        var dateKey = openGroup
            ? openGroup.getAttribute('data-date-key')
            : link.getAttribute('data-default-date');
        var branchId = link.getAttribute('data-branch-id');
        var params = new URLSearchParams();

        if (dateKey) {
            params.set('date', dateKey);
        }

        if (branchId) {
            params.set('branch_id', branchId);
        }

        link.href = params.toString() === ''
            ? routePath
            : routePath + '?' + params.toString();
    }

    function initScheduledGroups() {
        document.querySelectorAll('[data-meatco-scheduled-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var group = btn.closest('.meatco-scheduled-group');
                if (group) {
                    group.classList.toggle('is-open');
                    updateScheduledDeliveryRoutesLink(
                        group.classList.contains('is-open') ? group : null
                    );
                }
            });
        });

        document.querySelectorAll('[data-meatco-scheduled-print]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.stopPropagation();
            });
        });

        document.querySelectorAll('.meatco-scheduled-group').forEach(function (group, index) {
            if (index === 0) {
                group.classList.add('is-open');
            }
        });

        updateScheduledDeliveryRoutesLink();
    }

    function boot() {
        tick();
        setInterval(tick, 1000);
        initScheduledGroups();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
