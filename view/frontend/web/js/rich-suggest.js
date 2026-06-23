define([
    'jquery',
    'mage/url',
    'mage/translate'
], function ($, urlBuilder, $t) {
    'use strict';

    function debounce(callback, delay) {
        var timeout = null;

        return function () {
            var args = arguments,
                context = this;

            clearTimeout(timeout);
            timeout = setTimeout(function () {
                callback.apply(context, args);
            }, delay);
        };
    }

    function escapeHtml(value) {
        return $('<div/>').text(value || '').html();
    }

    return function (config) {
        var input = $(config.searchInput || '#search'),
            form = $(config.form || '#search_mini_form'),
            nativeAutocomplete = $(config.nativeAutocomplete || '#search_autocomplete'),
            endpoint = config.endpoint || urlBuilder.build('vectorsearch/ajax/suggest'),
            minLength = parseInt(config.minLength || 2, 10),
            suggestionDelay = parseInt(config.suggestionDelay || 3000, 10),
            labels = config.labels || {},
            request = null,
            activeIndex = -1,
            responseCache = {},
            responseCacheLifetime = parseInt(config.cacheLifetime || 300000, 10),
            panel;

        if (!input.length || input.data('vectorsearch-rich-suggest')) {
            return;
        }

        function label(key, fallback) {
            return labels[key] || $t(fallback);
        }

        function disableNativeAutocomplete() {
            var quickSearch = input.data('mageQuickSearch');

            nativeAutocomplete.hide().empty().attr('aria-hidden', 'true');

            if (quickSearch && quickSearch.options) {
                quickSearch.options.url = '';
            }
        }

        input.data('vectorsearch-rich-suggest', true);
        form.addClass('vectorsearch-rich-form');
        form.closest('.block-search').addClass('vectorsearch-rich-block');
        disableNativeAutocomplete();
        setTimeout(disableNativeAutocomplete, 0);
        setTimeout(disableNativeAutocomplete, 500);
        input.attr({
            'aria-controls': 'vectorsearch-rich-suggest',
            'aria-haspopup': 'listbox'
        });

        panel = $('<div/>', {
            id: 'vectorsearch-rich-suggest',
            class: 'vectorsearch-rich-suggest',
            role: 'listbox',
            'aria-label': label('searchSuggestions', 'Search suggestions')
        }).hide();

        input.closest('.control').append(panel);

        function hasResults(data) {
            return (data.phrases && data.phrases.length) ||
                (data.categories && data.categories.length) ||
                (data.products && data.products.length);
        }

        function renderSection(title, items, renderer, className) {
            if (!items || !items.length) {
                return '';
            }

            return '<div class="vrs-section vrs-section-' + className + '">' +
                '<div class="vrs-section-title">' + escapeHtml(title) + '</div>' +
                '<div class="vrs-items">' + items.map(renderer).join('') + '</div>' +
                '</div>';
        }

        function renderIcon(type) {
            if (type === 'category') {
                return '<span class="vrs-icon vrs-icon-category" aria-hidden="true">' +
                    '<svg class="vrs-svg-icon" viewBox="0 0 24 24" focusable="false">' +
                    '<rect x="4" y="4" width="6" height="6" rx="1.2"></rect>' +
                    '<rect x="14" y="4" width="6" height="6" rx="1.2"></rect>' +
                    '<rect x="4" y="14" width="6" height="6" rx="1.2"></rect>' +
                    '<rect x="14" y="14" width="6" height="6" rx="1.2"></rect>' +
                    '</svg>' +
                    '</span>';
            }

            return '<span class="vrs-icon vrs-icon-phrase" aria-hidden="true">' +
                '<svg class="vrs-svg-icon" viewBox="0 0 24 24" focusable="false">' +
                '<circle cx="10.5" cy="10.5" r="5.5"></circle>' +
                '<path d="M15 15l5 5"></path>' +
                '</svg>' +
                '</span>';
        }

        function renderPhrase(item) {
            return '<a class="vrs-item vrs-phrase" role="option" href="' + escapeHtml(item.url) + '" data-vrs-item>' +
                renderIcon('phrase') +
                '<span class="vrs-copy"><strong>' + escapeHtml(item.title) + '</strong></span>' +
                '</a>';
        }

        function renderCategory(item) {
            var path = item.path || label('category', 'Category');

            return '<a class="vrs-item vrs-category" role="option" href="' + escapeHtml(item.url) + '" data-vrs-item>' +
                renderIcon('category') +
                '<span class="vrs-copy"><strong>' + escapeHtml(item.title) + '</strong><small>' + escapeHtml(path) + '</small></span>' +
                '</a>';
        }

        function renderProduct(item) {
            return '<a class="vrs-item vrs-product" role="option" href="' + escapeHtml(item.url) + '" data-vrs-item>' +
                '<span class="vrs-product-image"><img src="' + escapeHtml(item.image) + '" alt="" loading="lazy"/></span>' +
                '<span class="vrs-copy"><strong>' + escapeHtml(item.title) + '</strong><small>' + escapeHtml(item.sku) + '</small></span>' +
                '<span class="vrs-price">' + escapeHtml(item.price) + '</span>' +
                '</a>';
        }

        function show(data) {
            var html,
                topGrid;

            if (!hasResults(data)) {
                hide();
                return;
            }

            topGrid = renderSection(label('suggestedPhrases', 'Suggested phrases'), data.phrases, renderPhrase, 'phrases') +
                renderSection(label('categories', 'Categories'), data.categories, renderCategory, 'categories');

            html = '<div class="vrs-shell">' +
                '<div class="vrs-topline"><span>' + escapeHtml(label('bestMatches', 'Best matches')) + '</span>' +
                '<a class="vrs-all" href="' + escapeHtml(data.search_url) + '">' + escapeHtml(label('showAll', 'Show all')) + '</a></div>' +
                (topGrid ? '<div class="vrs-top-grid">' + topGrid + '</div>' : '') +
                renderSection(label('products', 'Products'), data.products, renderProduct, 'products') +
                '</div>';

            activeIndex = -1;
            panel.html(html).show();
            disableNativeAutocomplete();
            input.attr('aria-expanded', 'true').removeAttr('aria-activedescendant');
        }

        function hide() {
            activeIndex = -1;
            panel.hide().empty();
            disableNativeAutocomplete();
            input.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
        }

        function items() {
            return panel.find('[data-vrs-item]:visible');
        }

        function setActive(index) {
            var list = items(),
                item;

            if (!list.length) {
                return;
            }

            if (index < 0) {
                index = list.length - 1;
            }
            if (index >= list.length) {
                index = 0;
            }

            list.removeClass('is-active').removeAttr('id');
            item = list.eq(index).addClass('is-active').attr('id', 'vectorsearch-rich-option-' + index);
            activeIndex = index;
            input.attr('aria-activedescendant', item.attr('id'));
        }

        function fetchSuggestions() {
            var query = $.trim(input.val()),
                cacheKey = query.toLowerCase(),
                cached = responseCache[cacheKey],
                now = Date.now();

            if (query.length < minLength) {
                if (request) {
                    request.abort();
                }
                hide();
                return;
            }

            if (cached && cached.expires > now) {
                show(cached.data);
                return;
            }

            if (request) {
                request.abort();
            }

            activeIndex = -1;
            panel.html(
                '<div class="vrs-loading" role="status">' +
                '<span class="vrs-spinner" aria-hidden="true"></span>' +
                '<span>' + escapeHtml(label('searching', 'Searching...')) + '</span>' +
                '</div>'
            ).show();
            disableNativeAutocomplete();

            request = $.getJSON(endpoint, {q: query})
                .done(function (data) {
                    if ($.trim(input.val()) !== query) {
                        return;
                    }

                    responseCache[cacheKey] = {
                        data: data,
                        expires: Date.now() + responseCacheLifetime
                    };
                    show(data);
                })
                .fail(function (xhr) {
                    if (xhr.statusText !== 'abort') {
                        hide();
                    }
                });
        }

        input.on('input propertychange', function () {
            hide();
        });
        input.on('input propertychange', debounce(fetchSuggestions, suggestionDelay));
        input.on('focus', function () {
            if ($.trim(input.val()).length >= minLength && panel.children().length) {
                panel.show();
                disableNativeAutocomplete();
            }
        });

        input.on('keydown', function (event) {
            var key = event.key || event.keyCode,
                list = items(),
                active;

            if (!panel.is(':visible')) {
                return;
            }

            if (key === 'ArrowDown' || key === 40) {
                event.preventDefault();
                setActive(activeIndex + 1);
            } else if (key === 'ArrowUp' || key === 38) {
                event.preventDefault();
                setActive(activeIndex - 1);
            } else if (key === 'Enter' || key === 13) {
                active = list.eq(activeIndex);
                if (active.length) {
                    event.preventDefault();
                    window.location.href = active.attr('href');
                }
            } else if (key === 'Escape' || key === 27) {
                hide();
            }
        });

        panel.on('mouseenter', '[data-vrs-item]', function () {
            setActive(items().index(this));
        });

        $(document).on('mousedown.vectorsearchRichSuggest', function (event) {
            if (!$(event.target).closest(panel).length && event.target !== input.get(0)) {
                hide();
            }
        });
    };
});
