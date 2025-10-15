(function () {
    'use strict';

    var prefersReducedMotion = false;

    if (window.matchMedia) {
        var mediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
        prefersReducedMotion = mediaQuery.matches;

        if (typeof mediaQuery.addEventListener === 'function') {
            mediaQuery.addEventListener('change', function (event) {
                prefersReducedMotion = event.matches;
            });
        } else if (typeof mediaQuery.addListener === 'function') {
            mediaQuery.addListener(function (event) {
                prefersReducedMotion = event.matches;
            });
        }
    }

    var raf = typeof window.requestAnimationFrame === 'function'
        ? window.requestAnimationFrame.bind(window)
        : function (callback) {
            window.setTimeout(callback, 16);
        };

    var STORAGE_KEY = 'sitepulseModuleNavSearch';

    var isString = function (value) {
        return typeof value === 'string' || value instanceof String;
    };

    var readStorage = function () {
        if (!('localStorage' in window)) {
            return '';
        }

        try {
            var stored = window.localStorage.getItem(STORAGE_KEY);

            return isString(stored) ? stored : '';
        } catch (error) {
            return '';
        }
    };

    var writeStorage = function (value) {
        if (!('localStorage' in window)) {
            return;
        }

        try {
            if (value) {
                window.localStorage.setItem(STORAGE_KEY, value);
            } else {
                window.localStorage.removeItem(STORAGE_KEY);
            }
        } catch (error) {
            // Gracefully ignore storage issues (quota, private mode, etc.).
        }
    };

    var sprintfFn = null;

    if (window.wp && window.wp.i18n && typeof window.wp.i18n.sprintf === 'function') {
        sprintfFn = window.wp.i18n.sprintf;
    }

    var formatCount = function (template, value) {
        if (!template) {
            return '';
        }

        var count = Number(value);

        if (!Number.isFinite(count)) {
            count = value;
        }

        if (sprintfFn) {
            try {
                return sprintfFn(template, count);
            } catch (error) {
                // Fallback to manual replacement below.
            }
        }

        return template.replace(/%(\d+\$)?[ds]/g, String(count));
    };

    var initNavigation = function (nav) {
        if (!nav) {
            return;
        }

        nav.classList.add('sitepulse-module-nav--js');

        var form = nav.querySelector('.sitepulse-module-nav__mobile-form');
        var select = nav.querySelector('[data-sitepulse-nav-select]');

        if (form && select) {
            select.addEventListener('change', function () {
                if (select.value) {
                    form.submit();
                }
            });
        }

        var viewport = nav.querySelector('[data-sitepulse-nav-viewport]');
        var prevButton = nav.querySelector('[data-sitepulse-nav-scroll="prev"]');
        var nextButton = nav.querySelector('[data-sitepulse-nav-scroll="next"]');
        var searchInput = nav.querySelector('[data-sitepulse-nav-search]');
        var clearButton = nav.querySelector('[data-sitepulse-nav-clear]');
        var resultsDisplay = nav.querySelector('[data-sitepulse-nav-results]');
        var emptyMessage = nav.querySelector('[data-sitepulse-nav-empty]');

        var getItems = function () {
            return nav.querySelectorAll('[data-sitepulse-nav-item]');
        };

        var toggleClearButton = function (query) {
            if (!clearButton) {
                return;
            }

            var hasValue = query && query.length > 0;
            clearButton.hidden = !hasValue;
        };

        var updateResultsDisplay = function (visibleCount) {
            if (!resultsDisplay) {
                return;
            }

            var plural = resultsDisplay.getAttribute('data-plural') || '';
            var singular = resultsDisplay.getAttribute('data-singular') || '';
            var empty = resultsDisplay.getAttribute('data-empty') || '';
            var text = '';

            if (visibleCount <= 0) {
                text = empty;
            } else if (visibleCount === 1 && singular) {
                text = singular;
            } else if (plural) {
                text = formatCount(plural, visibleCount);
            } else {
                text = String(visibleCount);
            }

            resultsDisplay.textContent = text;
        };

        if (!viewport || !prevButton || !nextButton) {
            return;
        }

        var getScrollMetrics = function () {
            var maxScroll = Math.max(viewport.scrollWidth - viewport.clientWidth, 0);

            return {
                maxScroll: maxScroll,
                position: viewport.scrollLeft
            };
        };

        var updateButtons = function () {
            var items = getItems();
            var visibleCount = 0;

            Array.prototype.forEach.call(items, function (item) {
                if (!item.hasAttribute('hidden')) {
                    visibleCount++;
                }
            });

            var metrics = getScrollMetrics();
            var tolerance = 2;

            if (metrics.maxScroll <= tolerance || visibleCount <= 1) {
                nav.classList.remove('sitepulse-module-nav--scrollable');
                prevButton.disabled = true;
                nextButton.disabled = true;
                return;
            }

            nav.classList.add('sitepulse-module-nav--scrollable');

            prevButton.disabled = metrics.position <= tolerance;
            nextButton.disabled = metrics.position >= (metrics.maxScroll - tolerance);
        };

        var scrollByAmount = function (direction) {
            var amount = viewport.clientWidth * 0.8 * direction;

            viewport.scrollBy({
                left: amount,
                behavior: prefersReducedMotion ? 'auto' : 'smooth'
            });
        };

        var filterItems = function (query) {
            var value = isString(query) ? query.trim().toLowerCase() : '';
            var items = getItems();
            var matches = 0;

            Array.prototype.forEach.call(items, function (item) {
                var filterText = item.getAttribute('data-filter-text') || '';
                var isMatch = value === '' || filterText.indexOf(value) !== -1;
                var link = item.querySelector('a');

                if (isMatch) {
                    item.hidden = false;
                    item.classList.remove('is-filtered-out');

                    if (link) {
                        link.removeAttribute('tabindex');
                    }

                    matches++;
                } else {
                    item.hidden = true;
                    item.classList.add('is-filtered-out');

                    if (link) {
                        link.setAttribute('tabindex', '-1');
                    }
                }
            });

            var groups = nav.querySelectorAll('.sitepulse-module-nav__group');

            Array.prototype.forEach.call(groups, function (group) {
                var groupItems = group.querySelectorAll('[data-sitepulse-nav-item]');
                var hasVisible = false;

                Array.prototype.forEach.call(groupItems, function (groupItem) {
                    if (!groupItem.hasAttribute('hidden')) {
                        hasVisible = true;
                    }
                });

                group.hidden = !hasVisible;
            });

            if (emptyMessage) {
                var hasMatches = matches > 0;
                emptyMessage.hidden = hasMatches;
                emptyMessage.setAttribute('aria-hidden', hasMatches ? 'true' : 'false');
            }

            nav.classList.toggle('sitepulse-module-nav--filtering', value !== '');
            updateResultsDisplay(matches);
            toggleClearButton(value);

            if (matches > 0) {
                if (prefersReducedMotion) {
                    viewport.scrollLeft = 0;
                } else {
                    viewport.scrollTo({
                        left: 0,
                        behavior: 'smooth'
                    });
                }
            }

            raf(updateButtons);
        };

        prevButton.addEventListener('click', function () {
            scrollByAmount(-1);
        });

        nextButton.addEventListener('click', function () {
            scrollByAmount(1);
        });

        viewport.addEventListener('scroll', updateButtons, { passive: true });
        window.addEventListener('resize', updateButtons);

        if (searchInput) {
            var applyStoredSearch = function () {
                var storedValue = readStorage();

                if (storedValue) {
                    searchInput.value = storedValue;
                    filterItems(storedValue);
                } else {
                    filterItems('');
                }

                toggleClearButton(searchInput.value || '');
            };

            searchInput.addEventListener('input', function () {
                var currentValue = searchInput.value || '';
                writeStorage(currentValue);
                filterItems(currentValue);
            });

            searchInput.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    searchInput.value = '';
                    writeStorage('');
                    filterItems('');
                    toggleClearButton('');
                }
            });

            applyStoredSearch();
        } else {
            filterItems('');
        }

        if (clearButton && searchInput) {
            clearButton.addEventListener('click', function () {
                searchInput.value = '';
                writeStorage('');
                filterItems('');
                toggleClearButton('');
                searchInput.focus();
            });
        }

        // Ensure initial state after layout.
        raf(updateButtons);
    };

    document.addEventListener('DOMContentLoaded', function () {
        var navigations = document.querySelectorAll('.sitepulse-module-nav');

        navigations.forEach(function (nav) {
            initNavigation(nav);
        });
    });
})();
