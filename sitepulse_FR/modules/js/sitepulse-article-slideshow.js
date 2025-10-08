(function (window, document) {
    'use strict';

    if (window.sitepulseArticleSlideshowInitialized) {
        return;
    }

    window.sitepulseArticleSlideshowInitialized = true;

    var config = window.sitepulseSlideshow || {};
    var strings = config.strings && typeof config.strings === 'object' ? config.strings : {};
    var debugEnabled = !!config.debug;

    var selectors = [];

    if (config.selectors && typeof config.selectors.length === 'number') {
        for (var i = 0; i < config.selectors.length; i++) {
            var selectorCandidate = config.selectors[i];

            if (typeof selectorCandidate !== 'string') {
                continue;
            }

            selectorCandidate = selectorCandidate.trim();

            if (selectorCandidate !== '') {
                selectors.push(selectorCandidate);
            }
        }
    }

    if (!selectors.length) {
        selectors = ['.sitepulse-article', '.entry-content', '.wp-block-post-content', '.post', '.hentry', 'article'];
    }

    var allowedExtensions = {
        jpg: true,
        jpeg: true,
        png: true,
        gif: true,
        webp: true,
        bmp: true,
        svg: true,
        avif: true,
        heic: true,
        heif: true,
        jfif: true,
        tif: true,
        tiff: true
    };

    var overlay = null;
    var dialog = null;
    var imageWrapper = null;
    var imageElement = null;
    var captionElement = null;
    var counterElement = null;
    var prevButton = null;
    var nextButton = null;
    var closeButton = null;
    var loaderElement = null;
    var debugPanel = null;
    var debugPanelMessage = null;
    var debugPanelList = null;
    var debugPanelSelectors = null;
    var debugBadge = null;

    var activeItems = [];
    var currentIndex = -1;
    var focusReturnTarget = null;
    var focusableElements = [];
    var trackedContainers = [];

    function isArray(value) {
        return Object.prototype.toString.call(value) === '[object Array]';
    }

    function logDebug(message, context) {
        if (!debugEnabled) {
            return;
        }

        if (window.console && typeof window.console.info === 'function') {
            if (typeof context !== 'undefined') {
                window.console.info('[SitePulse slideshow] ' + message, context);
            } else {
                window.console.info('[SitePulse slideshow] ' + message);
            }
        }
    }

    function matches(element, selector) {
        if (!element || element.nodeType !== 1) {
            return false;
        }

        var proto = element.matches || element.msMatchesSelector || element.webkitMatchesSelector;

        if (!proto) {
            return false;
        }

        try {
            return proto.call(element, selector);
        } catch (error) {
            logDebug('Invalid selector provided to matches()', { selector: selector, error: error });
        }

        return false;
    }

    function closest(element, selector) {
        if (!element || element.nodeType !== 1) {
            return null;
        }

        if (typeof element.closest === 'function') {
            try {
                return element.closest(selector);
            } catch (error) {
                logDebug('Invalid selector provided to closest()', { selector: selector, error: error });
            }
        }

        var current = element;

        while (current && current.nodeType === 1) {
            if (matches(current, selector)) {
                return current;
            }

            current = current.parentElement;
        }

        return null;
    }

    function sanitizeString(value) {
        if (typeof value !== 'string') {
            return '';
        }

        return value.replace(/\s+/g, ' ').trim();
    }

    function normalizeUrl(value) {
        if (typeof value !== 'string') {
            return '';
        }

        return value.trim();
    }

    function shouldHandleAnchor(anchor, image) {
        if (!anchor || anchor.nodeType !== 1) {
            return false;
        }

        if (anchor.getAttribute('data-sitepulse-slideshow') === 'ignore') {
            return false;
        }

        var href = anchor.getAttribute('href');

        if (!href) {
            return false;
        }

        var normalized = normalizeUrl(href).split('#')[0].split('?')[0];

        if (normalized.indexOf('data:image') === 0) {
            return true;
        }

        var dotIndex = normalized.lastIndexOf('.');

        if (dotIndex === -1) {
            return false;
        }

        var extension = normalized.substring(dotIndex + 1).toLowerCase();

        if (!allowedExtensions[extension]) {
            return false;
        }

        if (!image) {
            return true;
        }

        var img = image;

        if (img.nodeType !== 1 || img.tagName.toLowerCase() !== 'img') {
            return true;
        }

        return true;
    }

    function shouldHandleImage(image) {
        if (!image || image.nodeType !== 1) {
            return false;
        }

        var source = image.currentSrc || image.src || '';

        if (!source) {
            return false;
        }

        if (source.indexOf('data:image') === 0) {
            return true;
        }

        var normalized = normalizeUrl(source).split('#')[0].split('?')[0];
        var dotIndex = normalized.lastIndexOf('.');

        if (dotIndex === -1) {
            return false;
        }

        var extension = normalized.substring(dotIndex + 1).toLowerCase();

        return !!allowedExtensions[extension];
    }

    function markImageClickable(image) {
        if (!image || image.nodeType !== 1) {
            return;
        }

        if (image.classList) {
            image.classList.add('sitepulse-slideshow__clickable');
        } else if (typeof image.className === 'string' && image.className.indexOf('sitepulse-slideshow__clickable') === -1) {
            image.className += ' sitepulse-slideshow__clickable';
        }
    }

    function findIndex(collection, predicate) {
        if (!collection || !collection.length) {
            return -1;
        }

        for (var index = 0; index < collection.length; index++) {
            if (predicate(collection[index], index)) {
                return index;
            }
        }

        return -1;
    }

    function isPrimaryClick(event) {
        if (!event) {
            return false;
        }

        if (event.defaultPrevented) {
            return false;
        }

        if (event.button && event.button !== 0) {
            return false;
        }

        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return false;
        }

        return true;
    }

    function toArray(nodeList) {
        var result = [];

        if (!nodeList) {
            return result;
        }

        for (var i = 0; i < nodeList.length; i++) {
            result.push(nodeList[i]);
        }

        return result;
    }

    function collectGalleryTriggers(container) {
        var triggers = [];

        if (!container || container.nodeType !== 1) {
            return triggers;
        }

        var images = container.querySelectorAll('img');

        for (var i = 0; i < images.length; i++) {
            var image = images[i];
            var anchor = closest(image, 'a[href]');

            if (anchor && shouldHandleAnchor(anchor, image)) {
                triggers.push({ trigger: anchor, anchor: anchor, image: image });
                markImageClickable(image);
                continue;
            }

            if (!anchor && shouldHandleImage(image)) {
                triggers.push({ trigger: image, anchor: null, image: image });
                markImageClickable(image);
            }
        }

        return triggers;
    }

    function collectGalleryItems(container) {
        var items = [];

        if (!container || container.nodeType !== 1) {
            return items;
        }

        var images = container.querySelectorAll('img');

        for (var i = 0; i < images.length; i++) {
            var image = images[i];
            var anchor = closest(image, 'a[href]');
            var hasAnchor = anchor && shouldHandleAnchor(anchor, image);
            var source = '';

            if (hasAnchor) {
                source = normalizeUrl(anchor.getAttribute('href'));
            } else if (!anchor && shouldHandleImage(image)) {
                source = normalizeUrl(image.currentSrc || image.src || '');
            } else {
                continue;
            }

            if (!source) {
                continue;
            }

            var altText = sanitizeString(image.getAttribute('alt') || '');
            var captionText = '';
            var figure = closest(image, 'figure');

            if (figure) {
                var captionElement = figure.querySelector('figcaption');

                if (captionElement) {
                    captionText = sanitizeString(captionElement.textContent || '');
                }
            }

            var titleText = '';

            if (hasAnchor) {
                titleText = sanitizeString(anchor.getAttribute('data-caption') || anchor.getAttribute('title') || '');
            } else {
                titleText = sanitizeString(image.getAttribute('title') || '');
            }

            var label = captionText || titleText || altText;

            items.push({
                trigger: hasAnchor ? anchor : image,
                anchor: hasAnchor ? anchor : null,
                image: image,
                src: source,
                alt: altText,
                caption: captionText,
                title: label
            });
        }

        return items;
    }

    function ensureOverlay() {
        if (overlay) {
            return;
        }

        overlay = document.createElement('div');
        overlay.className = 'sitepulse-slideshow';
        overlay.setAttribute('aria-hidden', 'true');

        dialog = document.createElement('div');
        dialog.className = 'sitepulse-slideshow__dialog';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-label', strings.ariaLabel || 'Visionneuse d\'images');

        closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'sitepulse-slideshow__close';
        closeButton.setAttribute('aria-label', strings.close || 'Fermer le diaporama');
        closeButton.appendChild(document.createTextNode('\u00d7'));
        dialog.appendChild(closeButton);

        imageWrapper = document.createElement('div');
        imageWrapper.className = 'sitepulse-slideshow__image-wrapper';

        loaderElement = document.createElement('div');
        loaderElement.className = 'sitepulse-slideshow__loader';
        imageWrapper.appendChild(loaderElement);

        imageElement = document.createElement('img');
        imageElement.className = 'sitepulse-slideshow__image';
        imageElement.setAttribute('alt', '');
        imageElement.setAttribute('decoding', 'async');
        imageElement.setAttribute('loading', 'lazy');
        imageWrapper.appendChild(imageElement);

        var controls = document.createElement('div');
        controls.className = 'sitepulse-slideshow__controls';

        prevButton = document.createElement('button');
        prevButton.type = 'button';
        prevButton.className = 'sitepulse-slideshow__button sitepulse-slideshow__button--prev';
        prevButton.setAttribute('aria-label', strings.previous || 'Image précédente');
        prevButton.appendChild(document.createTextNode('\u2039'));
        controls.appendChild(prevButton);

        nextButton = document.createElement('button');
        nextButton.type = 'button';
        nextButton.className = 'sitepulse-slideshow__button sitepulse-slideshow__button--next';
        nextButton.setAttribute('aria-label', strings.next || 'Image suivante');
        nextButton.appendChild(document.createTextNode('\u203a'));
        controls.appendChild(nextButton);

        imageWrapper.appendChild(controls);
        dialog.appendChild(imageWrapper);

        var meta = document.createElement('div');
        meta.className = 'sitepulse-slideshow__meta';

        counterElement = document.createElement('p');
        counterElement.className = 'sitepulse-slideshow__counter';
        counterElement.textContent = '';
        meta.appendChild(counterElement);

        captionElement = document.createElement('p');
        captionElement.className = 'sitepulse-slideshow__caption';
        captionElement.textContent = '';
        meta.appendChild(captionElement);

        dialog.appendChild(meta);

        if (debugEnabled) {
            debugPanel = document.createElement('section');
            debugPanel.className = 'sitepulse-slideshow__debug';
            debugPanel.setAttribute('aria-live', 'polite');

            var debugHeading = document.createElement('h3');
            debugHeading.className = 'sitepulse-slideshow__debug-heading';
            debugHeading.textContent = strings.debugTitle || 'Mode debug du diaporama';
            debugPanel.appendChild(debugHeading);

            debugPanelSelectors = document.createElement('p');
            debugPanelSelectors.className = 'sitepulse-slideshow__debug-selectors';
            debugPanelSelectors.textContent = (strings.debugSelectors || 'Sélecteurs analysés') + ': ' + selectors.join(', ');
            debugPanel.appendChild(debugPanelSelectors);

            debugPanelMessage = document.createElement('p');
            debugPanelMessage.className = 'sitepulse-slideshow__debug-message';
            debugPanel.appendChild(debugPanelMessage);

            debugPanelList = document.createElement('dl');
            debugPanelList.className = 'sitepulse-slideshow__debug-list';
            debugPanel.appendChild(debugPanelList);

            dialog.appendChild(debugPanel);
        }

        overlay.appendChild(dialog);
        document.body.appendChild(overlay);

        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) {
                closeSlideshow();
            }
        });

        closeButton.addEventListener('click', function () {
            closeSlideshow();
        });

        prevButton.addEventListener('click', function () {
            showPrevious();
        });

        nextButton.addEventListener('click', function () {
            showNext();
        });
    }

    function refreshFocusableElements() {
        if (!overlay) {
            return;
        }

        var nodes = overlay.querySelectorAll('button:not([disabled])');
        focusableElements = toArray(nodes);
    }

    function updateDebugPanel(items, index) {
        if (!debugEnabled || !debugPanel || !debugPanelList || !debugPanelMessage) {
            return;
        }

        while (debugPanelList.firstChild) {
            debugPanelList.removeChild(debugPanelList.firstChild);
        }

        if (!items.length) {
            debugPanelMessage.textContent = strings.debugNoContainers || 'Aucune image à afficher.';
            return;
        }

        debugPanelMessage.textContent = '';

        appendDebugRow(strings.debugTotal || 'Total', String(items.length));
        appendDebugRow(strings.debugIndex || 'Index courant', String(index + 1) + ' / ' + items.length);

        var item = items[index];
        var sourceLabel = strings.debugImage || 'Image active';
        appendDebugRow(sourceLabel, item.src || '');

        if (item.alt) {
            appendDebugRow('Alt', item.alt);
        }

        if (item.caption) {
            appendDebugRow('Caption', item.caption);
        }
    }

    function appendDebugRow(label, value) {
        if (!debugPanelList) {
            return;
        }

        var term = document.createElement('dt');
        term.textContent = label;
        debugPanelList.appendChild(term);

        var description = document.createElement('dd');
        description.textContent = value;
        debugPanelList.appendChild(description);
    }

    function ensureDebugBadge() {
        if (!debugEnabled) {
            return null;
        }

        if (debugBadge) {
            return debugBadge;
        }

        debugBadge = document.createElement('div');
        debugBadge.className = 'sitepulse-slideshow__debug-status';
        debugBadge.setAttribute('role', 'status');
        debugBadge.textContent = strings.debugTitle || 'Mode debug du diaporama';
        document.body.appendChild(debugBadge);

        return debugBadge;
    }

    function updateDebugBadge() {
        if (!debugEnabled) {
            return;
        }

        var badge = ensureDebugBadge();

        if (!badge) {
            return;
        }

        var containerCount = 0;
        var imageCount = 0;

        for (var i = 0; i < trackedContainers.length; i++) {
            var container = trackedContainers[i];

            if (!container || container.nodeType !== 1) {
                continue;
            }

            containerCount++;

            var countAttr = container.getAttribute('data-sitepulse-slideshow-count');
            var parsed = countAttr ? parseInt(countAttr, 10) : NaN;

            if (!isNaN(parsed)) {
                imageCount += parsed;
            }
        }

        if (containerCount === 0) {
            badge.textContent = (strings.debugTitle || 'Mode debug du diaporama') + ' — ' + (strings.debugNoContainers || 'Aucun conteneur détecté');
        } else {
            badge.textContent = (strings.debugTitle || 'Mode debug du diaporama') + ' — ' + imageCount + ' / ' + containerCount;
        }

        badge.classList.add('is-visible');
    }

    function preloadImage(source) {
        if (!source) {
            return;
        }

        var img = new window.Image();
        img.src = source;
    }

    function preloadAround(index) {
        if (!activeItems || activeItems.length <= 1) {
            return;
        }

        var length = activeItems.length;
        var nextIndex = (index + 1) % length;
        var prevIndex = (index - 1 + length) % length;

        preloadImage(activeItems[nextIndex].src);

        if (length > 2) {
            preloadImage(activeItems[prevIndex].src);
        }
    }

    function updateSlide() {
        if (!overlay || !overlay.classList.contains('is-active')) {
            return;
        }

        if (!activeItems.length) {
            captionElement.textContent = strings.empty || '';
            counterElement.textContent = '';
            return;
        }

        if (currentIndex < 0 || currentIndex >= activeItems.length) {
            currentIndex = 0;
        }

        var item = activeItems[currentIndex];

        overlay.classList.add('is-loading');

        imageElement.onload = function () {
            overlay.classList.remove('is-loading');
        };

        imageElement.onerror = function () {
            overlay.classList.remove('is-loading');
            if (strings.missingImage) {
                captionElement.textContent = strings.missingImage;
            }
        };

        imageElement.setAttribute('src', item.src);
        imageElement.setAttribute('alt', item.alt || item.caption || strings.missingImage || '');

        captionElement.textContent = item.caption || item.alt || '';

        var counterTemplate = strings.counter || '%1$d / %2$d';
        counterElement.textContent = counterTemplate.replace('%1$d', String(currentIndex + 1)).replace('%2$d', String(activeItems.length));

        var disableNavigation = activeItems.length <= 1;
        prevButton.disabled = disableNavigation;
        nextButton.disabled = disableNavigation;

        updateDebugPanel(activeItems, currentIndex);
        preloadAround(currentIndex);
    }

    function showNext() {
        if (!activeItems.length) {
            return;
        }

        currentIndex = (currentIndex + 1) % activeItems.length;
        updateSlide();
    }

    function showPrevious() {
        if (!activeItems.length) {
            return;
        }

        currentIndex = (currentIndex - 1 + activeItems.length) % activeItems.length;
        updateSlide();
    }

    function closeSlideshow() {
        if (!overlay || !overlay.classList.contains('is-active')) {
            return;
        }

        overlay.classList.remove('is-active');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('sitepulse-slideshow--open');
        window.removeEventListener('keydown', handleKeydown, true);

        if (focusReturnTarget && typeof focusReturnTarget.focus === 'function') {
            try {
                focusReturnTarget.focus();
            } catch (error) {
                logDebug('Unable to restore focus', error);
            }
        }

        focusReturnTarget = null;
    }

    function trapFocus(event) {
        if (!focusableElements.length) {
            return;
        }

        var first = focusableElements[0];
        var last = focusableElements[focusableElements.length - 1];
        var active = document.activeElement;

        if (event.shiftKey) {
            if (active === first || !overlay.contains(active)) {
                event.preventDefault();
                last.focus();
            }
        } else if (active === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function handleKeydown(event) {
        if (!overlay || !overlay.classList.contains('is-active')) {
            return;
        }

        var key = event.key || event.keyCode;

        if (key === 'Escape' || key === 'Esc' || key === 27) {
            event.preventDefault();
            closeSlideshow();
            return;
        }

        if (key === 'ArrowRight' || key === 'Right' || key === 39) {
            event.preventDefault();
            showNext();
            return;
        }

        if (key === 'ArrowLeft' || key === 'Left' || key === 37) {
            event.preventDefault();
            showPrevious();
            return;
        }

        if (key === 'Tab' || key === 9) {
            trapFocus(event);
        }
    }

    function openSlideshow(items, startIndex) {
        if (!items || !items.length) {
            return;
        }

        ensureOverlay();

        activeItems = isArray(items) ? items.slice() : toArray(items);
        currentIndex = startIndex >= 0 ? startIndex : 0;

        focusReturnTarget = document.activeElement;

        overlay.setAttribute('aria-hidden', 'false');
        overlay.classList.add('is-active');
        document.body.classList.add('sitepulse-slideshow--open');

        refreshFocusableElements();

        if (closeButton) {
            try {
                closeButton.focus();
            } catch (error) {
                logDebug('Unable to focus close button', error);
            }
        }

        updateSlide();
        window.addEventListener('keydown', handleKeydown, true);
    }

    function openFromTrigger(container, trigger) {
        var items = collectGalleryItems(container);

        if (!items.length) {
            logDebug('Aucun élément de diaporama détecté pour ce conteneur.', container);
            return;
        }

        var index = findIndex(items, function (item) {
            return item.trigger === trigger;
        });

        if (index === -1) {
            index = 0;
        }

        openSlideshow(items, index);
    }

    function bindContainer(container) {
        if (!container || container.nodeType !== 1) {
            return;
        }

        if (trackedContainers.indexOf(container) === -1) {
            trackedContainers.push(container);
        }

        container.setAttribute('data-sitepulse-slideshow-container', 'true');

        var triggers = collectGalleryTriggers(container);
        container.setAttribute('data-sitepulse-slideshow-count', String(triggers.length));

        for (var i = 0; i < triggers.length; i++) {
            var trigger = triggers[i];
            var element = trigger.trigger;

            if (!element || element.nodeType !== 1) {
                continue;
            }

            if (element.getAttribute('data-sitepulse-slideshow-bound') === '1') {
                continue;
            }

            element.setAttribute('data-sitepulse-slideshow-bound', '1');

            element.addEventListener('click', function (event) {
                if (!isPrimaryClick(event)) {
                    return;
                }

                event.preventDefault();
                openFromTrigger(container, this);
            });
        }

        updateDebugBadge();

        if (typeof MutationObserver !== 'undefined' && !container.sitepulseSlideshowObserver) {
            try {
                var observer = new MutationObserver(function (mutations) {
                    var needsRefresh = false;

                    for (var index = 0; index < mutations.length; index++) {
                        var mutation = mutations[index];

                        if (mutation.addedNodes && mutation.addedNodes.length) {
                            needsRefresh = true;
                            break;
                        }
                    }

                    if (needsRefresh) {
                        bindContainer(container);
                    }
                });

                observer.observe(container, { childList: true, subtree: true });
                container.sitepulseSlideshowObserver = observer;
            } catch (error) {
                logDebug('Impossible d\'observer les mutations du conteneur.', error);
            }
        }
    }

    function setupContainers() {
        var matchedContainers = [];

        for (var i = 0; i < selectors.length; i++) {
            var selector = selectors[i];

            try {
                var found = document.querySelectorAll(selector);
                matchedContainers = matchedContainers.concat(toArray(found));
            } catch (error) {
                logDebug('Sélecteur de diaporama invalide.', { selector: selector, error: error });
            }
        }

        if (!matchedContainers.length) {
            trackedContainers = [];
            updateDebugBadge();
            return;
        }

        var uniqueContainers = [];

        for (var j = 0; j < matchedContainers.length; j++) {
            var candidate = matchedContainers[j];

            if (uniqueContainers.indexOf(candidate) === -1) {
                uniqueContainers.push(candidate);
            }
        }

        for (var k = 0; k < uniqueContainers.length; k++) {
            bindContainer(uniqueContainers[k]);
        }
    }

    function boot() {
        setupContainers();
        updateDebugBadge();

        if (debugEnabled) {
            window.sitepulseSlideshowDebug = {
                refresh: setupContainers,
                selectors: selectors.slice(),
                version: '1.0.0'
            };

            logDebug('Initialisation du diaporama avec ' + selectors.length + ' sélecteurs.', selectors);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.addEventListener('load', function () {
        setupContainers();
    });
})(window, document);
