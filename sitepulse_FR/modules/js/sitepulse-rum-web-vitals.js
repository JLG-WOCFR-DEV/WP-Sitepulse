(function (window, document) {
    'use strict';

    if (!window || !document) {
        return;
    }

    var config = window.SitePulseRum || {};

    if (!config || typeof config !== 'object') {
        return;
    }

    if (!config.enabled || !config.endpoint || !config.token) {
        return;
    }

    var sampleRate = typeof config.sampleRate === 'number' ? config.sampleRate : 1;

    if (sampleRate <= 0 || Math.random() > sampleRate) {
        return;
    }

    var performance = window.performance;
    var navigator = window.navigator || {};
    var metrics = [];
    var finalized = false;
    var clsValue = 0;
    var lcpValue = null;
    var fidValue = null;
    var fidRecorded = false;
    var clsObserved = false;

    function now() {
        if (performance && typeof performance.now === 'function') {
            return performance.now();
        }

        return Date.now ? Date.now() : new Date().getTime();
    }

    function detectNavigationType() {
        if (!performance || typeof performance.getEntriesByType !== 'function') {
            return 'navigate';
        }

        var entries = performance.getEntriesByType('navigation');

        if (entries && entries.length > 0 && entries[0].type) {
            return entries[0].type;
        }

        return 'navigate';
    }

    function detectDevice() {
        if (config.deviceHint && typeof config.deviceHint === 'string') {
            return config.deviceHint;
        }

        if (window.matchMedia) {
            if (window.matchMedia('(max-width: 767px)').matches) {
                return 'mobile';
            }

            if (window.matchMedia('(max-width: 1024px)').matches) {
                return 'tablet';
            }
        }

        return 'desktop';
    }

    function sanitizeValue(value, decimals) {
        var parsed = parseFloat(value);

        if (!isFinite(parsed)) {
            return null;
        }

        if (typeof decimals === 'number') {
            var factor = Math.pow(10, decimals);
            return Math.round(parsed * factor) / factor;
        }

        return parsed;
    }

    function gradeMetric(metric, value) {
        if (metric === 'LCP') {
            if (value <= 2500) {
                return 'good';
            }

            if (value <= 4000) {
                return 'needs_improvement';
            }

            return 'poor';
        }

        if (metric === 'FID') {
            if (value <= 100) {
                return 'good';
            }

            if (value <= 300) {
                return 'needs_improvement';
            }

            return 'poor';
        }

        if (metric === 'CLS') {
            if (value <= 0.1) {
                return 'good';
            }

            if (value <= 0.25) {
                return 'needs_improvement';
            }

            return 'poor';
        }

        return 'unknown';
    }

    function recordMetric(metric, value) {
        var normalized = sanitizeValue(value, metric === 'CLS' ? 3 : 2);

        if (normalized === null) {
            return;
        }

        metrics.push({
            metric: metric,
            value: normalized,
            rating: gradeMetric(metric, normalized),
        });
    }

    function flushMetrics(reason) {
        if (finalized) {
            return;
        }

        finalized = true;

        if (lcpValue !== null) {
            recordMetric('LCP', lcpValue);
        }

        if (fidValue !== null) {
            recordMetric('FID', fidValue);
        }

        if (clsObserved) {
            recordMetric('CLS', clsValue);
        }

        if (!metrics.length) {
            return;
        }

        var payload = {
            token: config.token,
            samples: metrics.map(function (entry) {
                return {
                    metric: entry.metric,
                    value: entry.value,
                    rating: entry.rating,
                    path: window.location.pathname + (window.location.search || ''),
                    url: window.location.href,
                    device: detectDevice(),
                    navigationType: detectNavigationType(),
                    timestamp: Date.now ? Date.now() : new Date().getTime(),
                };
            }),
        };

        var body = JSON.stringify(payload);

        if (!body) {
            return;
        }

        if (navigator.sendBeacon) {
            try {
                var blob = new window.Blob([body], { type: 'application/json' });
                if (navigator.sendBeacon(config.endpoint, blob)) {
                    return;
                }
            } catch (error) {
                // Fall through to fetch.
            }
        }

        if (window.fetch) {
            try {
                window.fetch(config.endpoint, {
                    method: 'POST',
                    credentials: 'omit',
                    headers: { 'Content-Type': 'application/json' },
                    body: body,
                    keepalive: true,
                }).catch(function () {});
                return;
            } catch (error) {
                // ignore
            }
        }

        try {
            var xhr = new window.XMLHttpRequest();
            xhr.open('POST', config.endpoint, true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.send(body);
        } catch (error) {
            // give up silently
        }
    }

    function observeCLS() {
        if (!performance || typeof performance.getEntriesByType !== 'function' || typeof window.PerformanceObserver !== 'function') {
            return;
        }

        if (!window.PerformanceObserver.supportedEntryTypes || window.PerformanceObserver.supportedEntryTypes.indexOf('layout-shift') === -1) {
            return;
        }

        try {
            var observer = new window.PerformanceObserver(function (list) {
                clsObserved = true;
                list.getEntries().forEach(function (entry) {
                    if (entry && !entry.hadRecentInput) {
                        clsValue += entry.value;
                    }
                });
            });
            observer.observe({ type: 'layout-shift', buffered: true });
            clsObserved = true;
        } catch (error) {
            clsObserved = false;
        }
    }

    function observeLCP() {
        if (!performance || typeof performance.getEntriesByType !== 'function' || typeof window.PerformanceObserver !== 'function') {
            return;
        }

        if (!window.PerformanceObserver.supportedEntryTypes || window.PerformanceObserver.supportedEntryTypes.indexOf('largest-contentful-paint') === -1) {
            return;
        }

        try {
            var observer = new window.PerformanceObserver(function (list) {
                var entries = list.getEntries();
                if (!entries || !entries.length) {
                    return;
                }
                var lastEntry = entries[entries.length - 1];
                lcpValue = lastEntry.renderTime || lastEntry.loadTime || lastEntry.startTime || lastEntry.processingEnd || lastEntry.processingStart || null;
            });
            observer.observe({ type: 'largest-contentful-paint', buffered: true });
        } catch (error) {
            lcpValue = lcpValue;
        }
    }

    function observeFID() {
        if (typeof window.PerformanceObserver === 'function' && window.PerformanceObserver.supportedEntryTypes && window.PerformanceObserver.supportedEntryTypes.indexOf('first-input') !== -1) {
            try {
                var observer = new window.PerformanceObserver(function (list) {
                    var entry = list.getEntries()[0];
                    if (!entry || fidRecorded) {
                        return;
                    }

                    fidRecorded = true;
                    fidValue = entry.processingStart - entry.startTime;
                });
                observer.observe({ type: 'first-input', buffered: true });
                return;
            } catch (error) {
                // fallback to event listener
            }
        }

        var firstInput = function (event) {
            if (fidRecorded) {
                return;
            }

            fidRecorded = true;
            var eventTimestamp = event.timeStamp;
            var delay = now() - eventTimestamp;
            if (delay >= 0) {
                fidValue = delay;
            }

            removeFirstInputListeners();
        };

        function removeFirstInputListeners() {
            window.removeEventListener('pointerdown', firstInput, true);
            window.removeEventListener('keydown', firstInput, true);
            window.removeEventListener('touchstart', firstInput, true);
        }

        window.addEventListener('pointerdown', firstInput, true);
        window.addEventListener('keydown', firstInput, true);
        window.addEventListener('touchstart', firstInput, true);
    }

    function bindLifecycleEvents() {
        var hiddenHandler = function () {
            flushMetrics('hidden');
        };

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                hiddenHandler();
            }
        }, { once: true });

        window.addEventListener('pagehide', function () {
            hiddenHandler();
        }, { once: true });
    }

    observeCLS();
    observeLCP();
    observeFID();
    bindLifecycleEvents();
})(window, document);
