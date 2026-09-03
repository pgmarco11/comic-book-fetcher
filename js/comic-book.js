/**
 * comic-book.js
 * Handles issue loading, pagination, search for Comic Book Issues page
 * Uses jQuery + AJAX + wp_localize_script
 */
window.DEBUG = false;

jQuery(document).ready(function($){    

    // The temporary issues-page response handles its own retry.
    if (document.getElementById('metron-retry-message')) {
        return;
    }

    setTimeout(() => {

        const serverRenderedItems = document.querySelectorAll(`
            .comic-title img[data-series-id],
            .issue-item img[data-issue-id]
        `);
    
        if (serverRenderedItems.length > 0) {
            lazyLoadImages();
        } else {
            console.log('No lazy-load items found');
        }
    
    }, 200);

    if (window.location.pathname.includes('/issues/')) {  
        setTimeout(() => {
            const $list = $('#issues-list');
            // Only check for real issue items — .no-results is not "server-rendered content"
            const hasContent = $list.find('li.issue-item').length > 0;
    
            if ($list.hasClass('server-rendered') && hasContent) {
                $list.addClass('loaded');
                $('#loading-spinner').addClass('hidden').css({ display: 'none', opacity: 0 });
                const total = parseInt($list.attr('data-total')) || 0;
                const page  = parseInt($list.attr('data-page')) || 1;
                const search = $('#issue-search').val() || '';
                const titleId = new URLSearchParams(location.search).get('title_id');
                if (total > 0 && titleId) {
                    renderIssuePagination(titleId, page, search, total);
                }
            }
            // No else. The bottom sync block already calls fetchIssues at t=0 when needed.
            // Calling it again here after SPA nav cleared the list is the double-fetch bug.
        }, 300);
    }
      
    let currentPublisherId = null;
    let currentLetter = 'all';
    let currentPage = 1;
    let currentSearch = '';
    let booksRequestId = 0;
    let publishersRequestId = 0;
    
    // Custom confirm dialog using Toastify and Promise
    function toastConfirm(message) {
        return new Promise((resolve) => {
            const container = document.createElement('div');
            container.style.background = '#fff';
            container.style.color = '#000';
            container.style.padding = '15px';
            container.style.borderRadius = '5px';
            container.style.textAlign = 'center';
            container.style.minWidth = '250px';

            const text = document.createElement('div');
            text.textContent = message;
            container.appendChild(text);

            const buttons = document.createElement('div');
            buttons.style.marginTop = '10px';

            const yesBtn = document.createElement('button');
            yesBtn.textContent = 'Yes';
            yesBtn.style.marginRight = '10px';
            yesBtn.style.padding = '5px 10px';
            yesBtn.style.cursor = 'pointer';

            const noBtn = document.createElement('button');
            noBtn.textContent = 'No';
            noBtn.style.padding = '5px 10px';
            noBtn.style.cursor = 'pointer';

            buttons.appendChild(yesBtn);
            buttons.appendChild(noBtn);
            container.appendChild(buttons);

            const toast = Toastify({
                node: container,
                duration: -1,
                close: false,
                gravity: "top",
                position: "center",
                stopOnFocus: true,
            });
            toast.showToast();

            yesBtn.onclick = () => {
                toast.hideToast();
                resolve(true);
            };
            noBtn.onclick = () => {
                toast.hideToast();
                resolve(false);
            };
        });
    }



    // ===================================================================
    // Cache Helpers
    // ===================================================================
    const CACHE_TTL =
    24 * 60 * 60 * 1000;

    const CACHE_PREFIX =
        'tcs:comic-catalog:v3:';

    function browserCacheKey(key) {
        return CACHE_PREFIX + key;
    }

    function getCachedData(key) {
        const versionedKey = browserCacheKey(key);
        const cached = localStorage.getItem(versionedKey);
    
        if (!cached) {
            return null;
        }
    
        try {
            const { value, expiry } = JSON.parse(cached);
    
            if (Date.now() > expiry) {
                localStorage.removeItem(versionedKey);
                return null;
            }

            // Let PHP attach current cached publisher details when this
            // browser snapshot was saved before enrichment finished.
            if (
                Array.isArray(value?.publishers) &&
                value.publishers.some(item => item.publisher_loaded !== true)
            ) {
                return null;
            }

            // Let PHP recheck unresolved or confirmed-missing covers.
            // This also avoids retaining a six-hour missing-image result
            // for the browser cache's longer 24-hour lifetime.
            if (
                Array.isArray(value?.series) &&
                value.series.some(item => !item.image)
            ) {
                return null;
            }
    
            return value;
        } catch (error) {
            console.error('Cache error:', error);
            localStorage.removeItem(versionedKey);
            return null;
        }
    }
    
    function setCachedData(key, data) {
        const versionedKey = browserCacheKey(key);
        const expiry = Date.now() + CACHE_TTL;
    
        try {
            localStorage.setItem(
                versionedKey,
                JSON.stringify({
                    value: data,
                    expiry,
                })
            );
        } catch (error) {
            console.warn(
                'localStorage quota exceeded:',
                error
            );
        }
    }
    
    function clearCachedData(key) {
        try {
            localStorage.removeItem(
                browserCacheKey(key)
            );
        } catch (error) {
            console.warn(
                'Failed to clear cache:',
                key,
                error
            );
        }
    }

    // ===================================================================
    // Spinner
    // ===================================================================    
    function showSpinner() {
        $('#loading-spinner')
            .stop(true, true)
            .removeClass('hidden')
            .addClass('visible')
            .css({
                display: 'block',
                opacity: 0
            })
            .animate({
                opacity: 1
            }, 200);
    }
    function hideSpinner() {
        $('#loading-spinner')
            .stop(true, true)
            .animate({
                opacity: 0
            }, 200, function() {
                $(this)
                    .removeClass('visible')
                    .addClass('hidden')
                    .css({
                        display: 'none',
                        opacity: 0
                    });
            });
    }

    // UI updates
    function updateActiveLetter(letter) {
   
        if (window.location.pathname.includes('/issues/') || window.location.pathname.includes('/issue/')) {    
            return;
        }
    
        $('.letter-btn').removeClass('active');
        $(`.letter-btn[data-letter="${letter}"]`).addClass('active');
        currentLetter = letter;
    
        const params = new URLSearchParams(window.location.search);
        params.set('letter', letter || 'all');
        if (!params.has('page') || currentPage === 1) {
            params.set('page', currentPage);
        }
    
        const newUrl = `${window.location.pathname}?${params.toString()}`;
     
        history.pushState({ page: parseInt(params.get('page')) || 1, letter }, '', newUrl);
    }

    function showLetterButtons(show) {
        $('#letter-buttons').toggle(show);
    }

    function escapeCatalogHtml(value) {
        const entities = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        };
    
        return String(value ?? '').replace(
            /[&<>"']/g,
            character => entities[character]
        );
    }

    function renderItems(items, type, page, total, search = '', letter = '', perPage,  isTotalExact = true) {  
        const isPublisher = type === 'publishers';
        const container   = $('#book-container');  

        const urlParams             = new URLSearchParams(window.location.search);
        const urlPage = urlParams.get('page') || '1';         
        const requestedPage = Number(page);      
        const pageSize    = Number(perPage) || 10;
        const currentPage = parseInt(urlPage, 10);

        const startIndex = total === 0
            ? 0
            : (currentPage - 1) * pageSize + 1;

        const endIndex = Math.min(
            startIndex + items.length - 1,
            total
        );
            
        let html = `<div id="items-wrapper"><div class="${isPublisher ? 'publishers' : 'book'}-wrapper">`;

        const totalLabel = `${total}${isTotalExact ? '' : '+'}`;

        if (!Array.isArray(items) || items.length === 0) {
            html += `
                <p class="empty-results">
                    No ${isPublisher ? 'publishers' : 'series'} found
                    ${letter && letter !== 'all' ? ` starting with "${letter}"` : ''}.
                </p>
            `;
        } else {
            html += `<p>Showing ${startIndex}–${endIndex} of ${totalLabel} ${isPublisher ? 'publishers' : 'series'}${search ? ` for "${search}"` : letter && letter !== 'all' ? ` starting with "${letter}"` : ''}</p>`
            
            items.forEach(item => {      
                
                const placeholder = comicbooks_fetchers_data.placeholder ||
                '/wp-content/plugins/comic-book-fetcher/images/placeholder.png';

                if (isPublisher) {           
                
                    const loaded = item.publisher_loaded === true;
                    const imageUrl = loaded && item.image
                        ? item.image
                        : placeholder;
                
                    html += `
                        <div
                            class="publisher-item"
                            data-publisher-id="${item.id}"
                            ${loaded ? 'data-publisher-loaded="true"' : ''}>
                
                            <a href="/comic-catalog/?publisher_id=${item.id}&letter=all&page=1">
                                <div class="publisher-image">
                                    <img
                                        src="${escapeCatalogHtml(imageUrl)}"
                                        alt="${escapeCatalogHtml(item.name)}"
                                        width="100"
                                        height="100"
                                        loading="lazy"
                                        decoding="async">
                                </div>
                
                                <div class="publisher-info">
                                    <h3>${escapeCatalogHtml(item.name)}</h3>
                
                                    <p>
                                        <strong>Founded:</strong>
                                        <span class="publisher-founded">
                                            ${escapeCatalogHtml(
                                                loaded
                                                    ? (item.founded || 'Unknown')
                                                    : 'Loading…'
                                            )}
                                        </span>
                                    </p>
                
                                    <p class="publisher-description">
                                        ${escapeCatalogHtml(
                                            loaded
                                                ? (item.desc || 'No description available.')
                                                : 'Loading publisher information…'
                                        )}
                                    </p>
                                </div>
                            </a>
                        </div>
                    `;
                } else {  
                    const hasImage = !!item.image;
                    const resolved = hasImage || item.image_resolved === true;
                    const imgSrc = hasImage ? item.image : placeholder;
                    
                    const imgAttrs = resolved
                        ? 'data-loaded="true"'
                        : `data-series-id="${item.series_id}" class="lazy-placeholder"`;
                
                    html += `
                    <div class="comic-title" data-series-id="${item.series_id}">
                            <a href="/comic-catalog/issues/?title_id=${item.series_id}&page=1">
                                <div class="comic-image">
                                    <img src="${escapeCatalogHtml(imgSrc)}"
                                        ${imgAttrs}
                                        alt="${escapeCatalogHtml(item.name)}"
                                        loading="lazy"                                 
                                        width="100"
                                        height="150">
                                </div>
                                <div class="comic-info">
                                    <div class="comic-title-name">${escapeCatalogHtml(item.name)}</div>

                                    <div class="comic-title-meta">
                                        <p>Vol. <span>${escapeCatalogHtml(item.volume || 1)}</span></p>
                                        <p>Issues: <span>${escapeCatalogHtml(item.issue_count || 0)}</span></p>
                                        <p>Started: <span>${escapeCatalogHtml(item.year_began || 'N/A')}</span></p>
                                    </div>
                                </div>
                            </a>
                    </div>`;
                }
            });
        }
    
        html += '</div>';

        if (Array.isArray(items) && items.length > 0 && total > 0) {
            html += renderPagination(
                requestedPage,
                Math.ceil(total / pageSize),
                letter,
                isTotalExact
            );
        }
        
        html += '</div></div>';
    
        container.html(html);
        hideSpinner(); 

        lazyLoadImages();
    
        // Preload next page only when we actually rendered the current one
        const totalPages = Math.ceil(total / perPage);
        if (requestedPage < totalPages && comicbooks_fetchers_data.preload_enabled) {
             if (typeof debugLog === 'function') debugLog(`Preloading next page ${requestedPage + 1}`);
             preloadData(
                 isPublisher ? 'publishers' : 'books',
                 currentPublisherId,
                 requestedPage + 1,
                 search,
                 letter
             );
        }

    }

    // ===================================================================
    // Render Pagination
    // ===================================================================
    function renderPagination(page, totalPages, letter = 'all', isTotalExact = true) {
        if (totalPages <= 1 && isTotalExact) return '';
    
        // Use current browser URL as base (safest way to preserve publisher_id, search, etc.)
        const currentUrl = new URL(window.location.href);
        const params = currentUrl.searchParams;
    
        let html = '<div class="pagination-wrapper">';
        html += `<p>Page ${page} of ${totalPages}${isTotalExact ? '' : '+'}</p>`;
    
        // Helper: create link for any target page
        function getPageLink(targetPage) {
            const newParams = new URLSearchParams(params);
            newParams.set('page', targetPage);
            newParams.set('letter', letter);
            return `${currentUrl.pathname}?${newParams.toString()}`;
        }
    
        if (page > 1) {
            html += `<a href="${getPageLink(page - 1)}" class="page-btn" data-page="${page - 1}" data-letter="${letter}">Previous</a>`;
        }
    
        const start = Math.max(1, page - 2);
        const end   = Math.min(totalPages, page + 2);
    
        for (let i = start; i <= end; i++) {
            const isActive = i === page;
            const href = isActive ? '#' : getPageLink(i);
            html += `<a href="${href}" class="page-btn${isActive ? ' active' : ''}" data-page="${i}" data-letter="${letter}">${i}</a>`;
        }
    
        if (page < totalPages || !isTotalExact) {
            html += `<a href="${getPageLink(page + 1)}" class="page-btn" data-page="${page + 1}" data-letter="${letter}">Next</a>`;
        }
    
        html += '</div>';
        return html;
    }


        /*
        * Run only one potentially Metron-backed enrichment request at a time.
        */
        const enrichmentRequestQueue = [];
        let enrichmentRequestActive = false;

        function enqueueEnrichmentRequest(request)
        {
            enrichmentRequestQueue.push(request);
            processEnrichmentRequestQueue();
        }

        function processEnrichmentRequestQueue()
        {
            if (
                enrichmentRequestActive ||
                enrichmentRequestQueue.length === 0
            ) {
                return;
            }

            enrichmentRequestActive = true;

            const request = enrichmentRequestQueue.shift();

            $.ajax(Object.assign({ timeout: 30000 }, request.options))
                .done(response => {
                    if (typeof request.done === 'function') {
                        request.done(response);
                    }
                })
                .fail(xhr => {
                    if (typeof request.fail === 'function') {
                        request.fail(xhr);
                    }
                })
                .always(() => {
                    if (typeof request.always === 'function') {
                        request.always();
                    }

                    enrichmentRequestActive = false;

                    /*
                    * Yield briefly before processing another visible batch.
                    * The PHP rate limiter still controls actual Metron timing.
                    */
                    window.setTimeout(
                        processEnrichmentRequestQueue,
                        250
                    );
                });
        }

    const pendingPublisherMap = new Map();
    let publisherBatchTimer = null;

    const enrichmentRetryLimit = 2;

    function apiRetryDelay(xhr, fallback = 2500) {
        const seconds = Number(
            xhr?.getResponseHeader('Retry-After')
        );
    
        return Number.isFinite(seconds) && seconds > 0
            ? Math.min(
                2147483647,
                Math.max(1000, seconds * 1000)
            )
            : fallback;
    }

    function requeueEnrichment(
        element,
        retryKey,
        queueCallback,
        minimumDelay = 0
    ) {
        const retryCount = Number(
            element.dataset[retryKey] || 0
        );
    
        if (retryCount >= enrichmentRetryLimit) {
            return;
        }
    
        element.dataset[retryKey] = String(retryCount + 1);
    
        setTimeout(() => {
            if (element.isConnected) {
                queueCallback(element);
            }
        }, Math.max(
            minimumDelay,
            500 * (retryCount + 1)
        ));
    }

    function queuePublisher(card) {
        const publisherId = card.dataset.publisherId;

        if (!publisherId) return;
        if (card.dataset.publisherLoaded === 'true') return;
        if (card.dataset.publisherLoading === 'true') return;

        card.dataset.publisherLoading = 'true';

        if (!pendingPublisherMap.has(publisherId)) {
            pendingPublisherMap.set(publisherId, []);
        }

        pendingPublisherMap.get(publisherId).push(card);

        clearTimeout(publisherBatchTimer);

        publisherBatchTimer = setTimeout(
            flushPublisherBatch,
            400
        );
    }

    function flushPublisherBatch()
    {
        if (!pendingPublisherMap.size) {
            return;
        }
    
        /*
         * Process only two visible publishers at a time.
         * Leave the remaining publishers in the map for later requests.
         */
        const publisherIds = Array.from(
            pendingPublisherMap.keys()
        ).slice(0, 2);
    
        const batch = new Map();
    
        publisherIds.forEach(publisherId => {
            batch.set(
                publisherId,
                pendingPublisherMap.get(publisherId) || []
            );
    
            pendingPublisherMap.delete(publisherId);
        });
    
        enqueueEnrichmentRequest({
            options: {
                url: comicbooks_fetchers_data.ajax_url,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'load_publisher_images_batch',
                    publisher_ids: publisherIds,
                    nonce: comicbooks_fetchers_data.nonce,
                },
            },
    
            done(response) {
                const publishers =
                    response?.data?.publishers || {};
    
                publisherIds.forEach(publisherId => {
                    const info = publishers[publisherId] || {};
                    const cards = batch.get(publisherId) || [];
    
                    cards.forEach(card => {
                        const img = card.querySelector(
                            '.publisher-image img'
                        );
    
                        const founded = card.querySelector(
                            '.publisher-founded'
                        );
    
                        const description = card.querySelector(
                            '.publisher-description'
                        );
    
                        if (img && info.image) {
                            img.src = info.image;
                        }
    
                        if (founded) {
                            founded.textContent =
                                info.founded || 'Unknown';
                        }
    
                        if (description) {
                            description.textContent =
                                info.desc ||
                                'No description available.';
                        }
    
                        delete card.dataset.publisherLoading;
                        delete card.dataset.publisherRetryCount;
                        card.dataset.publisherLoaded = 'true';
                    });
                });
            },
    
            fail(xhr) {
                publisherIds.forEach(publisherId => {
                    const cards = batch.get(publisherId) || [];
    
                    cards.forEach(card => {
                        delete card.dataset.publisherLoading;
                    
                        requeueEnrichment(
                            card,
                            'publisherRetryCount',
                            queuePublisher,
                            apiRetryDelay(xhr)
                        );
                    });
                });
            },
    
            always() {
                if (pendingPublisherMap.size) {
                    publisherBatchTimer = window.setTimeout(
                        flushPublisherBatch,
                        100
                    );
                }
            },
        });
    }

    // ======================================================
    // Series image batch queue
    // Prevents one AJAX call per image
    // ======================================================
    const pendingSeriesImageMap = new Map();
    let seriesImageBatchTimer = null;

    function queueSeriesImage(img) {
        if (img.dataset.coverStatus === 'error') return;

        const seriesId = img.dataset.seriesId;

        if (!seriesId) return;
        if (img.dataset.loaded === 'true') return;
        if (img.dataset.loading === 'true') return;

        img.dataset.loading = 'true';

        if (!pendingSeriesImageMap.has(seriesId)) {
            pendingSeriesImageMap.set(seriesId, []);
        }

        pendingSeriesImageMap.get(seriesId).push(img);

        clearTimeout(seriesImageBatchTimer);

        // Small delay lets multiple images enter viewport and get batched together
        seriesImageBatchTimer = setTimeout(flushSeriesImageBatch, 400);
    }

    function retrySeriesCover(img, seconds = 3) {
        delete img.dataset.loading;
        delete img.dataset.loaded;

        img.onload = null;
        img.onerror = null;

        if (
            Number(img.dataset.seriesRetryCount || 0) >=
            enrichmentRetryLimit
        ) {
            img.dataset.coverStatus = 'error';
            return;
        }

        img.dataset.coverStatus = 'retry';

        const delay = Math.min(
            2147483647,
            Math.max(
                1000,
                (Number(seconds) || 3) * 1000
            )
        );

        requeueEnrichment(
            img,
            'seriesRetryCount',
            queueSeriesImage,
            delay
        );
    }

    function flushSeriesImageBatch() {
        if (!pendingSeriesImageMap.size) return;

        const seriesIds = Array.from(
            pendingSeriesImageMap.keys()
        ).slice(0, 2);

        const batch = new Map();

        seriesIds.forEach(seriesId => {
            batch.set(
                seriesId,
                pendingSeriesImageMap.get(seriesId) || []
            );

            pendingSeriesImageMap.delete(seriesId);
        });

        enqueueEnrichmentRequest({
            options: {
                url: comicbooks_fetchers_data.ajax_url,
                method: 'POST',
                dataType: 'json',

                data: {
                    action: 'load_series_images_batch',
                    series_ids: seriesIds,
                    nonce: comicbooks_fetchers_data.nonce
                }
            },

            done(response) {
                const results = response?.success === true
                    ? response?.data?.results
                    : null;

                seriesIds.forEach(seriesId => {
                    const result = results?.[seriesId];
                    const imgs = batch.get(seriesId) || [];

                    imgs.forEach(img => {
                        if (!img.isConnected) return;

                        if (result?.status === 'found') {
                            let url;

                            try {
                                url = new URL(result.url);

                                if (
                                    !['http:', 'https:'].includes(
                                        url.protocol
                                    )
                                ) {
                                    throw new Error(
                                        'Invalid image protocol'
                                    );
                                }
                            } catch {
                                retrySeriesCover(img);
                                return;
                            }

                            img.dataset.coverStatus = 'loading';
                            img.dataset.loading = 'true';

                            img.onload = () => {
                                delete img.dataset.loading;
                                delete img.dataset.seriesRetryCount;

                                img.dataset.loaded = 'true';
                                img.dataset.coverStatus = 'found';

                                img.onload = null;
                                img.onerror = null;
                            };

                            img.onerror = () => {
                                retrySeriesCover(img);
                            };

                            img.src = url.href;
                            return;
                        }

                        if (result?.status === 'missing') {
                            delete img.dataset.loading;
                            delete img.dataset.seriesRetryCount;

                            img.onload = null;
                            img.onerror = null;

                            img.dataset.loaded = 'true';
                            img.dataset.coverStatus = 'missing';
                            return;
                        }

                        if (result?.status === 'error') {
                            delete img.dataset.loading;
                            delete img.dataset.loaded;

                            img.dataset.coverStatus = 'error';

                            img.onload = null;
                            img.onerror = null;

                            console.warn(
                                'Series cover unavailable:',
                                seriesId,
                                result.message
                            );

                            return;
                        }

                        // Missing or malformed entries are failures.
                        // Only an explicit "missing" status confirms absence.
                        retrySeriesCover(
                            img,
                            result?.retry_after
                        );
                    });
                });
            },

            fail(xhr) {
                const retryable =
                    xhr.status === 0 ||
                    xhr.status === 408 ||
                    xhr.status === 429 ||
                    xhr.status >= 500;

                seriesIds.forEach(seriesId => {
                    (batch.get(seriesId) || []).forEach(img => {
                        if (retryable) {
                            retrySeriesCover(
                                img,
                                apiRetryDelay(xhr) / 1000
                            );
                        } else {
                            delete img.dataset.loading;
                            delete img.dataset.loaded;

                            img.dataset.coverStatus = 'error';
                        }
                    });
                });
            },

            always() {
                if (pendingSeriesImageMap.size) {
                    seriesImageBatchTimer = window.setTimeout(
                        flushSeriesImageBatch,
                        100
                    );
                }
            }
        });
    }
    
    function lazyLoadImages() {

        const publisherCards = document.querySelectorAll(
            '.publisher-item[data-publisher-id]' +
            ':not([data-publisher-loaded])' +
            ':not([data-publisher-loading])'
        );
        
        const publisherObserver = new IntersectionObserver(
            (entries, observer) => {
                entries.forEach(entry => {
                    if (!entry.isIntersecting) {
                        return;
                    }
        
                    observer.unobserve(entry.target);
                    queuePublisher(entry.target);
                });
            },
            {
                root: null,
                rootMargin: '0px',
                threshold: 0.01
            }
        );
        
        publisherCards.forEach(card => {
            publisherObserver.observe(card);
        });

        /*
        * SERIES IMAGES
        *
        * Only request covers for series currently visible in the viewport.
        * Visible covers are still combined into one AJAX batch.
        */
        const seriesImages = document.querySelectorAll(
            'img[data-series-id]:not([data-loaded]):not([data-loading])'
        );

        const seriesObserver = new IntersectionObserver(
            (entries, observer) => {
                entries.forEach(entry => {
                    if (!entry.isIntersecting) {
                        return;
                    }

                    const img = entry.target;

                    observer.unobserve(img);
                    queueSeriesImage(img);
                });
            },
            {
                root: null,
                rootMargin: '0px',
                threshold: 0.01
            }
        );

        seriesImages.forEach(img => {
            seriesObserver.observe(img);
        });

    }

    // Fetch publishers
    function fetchPublishers(
        name = '',
        page = 1,
        letter = 'all',
        retries = 3,
        polls = 0
    ) {
        const requestId = ++publishersRequestId;
    
        // Invalidate any older series request.
        ++booksRequestId;
    
        const isCurrent = () =>
            requestId === publishersRequestId;
    
        const container = $('#book-container');
        const select = document.getElementById('publisher-select');
    
        function showRetry(message) {
            if (!isCurrent()) return;
    
            hideSpinner();
    
            container.empty()
                .attr('aria-busy', 'false')
                .css('visibility', 'visible')
                .append($('<p>').text(message))
                .append(
                    $('<button>', {
                        type: 'button',
                        text: 'Try again'
                    }).on('click', () => {
                        fetchPublishers(name, page, letter);
                    })
                );
        }
    
        showSpinner();
    
        container
            .attr('aria-busy', 'true')
            .css('visibility', 'visible');
    
        // Read the server snapshot so browser caching cannot suppress
        // refresh scheduling or hide completion of the initial build.
        $.ajax({
            url: comicbooks_fetchers_data.ajax_url,
            method: 'POST',
            timeout: 30000,
    
            data: {
                action: 'load_publishers',
                nonce: comicbooks_fetchers_data.nonce,
                name,
                page,
                letter,
                include_options:
                    select && select.options.length <= 1 ? 1 : 0
            },
    
            success(response, textStatus, xhr) {
                if (!isCurrent()) return;
    
                const data = response?.data;
    
                if (!response?.success || !data) {
                    showRetry('Publishers could not be loaded.');
                    return;
                }
    
                if (data.ready === false) {
                    // Stop automatic polling after approximately two minutes.
                    if (polls >= 24) {
                        showRetry(
                            'The publisher list is still being prepared. ' +
                            'Please check again shortly.'
                        );
                        return;
                    }
    
                    hideSpinner();
    
                    container.empty()
                        .attr('aria-busy', 'true')
                        .append(
                            $('<p>', { role: 'status' }).text(
                                'Preparing the publisher list. ' +
                                'This can take a little while…'
                            )
                        );
    
                    setTimeout(() => {
                        if (isCurrent()) {
                            fetchPublishers(
                                name,
                                page,
                                letter,
                                retries,
                                polls + 1
                            );
                        }
                    }, apiRetryDelay(xhr, 5000));
    
                    return;
                }
    
                if (!Array.isArray(data.publishers)) {
                    showRetry('Publishers could not be loaded.');
                    return;
                }
    
                if (
                    select &&
                    Array.isArray(data.publisher_options)
                ) {
                    const selected = select.value;
    
                    select.replaceChildren(
                        new Option('Select a publisher', '')
                    );
    
                    data.publisher_options.forEach(publisher => {
                        select.add(
                            new Option(
                                publisher.name,
                                String(publisher.id)
                            )
                        );
                    });
    
                    select.value = selected;
                }
    
                allPublishers = data.publishers;
    
                renderItems(
                    allPublishers,
                    'publishers',
                    page,
                    Number(data.total || 0),
                    name,
                    letter,
                    10
                );
    
                showLetterButtons(true);
                updateActiveLetter(letter);
    
                container
                    .attr('aria-busy', 'false')
                    .css('visibility', 'visible');
    
                hideSpinner();
            },
    
            error(xhr, status) {
                if (!isCurrent()) return;
    
                const retryable =
                    status === 'timeout' ||
                    xhr.status === 0 ||
                    xhr.status === 429 ||
                    xhr.status >= 500;
    
                if (retryable && retries > 0) {
                    setTimeout(() => {
                        if (isCurrent()) {
                            fetchPublishers(
                                name,
                                page,
                                letter,
                                retries - 1,
                                polls
                            );
                        }
                    }, apiRetryDelay(xhr));
    
                    return;
                }
    
                showRetry(
                    'Publishers could not be loaded. Please try again.'
                );
            }
        });
    }

    // Fetch books
    function fetchBooks(publisherId, page = 1, name = '', letter = 'all', retries = 3) {

        ++publishersRequestId;

        // Every new request invalidates any previous request.
        const requestId = ++booksRequestId;
    
        showSpinner();
    
        // Don't leave an old "No series found" message visible
        // while the next request/scan is running.
        $('#book-container .empty-results').remove();
    
        if (!publisherId && !name) {
            $('#book-container').html(
                '<p>Please select a publisher or enter a search term.</p>'
            );
    
            showLetterButtons(false);
            updateActiveLetter(letter);

            $('#book-container')
            .attr('aria-busy', 'false')
            .css('visibility', 'visible');

            hideSpinner();
            return;
        }   

    
        const cacheKey = `metron_books_${publisherId}_${page}_${name}_${letter}`;
        const cached = getCachedData(cacheKey);
    
        if (cached) {
    
            // A newer request started while we were getting here.
            if (requestId !== booksRequestId) {
                return;
            }
    
            allSeries = cached.series || [];
    
            const perPage = cached.perPage || cached.per_page || 10;
            const total = cached.total || 0;
            const isTotalExact = cached.isTotalExact !== false;
    
            renderItems(
                allSeries,
                'books',
                page,
                total,
                name,
                letter,
                perPage,
                isTotalExact
            );
    
            showLetterButtons(true);
            updateActiveLetter(letter);
            $('#book-container')
            .attr('aria-busy', 'false')
            .css('visibility', 'visible');

            hideSpinner();
    
            return;
        }
    
        $.ajax({
            url: comicbooks_fetchers_data.ajax_url,
            method: 'POST',
    
            data: {
                action: 'load_book',
                nonce: comicbooks_fetchers_data.nonce,
                publisher_id: publisherId || '',
                page: page,
                per_page: 10,
                name: name,
                letter: letter
            },
    
            timeout: 30000,
    
            beforeSend: () => {
                console.log(
                    'Sending REAL request for page',
                    page,
                    'request',
                    requestId
                );
            },
    
            success: response => {
    
                // Important:
                // Ignore this response if another fetchBooks() started after it.
                if (requestId !== booksRequestId) {          
                    return;
                }   
    
                if (response.success && response.data) {
    
                    const scanComplete =
                        response.data.scan_complete !== false;
    
                        if (!scanComplete) {
                            setTimeout(() => {
                                // Stop if the user changed publisher, search, or page.
                                if (requestId !== booksRequestId) {
                                    return;
                                }
                        
                                fetchBooks(
                                    publisherId,
                                    page,
                                    name,
                                    letter,
                                    retries
                                );
                            }, 250);
                        
                            return;
                        }
    
                    // Scan is definitely complete now.
                    allSeries = response.data.series || [];
    
                    const perPage =
                        response.data.per_page || 10;
    
                    const total =
                        response.data.total || 0;
    
                    const isTotalExact =
                        response.data.is_total_exact !== false;
    
                    setCachedData(cacheKey, {
                        series: allSeries,
                        total,
                        perPage,
                        isTotalExact
                    });
    
                    renderItems(
                        allSeries,
                        'books',
                        page,
                        total,
                        name,
                        letter,
                        perPage,
                        isTotalExact
                    );
    
                    showLetterButtons(true);
                    updateActiveLetter(letter);
                    $('#book-container')
                    .attr('aria-busy', 'false')
                    .css('visibility', 'visible');

                    hideSpinner();
    
                } else {

                    $('#book-container')
                    .attr('aria-busy', 'false')
                    .css('visibility', 'visible');
    
                    $('#book-container').html(`
                        <div class="error-message">
                            Failed to load series.
                            <button class="retry-books">Retry</button>
                        </div>
                    `);
    
                    hideSpinner();
                }
            },
    
            error: (xhr, status) => {
                if (requestId !== booksRequestId) {
                    return;
                }
            
                if (
                    (
                        xhr.status === 429 ||
                        xhr.status === 503 ||
                        status === 'timeout'
                    ) &&
                    retries > 0
                ) {
                    setTimeout(() => {
                        if (requestId !== booksRequestId) {
                            return;
                        }
                    
                        fetchBooks(
                            publisherId,
                            page,
                            name,
                            letter,
                            retries - 1
                        );
                    }, apiRetryDelay(xhr));
            
                    return;
                }
            
                $('#book-container')
                    .attr('aria-busy', 'false')
                    .css('visibility', 'visible');
            
                $('#book-container').html(`
                    <div class="error-message">
                        The comic catalog is temporarily busy.
                        <button class="retry-books">
                            Retry
                        </button>
                    </div>
                `);
            
                hideSpinner();
            }
        });
    }

    // CSS for skeleton loader and minimum height
    const styles = `      
        #loading-spinner {
            position: absolute;
            top: 5rem;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 10;
        }
        #issues-list {
            position: relative;
            min-height: 400px;
        }
    `;

    // Inject styles
    const styleSheet = document.createElement('style');
    styleSheet.innerText = styles;
    document.head.appendChild(styleSheet);

    function renderIssuePagination(titleId, page, search, totalIssues) {
        const $wrapper = $('#pagination-wrapper');
        if (!$wrapper.length) return;  // safety: element must exist in DOM

        if (!totalIssues || totalIssues < 1) {
            $wrapper.empty();
            return;
        }

        const perPage    = 10;
        const totalPages = Math.ceil(totalIssues / perPage);
        const start      = Math.max(1, page - 2);
        const end        = Math.min(totalPages, page + 2);

        const searchParam = search ? `&search=${encodeURIComponent(search)}` : '';

        // Write directly into #pagination-wrapper — no extra <div class="pagination-wrapper"> wrapper
        let html = `<p>Page ${page} of ${totalPages}</p>`;

        if (page > 1) {
            const prev = page - 1;
            html += `<a href="?title_id=${titleId}&page=${prev}${searchParam}"
                        class="page-btn"
                        data-page="${prev}"
                        data-title-id="${titleId}"
                        data-search="${search}">Previous</a>`;
        }

        for (let i = start; i <= end; i++) {
            const active = i === page ? ' active' : '';
            const href   = i === page ? '#' : `?title_id=${titleId}&page=${i}${searchParam}`;
            html += `<a href="${href}"
                        class="page-btn${active}"
                        data-page="${i}"
                        data-title-id="${titleId}"
                        data-search="${search}">${i}</a>`;
        }

        // Next = end + 1, not page + 1 — mirrors the PHP fix
        if (end < totalPages) {
            const next = end + 1;
            html += `<a href="?title_id=${titleId}&page=${next}${searchParam}"
                        class="page-btn"
                        data-page="${next}"
                        data-title-id="${titleId}"
                        data-search="${search}">Next</a>`;
        }

        $wrapper.html(html);
        updateIssuesUrl(titleId, page, search);
    }

    function fetchIssues(titleId = null, page = null, search = '', retries = 3) {
        showSpinner();
        // Resolve params from URL if needed
        if (titleId === null || page === null) {
            const url = new URL(window.location.href);
            const urlTitleId = url.searchParams.get('title_id');
            const urlPage = url.searchParams.get('page');

            titleId = titleId ?? (urlTitleId ? parseInt(urlTitleId, 10) : null);
            page = page ?? (urlPage ? parseInt(urlPage, 10) : 1);

            if (!titleId || isNaN(titleId)) {
                $('#book-container')
                .attr('aria-busy', 'false')
                .css('visibility', 'visible');

                $('#issues-list').html('<p>No series selected.</p>').addClass('loaded');
                $('#pagination-wrapper').empty();
                hideSpinner();
                return;
            }

            if (!page || isNaN(page) || page < 1) {
                page = 1;
            }
        }      

        /* ======================================================
        * AJAX PATH
        * ====================================================== */
        $('#issues-list').removeClass('loaded').html('');

        $.ajax({
            url: comicbooks_fetchers_data.ajax_url,
            method: 'POST',
            data: {
                action: 'load_issues',
                nonce: comicbooks_fetchers_data.nonce,
                title_id: titleId,
                page,
                search
            },
            timeout: 45000,

            success(response) {   
            
                if (!response || response.success !== true) {
                    console.error('AJAX failed - no success flag', response);
                    $('#book-container')
                    .attr('aria-busy', 'false')
                    .css('visibility', 'visible');

                    $('#issues-list').html('<p class="no-results">AJAX load failed (no success)</p>').addClass('loaded');
                    hideSpinner();
                    return;
                }
            
                const data = response.data || {};
                const html = data.issues || '';    
            
                if (typeof html !== 'string' || html.trim() === '') {
                    console.error('No valid HTML in response - empty or wrong format', data);
                    $('#book-container')
                    .attr('aria-busy', 'false')
                    .css('visibility', 'visible');

                    $('#issues-list').html('<p class="no-results">No issues HTML returned</p>').addClass('loaded');
                    hideSpinner();
                    return;
                }
            
                const currentPageFromResponse = Number(data.current_page || page);
                const totalPagesFromResponse = Number(data.total_pages || Math.ceil((data.total_issues || 0) / 10));
            
                $('#issues-list')
                    .removeClass('server-rendered')
                    .html(html)
                    .attr('data-total', Number(data.total_issues || 0))
                    .attr('data-page', currentPageFromResponse)
                    .addClass('loaded')
                    .css('opacity', '1');
                
                // New buttons now exist; refresh their saved status.
                $(document).trigger('comicbooks:issues-rendered');
            
                renderIssuePagination(titleId, currentPageFromResponse, search, data.total_issues || 0);
                       
                lazyLoadImages(); 

                $('#book-container')
                .attr('aria-busy', 'false')
                .css('visibility', 'visible');

                hideSpinner();  // always call at the end
            },

            error(xhr, status) {
                $('#book-container')
                .attr('aria-busy', 'false')
                .css('visibility', 'visible');

                hideSpinner();

                if (
                    (
                        xhr.status === 429 ||
                        xhr.status === 503 ||
                        status === 'timeout'
                    ) &&
                    retries > 0
                ) {
                    setTimeout(
                        () => fetchIssues(titleId, page, search, retries - 1),
                        apiRetryDelay(xhr)
                    );
                } else {
                    $('#issues-list').html('<p>Error loading issues.</p>').addClass('loaded');
                    $('#pagination-wrapper').empty();
                }
            }
        });
    }    


    // ===================================================================
    // Update URLs
    // ===================================================================
    function updateIssuesUrl(titleId, page, search) {
        // Only run on issues list pages
        if (!window.location.pathname.includes('/issues/')) {
              return;
        }
    
        const url = new URL('/comic-catalog/issues/', window.location.origin);
        url.searchParams.set('title_id', titleId);
        url.searchParams.set('page', page);
        if (search) url.searchParams.set('search', search);
        else url.searchParams.delete('search');
    
        history.pushState({ title_id: titleId, page, search }, '', url);
    }

    function updatePublisherUrl(letter, page, search) {
        const url = new URL(window.location);
        if(letter){
            url.searchParams.set('letter', letter);
        }     
        url.searchParams.set('page', page);
        if (search) {
            url.searchParams.set('search', search);
        } else {
            url.searchParams.delete('search');
        }    
        history.pushState({ letter: letter, page, search }, '', url);
    }

    // Ensure main content is visible
    $('.site-main, .page-section, .comic-issues-container').css({
        'display': 'block',
        'visibility': 'visible',
        'opacity': 1
    });

    // Check if issues-list is populated (server-side or initial AJAX)
    if (
        window.location.pathname.includes('/comic-catalog/issues/') &&
        $('#issues-list .issues-list').children().length > 0
    ) {
        $('#issues-list .issues-list').addClass('loaded');
    } else if (window.location.pathname.includes('/comic-catalog/issues/')) {
    
        const urlParams = new URLSearchParams(window.location.search);
    
        const titleId = urlParams.get('title_id');
        const page = parseInt(urlParams.get('page')) || 1;
        const search = urlParams.get('search') || '';
    
        if (titleId) {
            fetchIssues(titleId, page, search);       
        } else {
            $('#book-container')
            .attr('aria-busy', 'false')
            .css('visibility', 'visible');
            
            $('#issues-list').html('<p>No series selected.</p>');
            hideSpinner();
        }
    }

    // Debounce function
    function debounce(func, wait) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }

    // Event handlers
    $('#comic-search').on('input', debounce(function() {
        const searchQuery = $(this).val().trim();
        currentSearch = searchQuery;
        currentPage = 1;
        if (currentPublisherId) {
            fetchBooks(currentPublisherId, 1, searchQuery, currentLetter);
        } else {
            fetchPublishers(searchQuery, 1, currentLetter);
        }
    }, 500));

    // ===================================================================
    // EVENT: Search
    // ===================================================================

    if ($('#issue-search').length) {
        $('#issue-search').on('input', debounce(function() {
            const searchQuery = $(this).val().trim();
            currentSearch = searchQuery;
            currentPage = 1;
            
                // Resolve titleId from URL if not in JS data
                let titleId = comicbooks_fetchers_data?.title_id;
                if (!titleId) {
                    const params = new URLSearchParams(window.location.search);
                    titleId = parseInt(params.get('title_id')) || null;
                }
            
                if (titleId) {
                    fetchIssues(titleId, 1, searchQuery);                 
                } else {
                    console.warn('No title_id found for issue search');
            }
        }, 500));
    }

    $('#publisher-select').on('change', debounce(function() {
        const publisherId = $(this).val();
        const baseUrl = window.location.origin + window.location.pathname;

        currentPublisherId = publisherId;
        currentPage = 1;
        currentSearch = '';
        currentLetter = $(this).attr('data-letter') || 'all';

        $('#comic-search').val('').attr('placeholder', publisherId ? 'Search titles...' : 'Search publishers...');        
        showLetterButtons(true);
        updateActiveLetter('all');

        if (publisherId) {
            const newUrl = `${baseUrl}?publisher_id=${publisherId}&letter=all`;
            $('.publisher-info').css('display', 'block');
            window.location.href = newUrl;
        } else {
            const newUrl = `${baseUrl}?letter=${currentLetter}`;
            $('.publisher-info').hide();
            window.location.href = newUrl;
        }
    }, 300));

    $(document).on('click', '.letter-btn', debounce(function() {
        const letter = $(this).attr('data-letter') || 'all';
        currentLetter = letter;
        currentPage = 1;
        updateActiveLetter(letter); 
    
        if (currentPublisherId) {
            fetchBooks(currentPublisherId, 1, currentSearch, letter);
        } else {
            fetchPublishers(currentSearch, 1, letter);
        }
    }, 300));
    
    // ===================================================================
    // EVENT: Pagination Click
    // ===================================================================

    $(document).on('click', '.page-btn', function (e) {        
        
        const $link = $(this); 

        // If it's the current page → do nothing (just # href)
        if ($link.hasClass('active') || $link.attr('href') === '#') {
            e.preventDefault();
            return;
        }  
        if (e.ctrlKey || e.metaKey || e.which === 2) {
            // Let browser open in new tab / normal navigation
            return;
        }

        e.preventDefault();     

        const $btn = $(this);
    
        const page      = Number($link.data('page'));
        const titleId   = Number($btn.data('title-id')) || null;
        const letter    = $btn.data('letter') ?? currentLetter;
        const search    = $btn.data('search') ?? currentSearch;
    
        const isIssuesPage = window.location.pathname.includes('/comic-catalog/issues/');
        const hasTitleId   = !!titleId;
        const hasPublisher = !!currentPublisherId;
    
        if (page === undefined || isNaN(page)) {
            console.error('Invalid page click', { page });
            return;
        }   
        if (isIssuesPage && hasTitleId) {
            updateIssuesUrl(titleId, page, search);
            fetchIssues(titleId, page, search);        

            $('html, body').animate({
                scrollTop: $('#issues-list').offset().top
            }, 100);
    
            return;
        }
    
        /*   BOOKS PAGINATION (publisher selected) */
        if (hasPublisher) {
            currentPage = page;
            currentLetter = letter;
    
            updatePublisherUrl(letter, page, search);
            fetchBooks(currentPublisherId, page, search, letter);
    
            $('html, body').animate({
                scrollTop: $('#book-container').offset().top
            }, 100);
    
            return;
        }
    
        /* PUBLISHERS PAGINATION (default) */
        currentPage = page;
        currentLetter = letter;
    
        updatePublisherUrl(letter, page, search);
        fetchPublishers(search, page, letter);
    
        $('html, body').animate({
            scrollTop: $('#book-container').offset().top
        }, 100);
    });
    
    $(document).on('click', '.publisher-item a, .comic-title a', function(e) {
        e.preventDefault();
        e.stopPropagation();
    
        const $item = $(this).closest('.publisher-item, .comic-title');
        const id = $item.data('publisher-id') || $item.data('series-id');
        const isPublisher = $item.hasClass('publisher-item');
    
        if (!id || isNaN(Number(id))) {
            console.warn("No valid ID found", { isPublisher, id });
            return;
        }
    
        showSpinner();
    
        let url;
        if (isPublisher) {
            url = `/comic-catalog/?publisher_id=${id}&letter=all&page=1`;
            currentPublisherId = Number(id); // if you use this global
        } else {
            url = `/comic-catalog/issues/?title_id=${id}&page=1`;
            currentSeriesId = Number(id);
        }
    
        setTimeout(() => {
            window.location.href = url;
        }, 100);
    });

    $(document).on('click', '.issue-item a.issue-link', function(e) {
        e.preventDefault();
    
        const $item = $(this).closest('.issue-item');
        const titleId = $item.data('title-id');
        const issueId = $item.data('issue-id');
    
        if (!titleId || !issueId) {
            console.warn("Missing title_id or issue_id on issue item");
            return;
        }
    
        showSpinner();
    
        // Build proper permalink-style URL
        const cleanUrl = new URL('/comic-catalog/issue/', location.origin);
        cleanUrl.searchParams.set('issue_id', issueId);
        cleanUrl.searchParams.set('title_id', titleId);         

        setTimeout(() => {
            window.location.href = cleanUrl.toString();   // or location.assign(cleanUrl)
        }, 120);
    });

    let collectionStatusRequestId = 0;

    function checkCollectionStatusBatch() {
        const requestId = ++collectionStatusRequestId;
        const buttons = $('.add-to-collection[data-issue-id]');
        const issueIds = [...new Set(
            buttons.map(function () {
                return Number($(this).attr('data-issue-id'));
            }).get().filter(id => Number.isInteger(id) && id > 0)
        )];

        if (!issueIds.length) return;

        $.ajax({
            url: comicbooks_fetchers_data.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'check_collection_status_batch',
                security: comicbooks_fetchers_data.nonce,
                issue_ids: issueIds
            },
            success: function (response) {
                if (
                    requestId !== collectionStatusRequestId ||
                    !response || !response.success || !response.data
                ) {
                    return;
                }

                buttons.each(function () {
                    const button = $(this);

                    if (!this.isConnected || button.prop('disabled')) return;

                    const issueId = Number(button.attr('data-issue-id'));
                    const status = response.data[issueId];
                    if (!status) return;

                    const postId = Number(status.post_id || 0);
                    const owned = Boolean(status.owned) && postId > 0;
                    const action = owned ? 'remove' : 'add';

                    button
                        .toggleClass('in-collection', owned)
                        .css({
                            'background-color': owned ? 'red' : '',
                            'color': owned ? 'white' : ''
                        })
                        .text(
                            owned
                                ? 'Remove from Collection'
                                : 'Add to My Collection'
                        )
                        .attr('data-action', action)
                        .data('action', action);

                    if (owned) {
                        button
                            .attr('data-post-id', postId)
                            .data('post-id', postId);
                    } else {
                        button
                            .removeAttr('data-post-id')
                            .removeData('post-id');
                    }
                });
            },
            error: function () {
                console.warn('Could not refresh collection status.');
            }
        });
    }

    $(document).on(
        'comicbooks:issues-rendered comicbooks:collection-changed',
        checkCollectionStatusBatch
    );

    checkCollectionStatusBatch();

    $(document).on('click', '.add-to-collection', async function(e) {
        e.preventDefault();
        const btn = $(this);
        const action = btn.data('action');
        const isRemove = action === 'remove';
        const originalText = btn.text();

        if (isRemove) {
            const confirmRemove = await toastConfirm('Remove this issue from your collection?');
            if (!confirmRemove) return;
        }

        btn.text(isRemove ? 'Removing...' : 'Adding...').prop('disabled', true);

        $.post(comicbooks_fetchers_data.ajax_url, {
            action: isRemove ? 'remove_comic_from_collection' : 'add_comic_to_collection',
            security: comicbooks_fetchers_data.nonce,
            post_id: isRemove ? btn.data('post-id') : 0,
            data: btn.data()
        }, function(response) {
            if (response.success) {
                if (isRemove) {
                    btn.text('Add to My Collection')
                        .removeClass('in-collection')
                        .css({'background-color': '', 'color': ''})
                        .data('action', 'add')
                        .removeAttr('data-post-id')
                        .removeData('post-id');
                } else {
                    btn.text('Remove from Collection')
                        .addClass('in-collection')
                        .css({'background-color': 'red', 'color': 'white'})
                        .data('action', 'remove')
                        .data('post-id', response.data.post_id);
                }

                $(document).trigger('comicbooks:collection-changed');
            } else {
                btn.text(isRemove ? 'Error Removing' : 'Error Adding');
                console.error('Error:', response.data);
                setTimeout(() => btn.text(originalText), 2000);
            }
        }).fail(function(jqXHR, textStatus, errorThrown) {
            console.error('AJAX request failed:', textStatus, errorThrown);
            btn.text(isRemove ? 'Error Removing' : 'Error Adding');
            setTimeout(() => btn.text(originalText), 2000);
        }).always(function() {
            btn.prop('disabled', false);
        });
    });

    // Popstate handler
    $(window).on('popstate', function(event) {
        const params = new URLSearchParams(window.location.search);
        const page = parseInt(params.get('page')) || 1;
        const letter = params.get('letter') || 'all';
        const search = params.get('search') || '';
        const rawPublisherId = params.get('publisher_id');
        const parsedPublisherId = parseInt(rawPublisherId, 10);

        const publisherId =
            Number.isInteger(parsedPublisherId) && parsedPublisherId > 0
                ? parsedPublisherId
                : null;

        const titleId = params.get('title_id') || null;
    
        // DO NOT run if we're already on the correct page
        if (currentPage === page && 
            currentLetter === letter && 
            currentSearch === search && 
            !!currentPublisherId === !!publisherId) {
            return;
        }    
  
        currentPage = page;
        currentLetter = letter;
        currentSearch = search;
        currentPublisherId = publisherId;
    
        if (titleId) {
            fetchIssues(titleId, page, search);      
        } else if (publisherId) {
            fetchBooks(publisherId, page, search, letter);
        } else {
            fetchPublishers(search, page, letter);
        }
    });

    // INITIAL LOAD
    // ==============================================================
    const urlParams   = new URLSearchParams(window.location.search);
    const urlLetter   = urlParams.get('letter') || 'all';
    const urlPage     = parseInt(urlParams.get('page')) || 1;
    const urlSearch   = urlParams.get('search') || '';
    const rawPublisherId = urlParams.get('publisher_id');
    const parsedPublisherId = parseInt(rawPublisherId, 10);

    const urlPubId =
        Number.isInteger(parsedPublisherId) && parsedPublisherId > 0
            ? parsedPublisherId
            : null;
    
    currentLetter = urlLetter;
    currentPage = urlPage;
    currentSearch = urlSearch;
    currentPublisherId = urlPubId;

    if (
        !window.location.pathname.includes('/issues/') &&
        comicbooks_fetchers_data?.items &&
        comicbooks_fetchers_data.items.length > 0
    ) {
        renderItems(
            comicbooks_fetchers_data.items,
            comicbooks_fetchers_data.type || 'publishers',
            urlPage,
            comicbooks_fetchers_data.total || 0,
            urlSearch,
            urlLetter,
            comicbooks_fetchers_data.per_page || 10
        );
        if (urlLetter) updateActiveLetter(urlLetter);
    }

    if(urlLetter){
        updateActiveLetter(urlLetter);
    }   

    // Check if server already rendered issues
    const titleId = urlParams.get('title_id');
    const issuePage = parseInt(urlParams.get('page')) || 1;
    const issueSearch = urlParams.get('search') || '';
    
    // Only run on issues page
    if (titleId && window.location.pathname.includes('/comic-catalog/issues/')) {
        $('#issue-search').val(issueSearch);
    } 
    if (window.__resumeFetchOnLoad) {
        const { 
            publisherId, 
            page, 
            search, 
            letter 
        } = window.__resumeFetchOnLoad;

        const normalizedPublisherId = parseInt(publisherId, 10);

        currentPublisherId =
            Number.isInteger(normalizedPublisherId) &&
            normalizedPublisherId > 0
                ? normalizedPublisherId
                : null;

            currentPage   = parseInt(page, 10) || 1;
            currentSearch = search || '';
            currentLetter = letter || 'all';
    
            showSpinner();

            $('#book-container')
            .empty()
            .attr('aria-busy', 'true')
            .css('visibility', 'hidden');
    
            if (currentPublisherId !== null) {
                fetchBooks(
                    currentPublisherId,
                    currentPage,
                    currentSearch,
                    currentLetter
                );
            } else {
                fetchPublishers(
                    currentSearch,
                    currentPage,
                    currentLetter
                );
            }

    } else if (window.__resumeScanOnLoad) {
        const { publisherId, page, search, letter } = window.__resumeScanOnLoad;
        currentPublisherId = publisherId;
        showSpinner();
        fetchBooks(publisherId, page, search, letter);
    }

});