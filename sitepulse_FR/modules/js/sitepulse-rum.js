(function (window, document) {
    'use strict';

    var config = window.SitePulseRUMConfig || {};

    if (!config || !config.enabled || !config.restUrl || !config.token) {
        return;
    }

    var consentRequired = !!config.consentRequired;
    var consentGranted = !consentRequired || !!config.consentGranted;
    var queue = [];
    var flushTimer = null;
    var lcpValue = null;
    var lcpSent = false;
    var fidSent = false;
    var clsValue = 0;
    var clsSources = 0;
    var navigationType = '';
    var device = typeof config.device === 'string' && config.device ? config.device : detectDevice();
    var connection = detectConnection();
    var batchSize = typeof config.batchSize === 'number' && config.batchSize > 0 ? config.batchSize : 6;
    var flushDelay = typeof config.flushDelay === 'number' && config.flushDelay > 0 ? config.flushDelay : 4000;

    initNavigationType();
    observeLCP();
    observeFID();
    observeCLS();

    if (document.readyState === 'complete') {
        scheduleLCPFinalization();
    } else {
        window.addEventListener('load', scheduleLCPFinalization);
    }

    window.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            finalizeMetrics();
        }
    });

    window.addEventListener('pagehide', finalizeMetrics);
    window.addEventListener('beforeunload', finalizeMetrics);

    exposeConsentHelpers();

    function detectDevice() {
        if (window.matchMedia) {
            try {
                if (window.matchMedia('(pointer: coarse)').matches) {
                    return 'mobile';
                }
            } catch (error) {}
        }

        if (navigator && navigator.userAgentData && typeof navigator.userAgentData.mobile === 'boolean') {
            return navigator.userAgentData.mobile ? 'mobile' : 'desktop';
        }

        if (typeof navigator !== 'undefined' && typeof navigator.userAgent === 'string') {
            var ua = navigator.userAgent.toLowerCase();

            if (ua.indexOf('mobile') !== -1 || ua.indexOf('android') !== -1 || ua.indexOf('iphone') !== -1) {
                return 'mobile';
            }
        }

        return 'desktop';
    }

    function detectConnection() {
        if (typeof navigator !== 'undefined' && navigator.connection && typeof navigator.connection.effectiveType === 'string') {
            return navigator.connection.effectiveType;
        }

        return '';
    }

    function initNavigationType() {
        if (typeof performance !== 'undefined' && performance.getEntriesByType) {
            try {
                var entries = performance.getEntriesByType('navigation');

                if (entries && entries.length) {
                    navigationType = entries[0].type || '';
                }
            } catch (error) {}
        }
    }

    function hasObserver(type) {
        return typeof PerformanceObserver !== 'undefined'
            && PerformanceObserver.supportedEntryTypes
            && PerformanceObserver.supportedEntryTypes.indexOf(type) !== -1;
    }

    function observeLCP() {
        if (!hasObserver('largest-contentful-paint')) {
            return;
        }

        try {
            var observer = new PerformanceObserver(function (list) {
                var entries = list.getEntries();

                if (!entries || !entries.length) {
                    return;
                }

                var entry = entries[entries.length - 1];
                lcpValue = entry.renderTime || entry.loadTime || entry.startTime || null;
            });

            observer.observe({ type: 'largest-contentful-paint', buffered: true });

            window.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'hidden') {
                    observer.disconnect();
                }
            });
        } catch (error) {}
    }

    function observeFID() {
        if (!hasObserver('first-input')) {
            return;
        }

        try {
            var observer = new PerformanceObserver(function (list) {
                var entries = list.getEntries();

                if (!entries || !entries.length || fidSent) {
                    return;
                }

                var entry = entries[0];
                var delay = entry.processingStart && entry.startTime ? entry.processingStart - entry.startTime : null;

                if (delay !== null && isFinite(delay) && delay >= 0) {
                    reportMetric('FID', delay, {
                        target: entry.name || '',
                        eventType: entry.entryType || 'first-input'
                    });
                    fidSent = true;
                }

                observer.disconnect();
            });

            observer.observe({ type: 'first-input', buffered: true });
        } catch (error) {}
    }

    function observeCLS() {
        if (!hasObserver('layout-shift')) {
            return;
        }

        try {
            var observer = new PerformanceObserver(function (list) {
                list.getEntries().forEach(function (entry) {
                    if (entry && !entry.hadRecentInput) {
                        clsValue += entry.value || 0;
                        clsSources += 1;
                    }
                });
            });

            observer.observe({ type: 'layout-shift', buffered: true });

            window.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'hidden') {
                    observer.disconnect();
                }
            });
        } catch (error) {}
    }

    function scheduleLCPFinalization() {
        if (lcpSent) {
            return;
        }

        setTimeout(function () {
            finalizeLCP();
        }, 0);
    }

    function finalizeLCP() {
        if (lcpSent || lcpValue === null || !isFinite(lcpValue)) {
            return;
        }

        reportMetric('LCP', lcpValue);
        lcpSent = true;
    }

    function finalizeCLS() {
        if (!clsSources) {
            return;
        }

        reportMetric('CLS', clsValue, {
            samples: clsSources
        });

        clsSources = 0;
    }

    function finalizeMetrics() {
        finalizeLCP();
        finalizeCLS();
        flushQueue(true);
    }

    function reportMetric(name, value, extra) {
        if (!consentGranted && consentRequired) {
            return;
        }

        if (!isFinite(value) || value < 0) {
            return;
        }

        var metric = {
            name: name,
            value: value,
            rating: computeRating(name, value),
            url: window.location && window.location.pathname ? window.location.pathname : '/',
            device: device,
            connection: connection,
            navigationType: navigationType,
            timestamp: Date.now()
        };

        if (extra && typeof extra === 'object') {
            Object.keys(extra).forEach(function (key) {
                metric[key] = extra[key];
            });
        }

        queue.push(metric);

        if (queue.length >= batchSize) {
            flushQueue(true);
        } else {
            scheduleFlush();
        }
    }

    function computeRating(name, value) {
        if (!isFinite(value)) {
            return 'unknown';
        }

        if (name === 'LCP') {
            if (value <= 2500) {
                return 'good';
            }

            return value <= 4000 ? 'needs_improvement' : 'poor';
        }

        if (name === 'FID') {
            if (value <= 100) {
                return 'good';
            }

            return value <= 300 ? 'needs_improvement' : 'poor';
        }

        if (name === 'CLS') {
            if (value <= 0.1) {
                return 'good';
            }

            return value <= 0.25 ? 'needs_improvement' : 'poor';
        }

        return 'unknown';
    }

    function scheduleFlush() {
        if (flushTimer) {
            return;
        }

        flushTimer = window.setTimeout(function () {
            flushQueue(false);
        }, flushDelay);
    }

    function flushQueue(force) {
        if (!queue.length || (consentRequired && !consentGranted)) {
            clearTimer();
            return;
        }

        var payload = {
            token: config.token,
            metrics: queue.splice(0, queue.length)
        };

        var body;

        try {
            body = JSON.stringify(payload);
        } catch (error) {
            return;
        }

        if (!body) {
            return;
        }

        var url = config.restUrl;

        if (!url) {
            return;
        }

        if (!force && navigator && typeof navigator.sendBeacon === 'function') {
            try {
                var blob = new Blob([body], { type: 'application/json' });
                var queued = navigator.sendBeacon(url, blob);

                if (queued) {
                    clearTimer();
                    return;
                }
            } catch (error) {}
        }

        if (typeof fetch === 'function') {
            fetch(url, {
                method: 'POST',
                credentials: 'omit',
                keepalive: true,
                headers: {
                    'Content-Type': 'application/json'
                },
                body: body
            })['catch'](function () {});
        } else {
            try {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', url, true);
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.send(body);
            } catch (error) {}
        }

        clearTimer();
    }

    function clearTimer() {
        if (flushTimer) {
            window.clearTimeout(flushTimer);
            flushTimer = null;
        }
    }

    function exposeConsentHelpers() {
        var api = window.SitePulseRUM || {};

        api.grantConsent = function () {
            consentGranted = true;
            flushQueue(true);
        };

        api.revokeConsent = function () {
            consentGranted = false;
            clearTimer();
            queue.length = 0;
        };

        api.enqueueMetric = function (metric) {
            if (metric && typeof metric.name === 'string' && typeof metric.value !== 'undefined') {
                reportMetric(metric.name, Number(metric.value), metric);
            }
        };

        window.SitePulseRUM = api;
    }
})(window, document);
