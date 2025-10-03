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
            var metrics = getScrollMetrics();
            var tolerance = 2;

            if (metrics.maxScroll <= tolerance) {
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

        prevButton.addEventListener('click', function () {
            scrollByAmount(-1);
        });

        nextButton.addEventListener('click', function () {
            scrollByAmount(1);
        });

        viewport.addEventListener('scroll', updateButtons, { passive: true });
        window.addEventListener('resize', updateButtons);

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
