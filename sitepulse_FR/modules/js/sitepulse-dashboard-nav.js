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

    var SEARCH_STORAGE_KEY = 'sitepulseModuleNavSearch';
    var CATEGORY_STORAGE_KEY = 'sitepulseModuleNavCategory';

    var isString = function (value) {
        return typeof value === 'string' || value instanceof String;
    };

    var readFromStorage = function (key) {
        if (!('localStorage' in window)) {
            return '';
        }

        try {
            var stored = window.localStorage.getItem(key);

            return isString(stored) ? stored : '';
        } catch (error) {
            return '';
        }
    };

    var writeToStorage = function (key, value) {
        if (!('localStorage' in window)) {
            return;
        }

        try {
            if (value) {
                window.localStorage.setItem(key, value);
            } else {
                window.localStorage.removeItem(key);
            }
        } catch (error) {
            // Gracefully ignore storage issues (quota, private mode, etc.).
        }
    };

    var readSearchStorage = function () {
        return readFromStorage(SEARCH_STORAGE_KEY);
    };

    var writeSearchStorage = function (value) {
        writeToStorage(SEARCH_STORAGE_KEY, value);
    };

    var readCategoryStorage = function () {
        return readFromStorage(CATEGORY_STORAGE_KEY);
    };

    var writeCategoryStorage = function (value) {
        writeToStorage(CATEGORY_STORAGE_KEY, value);
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
        var categoryButtons = nav.querySelectorAll('[data-sitepulse-nav-category]');
        var currentQuery = '';
        var activeCategory = 'all';

        var normalizeCategory = function (value) {
            if (!isString(value) || value === '') {
                return 'all';
            }

            return value;
        };

        var escapeAttributeValue = function (value) {
            if (!isString(value)) {
                return '';
            }

            return value.replace(/"/g, '\"');
        };

        var getCategoryButton = function (slug) {
            var normalized = normalizeCategory(slug);

            return nav.querySelector('[data-sitepulse-nav-category="' + escapeAttributeValue(normalized) + '"]');
        };

        var updateCategoryButtons = function () {
            if (!categoryButtons || categoryButtons.length === 0) {
                return;
            }

            Array.prototype.forEach.call(categoryButtons, function (button) {
                var buttonCategory = button.getAttribute('data-sitepulse-nav-category') || 'all';
                var isActive = buttonCategory === activeCategory;

                button.classList.toggle('is-active', isActive);
                button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
        };

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

        var filterItems = function () {
            var value = isString(currentQuery) ? currentQuery.trim().toLowerCase() : '';
            var normalizedCategory = isString(activeCategory) && activeCategory !== 'all' ? activeCategory : '';
            var items = getItems();
            var matches = 0;

            Array.prototype.forEach.call(items, function (item) {
                var filterText = item.getAttribute('data-filter-text') || '';
                var itemCategory = item.getAttribute('data-category') || '';
                var matchesCategory = !normalizedCategory || itemCategory === normalizedCategory;
                var matchesQuery = value === '' || filterText.indexOf(value) !== -1;
                var isMatch = matchesCategory && matchesQuery;
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
                var groupCategory = group.getAttribute('data-sitepulse-nav-group') || '';
                var categoryAllowsGroup = !normalizedCategory || groupCategory === normalizedCategory;
                var hasVisible = false;

                Array.prototype.forEach.call(groupItems, function (groupItem) {
                    if (!groupItem.hasAttribute('hidden')) {
                        hasVisible = true;
                    }
                });

                group.hidden = !categoryAllowsGroup || !hasVisible;
            });

            if (emptyMessage) {
                var hasMatches = matches > 0;
                emptyMessage.hidden = hasMatches;
                emptyMessage.setAttribute('aria-hidden', hasMatches ? 'true' : 'false');
            }

            nav.classList.toggle('sitepulse-module-nav--filtering', value !== '');
            nav.classList.toggle('sitepulse-module-nav--category-filtering', normalizedCategory !== '');
            nav.setAttribute('data-sitepulse-nav-active-category', normalizedCategory || 'all');
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

        var setActiveCategory = function (slug, options) {
            if (!categoryButtons || categoryButtons.length === 0) {
                activeCategory = 'all';
                nav.setAttribute('data-sitepulse-nav-active-category', 'all');
                return;
            }

            var normalized = normalizeCategory(slug);

            if (!getCategoryButton(normalized)) {
                normalized = 'all';
            }

            var suppressFilter = options && options.suppressFilter;
            var skipStorage = options && options.skipStorage;

            if (activeCategory === normalized) {
                if (!suppressFilter) {
                    filterItems();
                }

                return;
            }

            activeCategory = normalized;
            nav.setAttribute('data-sitepulse-nav-active-category', activeCategory);
            updateCategoryButtons();

            if (!skipStorage) {
                writeCategoryStorage(activeCategory);
            }

            if (!suppressFilter) {
                filterItems();
            }
        };

        var initializeCategory = function () {
            if (!categoryButtons || categoryButtons.length === 0) {
                activeCategory = 'all';
                nav.setAttribute('data-sitepulse-nav-active-category', 'all');
                return;
            }

            var defaultCategory = nav.getAttribute('data-sitepulse-nav-default-category') || 'all';
            var storedCategory = readCategoryStorage();
            var candidate = storedCategory && getCategoryButton(storedCategory) ? storedCategory : defaultCategory;

            if (!getCategoryButton(candidate)) {
                candidate = 'all';
            }

            setActiveCategory(candidate, { skipStorage: true, suppressFilter: true });
        };

        if (categoryButtons && categoryButtons.length > 0) {
            Array.prototype.forEach.call(categoryButtons, function (button) {
                button.addEventListener('click', function () {
                    var slug = button.getAttribute('data-sitepulse-nav-category') || 'all';
                    setActiveCategory(slug);
                });
            });
        } else {
            nav.setAttribute('data-sitepulse-nav-active-category', 'all');
        }

        initializeCategory();

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
                var storedValue = readSearchStorage();

                if (storedValue) {
                    searchInput.value = storedValue;
                } else {
                    searchInput.value = '';
                }

                currentQuery = searchInput.value || '';
                filterItems();
                toggleClearButton(currentQuery);
            };

            searchInput.addEventListener('input', function () {
                currentQuery = searchInput.value || '';
                writeSearchStorage(currentQuery);
                filterItems();
            });

            searchInput.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    searchInput.value = '';
                    currentQuery = '';
                    writeSearchStorage('');
                    filterItems();
                    toggleClearButton(currentQuery);
                }
            });

            applyStoredSearch();
        } else {
            filterItems();
            toggleClearButton(currentQuery);
        }

        if (clearButton && searchInput) {
            clearButton.addEventListener('click', function () {
                searchInput.value = '';
                currentQuery = '';
                writeSearchStorage('');
                filterItems();
                toggleClearButton(currentQuery);
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
