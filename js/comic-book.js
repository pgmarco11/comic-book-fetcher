/**
 * comic-book.js
 * Handles issue loading, pagination, search for Comic Book Issues page
 * Uses jQuery + AJAX + wp_localize_script
 */
window.DEBUG = true;
console.log('%c COMIC-BOOK.JS LOADED — DEBUG MODE ON', 'color:cyan;font-size:14px');

jQuery(document).ready(function($){    
    let allPublishers = [];
    let allSeries = [];
    let currentPublisherId = null;
    let currentLetter = 'all';
    let currentPage = 1;
    let currentSearch = '';
    let isAjaxPending = false;
    let isInitialLoad = true;
    
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
    const CACHE_TTL = 24 * 60 * 60 * 1000; // 24 hours

    function getCachedData(key) {
        const cached = localStorage.getItem(key);
        if (!cached) return null;
        try {
            const { value, expiry } = JSON.parse(cached);
            if (Date.now() > expiry) {
                localStorage.removeItem(key);
                return null;
            }
            return value;
        } catch (e) {
            console.error('Cache error:', e);
            localStorage.removeItem(key);
            return null;
        }
    }

    function setCachedData(key, data) {
        const expiry = Date.now() + CACHE_TTL;
        try {
            localStorage.setItem(key, JSON.stringify({ value: data, expiry }));
        } catch (e) {
            console.warn('localStorage quota exceeded:', e);
        }
    }

    // ===================================================================
    // Spinner
    // ===================================================================
    let isFirstLoad = true;

    // hide items AND show spinner
    function showSpinner() {
        $('#items-wrapper').hide();        
        // Just show spinner on top
        $('#loading-spinner').css({display: 'flex'});
    }    

    // show items again AND hide spinner function
    function hideSpinner() {
        $('#items-wrapper').show();
        $('#loading-spinner').hide();
    }


    // UI updates
    function updateActiveLetter(letter) {
        $('.letter-btn').removeClass('active');
        $(`.letter-btn[data-letter="${letter}"]`).addClass('active');
        currentLetter = letter;

        const params = new URLSearchParams(window.location.search);
        params.set('letter', letter || 'all');
        if (!params.has('page') || currentPage === 1) {
            params.set('page', currentPage);
        }
        console.log('updateActiveLetter, updating URL to:', `${window.location.pathname}?${params.toString()}`);
        history.pushState({ page: parseInt(params.get('page')) || 1, letter }, '', `${window.location.pathname}?${params.toString()}`);
    }

    function showLetterButtons(show) {
        $('#letter-buttons').toggle(show);
    }

    function renderItems(items, type, page, total, search = '', letter = '', perPage) {
        console.log('%c RENDERITEMS() CALLED ', 'background:blue;color:white;font-size:16px', {
            itemsCount: items?.length || 0,
            type, page, total, search, letter, perPage
        });

        const isPublisher = type === 'publishers';
        const container   = $('#book-container');
    
        // ──────── SUPER DEBUG ────────
        const caller = new Error().stack.split('\n')[2].trim(); // who called us
        const urlParams             = new URLSearchParams(window.location.search);
        const urlPage = urlParams.get('page') || '1';
        const urlLetter = urlParams.get('letter') || 'all';

        const currentUrlPage        = parseInt(urlPage, 10);
        const currentUrlLetter      = urlLetter;
        const currentUrlPublisherId = urlParams.get('publisher_id');
    
        const debugMsg = `
            <strong>renderItems() called</strong><br>
            → page param   : ${page} (type: ${typeof page})<br>
            → URL page     : ${currentUrlPage}<br>
            → letter       : ${urlLetter} (URL: ${currentUrlLetter})<br>
            → publisher_id : ${currentPublisherId} (URL: ${currentUrlPublisherId})<br>
            → items count  : ${items?.length || 0}<br>
            → type         : ${type}<br>
            → called from  : ${caller.substring(0, 100)}...
        `;

        console.log('%c RENDER PROCEEDING — BUILDING HTML', 'color:green;font-weight:bold');  

        console.log(debugMsg);    

        const requestedPage = Number(page);

        const currentPage = currentUrlPage;
        const pageSize    = Number(perPage) || items.length;

        const startIndex = total === 0
            ? 0
            : (currentPage - 1) * pageSize + 1;

        const endIndex = Math.min(
            startIndex + items.length - 1,
            total
        );
            
        let html = `<!-- SPINNER -->
        <div id="loading-spinner" class="spinner-overlay" aria-live="polite">
            <div class="spinner"></div>
            <p>Loading...</p>
        </div>
        <div id="items-wrapper"><div class="${isPublisher ? 'publishers' : 'book'}-wrapper">`;
    
        if (!items || items.length === 0) {
            html += `<p>No ${isPublisher ? 'publishers' : 'series'} found.</p>`;
        } else {
            html += `<p>Showing ${startIndex}–${endIndex} of ${total} ${isPublisher ? 'publishers' : 'series'}${search ? ` for "${search}"` : letter && letter !== 'all' ? ` starting with "${letter}"` : ''}</p>`;
    
            items.forEach(item => {
                if (isPublisher) {
                    html += `
                        <div class="publisher-item" data-publisher-id="${item.id}">
                            <div class="publisher-image">
                                <img src="${item.image || comicbooks_fetchers_data.placeholder}" alt="${item.name}" loading="lazy">
                            </div>
                            <div class="publisher-info">
                                <h3>${item.name}</h3>
                                <p><strong>Founded:</strong> ${item.founded || 'N/A'}</p>
                                <p>${item.desc || 'No description available.'}</p>
                            </div>
                        </div>`;
                } else {
                    html += `
                        <div class="comic-title" data-series-id="${item.series_id}">
                            <div class="comic-image">
                                <img src="${comicbooks_fetchers_data.placeholder}" 
                                     data-src="${item.first_issue_image || ''}" 
                                     alt="${item.name}" 
                                     loading="lazy"
                                     class="lazy-placeholder">
                            </div>
                            <div class="comic-info">
                                <div class="comic-title-name">${item.name}</div>
                                <div class="comic-title-meta">
                                    <p>Vol. <span>${item.volume || 1}</span></p>
                                    <p>Issues: <span>${item.issue_count || 0}</span></p>
                                    <p>Started: <span>${item.year_began || 'N/A'}</span></p>
                                </div>
                            </div>
                        </div>`;
                }
            });
        }
    
        html += '</div>';
        html += renderPagination(requestedPage, Math.ceil(total / perPage), letter);
        html += '</div></div>';
    
        container.html(html);
        hideSpinner();
        lazyLoadImages();
    
        // Preload next page only when we actually rendered the current one
        const totalPages = Math.ceil(total / perPage);
        if (requestedPage < totalPages && comicbooks_fetchers_data.preload_enabled) {
            if (typeof debugLog === 'function') debugLog(`Preloading next page ${requestedPage + 1}`);
            preloadData(isPublisher ? 'publishers' : 'books', currentPublisherId, requestedPage + 1, search, letter);
        }
    }

    // ===================================================================
    // Render Pagination
    // ===================================================================
    function renderPagination(page, totalPages, letter = 'all') {  // ← default 'all'
        if (totalPages <= 1) return '';
        let html = '<div class="pagination-wrapper">';
        html += `<p>Page ${page} of ${totalPages}</p>`;
        if (page > 1) {
            html += `<button type="button" class="page-btn" data-page="${page - 1}" data-letter="${letter}">Previous</button>`;
        }
        for (let i = Math.max(1, page - 2); i <= Math.min(totalPages, page + 2); i++) {
            html += `<button type="button" class="page-btn${i === page ? ' active' : ''}" data-page="${i}" data-letter="${letter}">${i}</button>`;
        }
        if (page < totalPages) {
            html += `<button type="button" class="page-btn" data-page="${page + 1}" data-letter="${letter}">Next</button>`;
        }
        html += '</div>';
        return html;
    } 
    
    function renderIssuesPagination(page, totalPages, titleId, search = '', perPage) {
        if (totalPages <= 1) return '';
        let html = '<div class="pagination-wrapper">';
        html += `<p>Page ${page} of ${totalPages}</p>`;
        if (page > 1) {
            html += `<a href="${new URLSearchParams({title_id: titleId, issue_page: page - 1, search}).toString()}" class="page-btn" data-page="${page - 1}">Previous</a>`;
        }
        for (let i = Math.max(1, page - 2); i <= Math.min(totalPages, page + 2); i++) {
            html += `<a href="${new URLSearchParams({title_id: titleId, issue_page: i, search}).toString()}" class="page-btn${i === page ? 'active' : ''}" data-page="${i}">${i}</a>`;
        }
        if (page < totalPages) {
            html += `<a href="${new URLSearchParams({title_id: titleId, issue_page: page + 1, search}).toString()}" class="page-btn" data-page="${page + 1}">Next</a>`;
        }
        html += '</div>';
        return html;
    }

    let lazyLoadObserver = null;
    const BATCH_SIZE = 3; // Load 5 images at a time
    const seriesBatch = new Set();
    const publisherBatch = new Set();
    let batchTimeout = null;
    
    function processBatch() {
        if (batchTimeout) return; // Already processing
    
        batchTimeout = setTimeout(() => {
            const seriesIds = Array.from(seriesBatch).slice(0, BATCH_SIZE);
            const publisherIds = Array.from(publisherBatch).slice(0, BATCH_SIZE);
    
            // Remove processed IDs
            seriesIds.forEach(id => seriesBatch.delete(id));
            publisherIds.forEach(id => publisherBatch.delete(id));
    
            // Load series images
            if (seriesIds.length) {
                $.post(comicbooks_fetchers_data.ajax_url, {
                    action: 'load_series_images_batch',
                    series_ids: seriesIds,
                    nonce: comicbooks_fetchers_data.nonce
                }, response => {
                    if (response.success && response.data.images) {
                        seriesIds.forEach(id => {
                            const img = document.querySelector(`.comic-title[data-series-id="${id}"] img`);
                            if (img && response.data.images[id]) {                                
                                img.src = response.data.images[id];
                                img.removeAttribute('data-src');
                                img.classList.remove('lazy-placeholder');
                                img.dataset.loaded = 'true';
                            }
                        });
                    }
                });
            }
    
            // Load publisher images
            if (publisherIds.length) {
                $.post(comicbooks_fetchers_data.ajax_url, {
                    action: 'load_publisher_images_batch',
                    publisher_ids: publisherIds,
                    nonce: comicbooks_fetchers_data.nonce
                }, response => {
                    if (response.success && response.data.images) {
                        publisherIds.forEach(id => {
                            const img = document.querySelector(
                                `.publisher-item[data-publisher-id="${id}"] img, ` +
                                `.publisher-info[data-publisher-id="${id}"] img`
                            );
                            if (img && response.data.images[id]) {
                                img.src = response.data.images[id];
                                img.dataset.loaded = 'true';
                                img.removeAttribute('loading');
                            }
                        });
                    }
                });
            }
    
            batchTimeout = null;
    
            // Continue if more in queue
            if (seriesBatch.size > 0 || publisherBatch.size > 0) {
                processBatch();
            }
        }, 100); // Small delay to group nearby entries
    }
    
    function lazyLoadImages() {
        // Disconnect old observer
        if (lazyLoadObserver) {
            lazyLoadObserver.disconnect();
        }
    
        // Reset batches
        seriesBatch.clear();
        publisherBatch.clear();
        if (batchTimeout) clearTimeout(batchTimeout);
        batchTimeout = null;
    
        const images = document.querySelectorAll('img[loading="lazy"]:not([data-loaded="true"])');
    
        if (images.length === 0) return;
    
        lazyLoadObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    const comicTitle = img.closest('.comic-title');
                    const publisherItem = img.closest('.publisher-item');
                    const publisherInfo = img.closest('.publisher-info');
    
                    if (comicTitle) {
                        const id = comicTitle.dataset.seriesId;
                        if (id && !img.dataset.loaded) {
                            seriesBatch.add(id);
                        }
                    } else if (publisherItem || publisherInfo) {
                        const id = (publisherItem?.dataset.publisherId) || (publisherInfo?.dataset.publisherId);
                        if (id && !img.dataset.loaded) {
                            publisherBatch.add(id);
                        }
                    }
    
                    // Stop observing this image
                    lazyLoadObserver.unobserve(img);
    
                    // Trigger batch
                    if (!batchTimeout) {
                        processBatch();
                    }
                }
            });
        }, {
            rootMargin: '200px 0px 200px 0px', // Start loading 200px before viewport
            threshold: 0.01
        });
    
        images.forEach(img => lazyLoadObserver.observe(img));
    }

    // Centralized debounced AJAX handler
    const debouncedAjaxAction = debounce(function(action, data, callback, errorCallback) {
        if (isAjaxPending) {
            console.warn('AJAX request already in progress, queuing:', action, data);
            setTimeout(() => debouncedAjaxAction(action, data, callback, errorCallback), 500);
            return;
        }

        isAjaxPending = true;
        const container = action === 'load_issues' ? '#issues-list' : '#book-container'; 

        console.log('Sending AJAX request:', action, data);

        $.ajax({
            url: comicbooks_fetchers_data.ajax_url,
            method: 'POST',
            data: {
                action,
                nonce: comicbooks_fetchers_data.nonce,
                ...data
            },
            timeout: 30000,
            success: response => {
                console.log(`${action} response:`, response);
                if (response.success && response.data) {
                    callback(response);
                } else {
                    console.warn(`${action} failed:`, response);
                    $(container).html(`<p>No results found.</p>`);
                }
            },
            error: (xhr, status, error) => {
                console.error(`${action} AJAX error:`, status, error, xhr.responseText);
                $(container).html(`<p>${xhr.status === 429 ? 'Too many requests. Please wait.' : 'Failed to load results.'}</p>`);
                if (errorCallback) errorCallback(xhr, status, error);
            },
            complete: () => {
                console.log(`${action} AJAX complete`);
                isAjaxPending = false;                
            }
        });
    }, 500); // Increased to 500ms from 300ms

    // ===================================================================
    // Preload Next Page
    // ===================================================================
    function preloadData(type, publisherId, page, search, letter) {
        const cacheKey = type === 'publishers' ? 
        `metron_publishers_${search}_${page}_${letter}` : 
        `metron_books_${publisherId}_${page}_${search}_${letter}`;
        
        if (getCachedData(cacheKey)) {
            console.log(`Preload skipped: Cache exists for ${cacheKey}`);
            return;
        }

        console.log(`Preloading ${type} for page ${page}`);
        debouncedAjaxAction(
            type === 'publishers' ? 'load_publishers' : 'load_book',
            {
                name: search,
                page: page,
                letter: letter,
                publisher_id: publisherId || ''
            },
            response => {
                if (response.success && response.data) {
                    setCachedData(cacheKey, {
                        [type === 'publishers' ? 'publishers' : 'series']: response.data[type === 'publishers' ? 'publishers' : 'series'],
                        total: response.data.total,
                        max_pages: response.data.max_pages,
                        per_page: response.data.per_page
                    });
                    if (typeof debugLog === 'function') {
                        debugLog(`PRELOADED silently page ${page} (${response.data.series?.length || response.data.publishers?.length} items)`);
                    }
                }
            },
            error => {
                console.error(`Preload failed for ${type} page ${page}:`, error);
            }
        );
    }

    // Fetch publishers
    function fetchPublishers(name = '', page, letter = 'all') {
        
        // ADD RIGHT AT THE TOP
        console.log('%c FETCH PUBLISHERS CALLED ', 'background:red;color:white;font-size:14px', {
            name, page, letter,
            cacheKey: `metron_publishers_${name}_${page}_${letter}`
        });

        const cacheKey = `metron_publishers_${name}_${page}_${letter}`;
        const cached = getCachedData(cacheKey);

        showSpinner();

        if (cached) {
            console.log('%c CACHE HIT!', 'color:green;font-weight:bold', cached);

            allPublishers = cached.publishers || [];
            const total = cached.total || 0;  // ← MUST come from server, not guessed
            const perPage = 10;
        
            renderItems(allPublishers, 'publishers', page, total, name, letter, perPage);
            showLetterButtons(true);
            updateActiveLetter(letter);
            hideSpinner();
        
            // Preload next page on cache hit too!
            const totalPages = Math.ceil(total / perPage);
            if (page < totalPages && comicbooks_fetchers_data.preload_enabled) {
                preloadData('publishers', null, page + 1, name, letter);
            }
            return;
        }

        console.log('%c CACHE MISS → REAL AJAX CALL', 'color:orange;font-weight:bold');

        $.ajax({
            url: comicbooks_fetchers_data.ajax_url,
            method: 'POST',
            timeout: 30000,
            data: {
                action: 'load_publishers',
                nonce: comicbooks_fetchers_data.nonce,
                name: name,
                page: page,
                letter: letter
            },
            beforeSend: function() {
                console.log('%c AJAX → SENDING REQUEST FOR PAGE ' + page, 'color:purple;font-weight:bold');
            },
            success: function(response) {
                console.log('%c AJAX SUCCESS → RESPONSE:', 'color:green;font-weight:bold', response); 
        
                if (!response.success || !response.data?.publishers) {
                    console.error('BAD RESPONSE — NO PUBLISHERS!', response);
                    $('#book-container').html('<p>Failed to load publishers.</p>');
                    hideSpinner();
                    return;
                }
        
                const publishers = response.data.publishers || [];
                const total = response.data.total || 0;
        
                // Empty result + retries → retry
                if (publishers.length === 0 && typeof retries !== 'undefined' && retries > 0) {
                    console.warn(`Empty publishers for page ${page}, retrying... (${retries} left)`);
                    setTimeout(() => fetchPublishers(name, page, letter, retries - 1), 1000);
                    return;
                }
        
                allPublishers = publishers;
                setCachedData(cacheKey, { 
                    publishers, 
                    total, 
                    maxPages: Math.ceil(total / 10) 
                });
        
                renderItems(allPublishers, 'publishers', page, total, name, letter, 10);
                showLetterButtons(true);
                updateActiveLetter(letter);
                hideSpinner();
        
                // Safe to preload next page now
                const totalPages = Math.ceil(total / 10);
                if (page < totalPages && comicbooks_fetchers_data.preload_enabled) {
                    preloadData('publishers', null, page + 1, name, letter);
                }
            },
            error: function(xhr) {
                console.error('AJAX FAILED', xhr.status, xhr.responseText);
        
                if (typeof retries !== 'undefined' && retries > 0) {
                    console.warn('Retrying fetchPublishers...', retries, 'left');
                    setTimeout(() => fetchPublishers(name, page, letter, retries - 1), 2000);
                } else {
                    $('#book-container').html('<p>Error loading publishers for page ' + page + '. <button onclick="location.reload()">Retry</button></p>');
                    hideSpinner();
                }
            }
        });
    }

    // Fetch books
    function fetchBooks(publisherId, page = 1, name = '', letter = 'all') {

        console.log("publisher ID: ", publisherId);

        console.log("name: ", name);


        if (!publisherId && !name) {
            $('#book-container').html('<p>Please select a publisher or enter a search term.</p>');
            showLetterButtons(false);
            updateActiveLetter(letter);
            hideSpinner();
            return;
        }

        console.log("Fetching books for publisher:", publisherId, "letter:", letter);

        const cacheKey = `metron_books_${publisherId}_${page}_${name}_${letter}`;
        const cached = getCachedData(cacheKey);

        showSpinner();

        if (cached) {
            allSeries = cached.series || [];
            const perPage = cached.perPage || cached.per_page || 10;
            const total = cached.total || 0;  // ← critical: use real total
        
            renderItems(allSeries, 'books', page, total, name, letter, perPage);
            showLetterButtons(true);
            updateActiveLetter(letter);
            hideSpinner();
        
            const totalPages = Math.ceil(total / perPage);
            if (page < totalPages && comicbooks_fetchers_data.preload_enabled) {
                preloadData('books', currentPublisherId, page + 1, name, letter);
            }
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
                console.log('Sending REAL request for page', page);
            },
            success: response => {
                console.log('REAL load_book response (page ' + page + '):', response);
                if (response.success && response.data) {
                    allSeries = response.data.series || [];
                    const perPage = response.data.per_page || 10;
                    const total = response.data.total || 0;
        
                    setCachedData(cacheKey, { series: allSeries, total, perPage });
                    renderItems(allSeries, 'books', page, total, name, letter, perPage);
                    showLetterButtons(true);
                    updateActiveLetter(letter);
                    hideSpinner();
        
                    // NOW safe to preload next
                    const totalPages = Math.ceil(total / perPage);
                    if (page < totalPages && comicbooks_fetchers_data.preload_enabled) {
                        preloadData('books', currentPublisherId, page + 1, name, letter);
                    }
                }
            },
            error: (xhr) => {
                console.error('REAL load failed:', xhr.responseText);
                hideSpinner();
            }
        });
    }

    // CSS for skeleton loader and minimum height
    const styles = `      
        #loading-spinner {
            position: relative;
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

    function fetchIssues(titleId, page = 1, search = '', retries = 3) {
        console.log(`fetchIssues: titleId=${titleId}, page=${page}, search='${search}', retries=${retries}`);
        if (!titleId) {
            $('#issues-list').html('<p>No series selected.</p>').addClass('loaded');    
            $('#pagination-wrapper').empty();
            console.log('fetchIssues: No titleId, hiding spinner');
            return;
        }
    
   
        $('#issues-list').removeClass('loaded').html(''); // Clear content
    
        const cacheKey = `metron_issues_${titleId}_${page}_${search}`;
        const cached = getCachedData(cacheKey);
        if (cached) {
            console.log('fetchIssues: Cache hit, rendering cached data');
            setTimeout(() => {
                const perPage = cached.per_page || 10;
                const totalIssues = cached.total_issues || 0;
                const totalPages = Math.ceil(totalIssues / perPage);
                $('#issues-list').html(cached.issues).addClass('loaded');
                $('#pagination-wrapper').html(renderIssuesPagination(cached.current_page, totalPages, titleId, search, perPage));
                updateIssuesUrl(titleId, cached.current_page, search);
                lazyLoadImages();
                console.log('fetchIssues: Hiding spinner after cache render');
                        if (cached.current_page < totalPages) {
                    preloadIssues(titleId, cached.current_page + 1, search);
                }
            }, 300); // Increased delay for visibility
            return;
        }
    
        $.ajax({
            url: comicbooks_fetchers_data.ajax_url,
            method: 'POST',
            data: {
                action: 'load_issues',
                nonce: comicbooks_fetchers_data.nonce,
                title_id: titleId,
                page: page,
                search: search || ''
            },
            timeout: 45000,
            success: response => {
                console.log('fetchIssues: AJAX response:', response);
                setTimeout(() => {
                    if (response.success && response.data) {
                        const perPage = response.data.per_page || 10;
                        const totalIssues = response.data.total_issues || 0;
                        const totalPages = response.data.total_pages || Math.ceil(totalIssues / perPage);
                        const currentPage = response.data.current_page || page;
                        const issuesHtml = response.data.issues || '<p>No issues found.</p>';
    
                        $('#issues-list').html(issuesHtml).addClass('loaded');
                        $('#pagination-wrapper').html(renderIssuesPagination(currentPage, totalPages, titleId, search, perPage));
                        updateIssuesUrl(titleId, currentPage, search);
                        lazyLoadImages();
                        console.log('fetchIssues: Hiding spinner after AJAX render');                
                        if (currentPage < totalPages && comicbooks_fetchers_data.preload_enabled) {
                            preloadIssues(titleId, currentPage + 1, search);
                        }
                    } else {
                        console.error('fetchIssues: AJAX error:', response.data?.message || 'Failed to load issues');
                        $('#issues-list').html('<p>' + (response.data?.message || 'Failed to load issues. Please try again.') + '</p>').addClass('loaded');
                        $('#pagination-wrapper').empty();
                        console.log('fetchIssues: Hiding spinner after error');                    
                    }
                }, 300); // Increased delay for visibility
            },
            error: (xhr, status, error) => {
                console.error('fetchIssues: AJAX failure:', status, error, xhr.responseText);
                if ((xhr.status === 429 || status === 'timeout') && retries > 0) {
                    console.log(`Retrying fetchIssues, retries left: ${retries - 1}`);
                    setTimeout(() => fetchIssues(titleId, page, search, retries - 1), 2000);
                } else {
                    $('#issues-list').html('<p>Error loading issues. Please try again later.</p>').addClass('loaded');
                    $('#pagination-wrapper').empty();
                    console.log('fetchIssues: Hiding spinner after failure');             
                }
            }
        });
    }

    // ===================================================================
    // MAIN: Load Issues
    // ===================================================================
    function preloadIssues(titleId, page, search) {
        const cacheKey = `metron_issues_${titleId}_${page}_${search}`;
        if (getCachedData(cacheKey)) {
            console.log(`Preload skipped: Cache exists for ${cacheKey}`);
            return;
        }

        console.log(`Preloading issues for page ${page}`);
        $.ajax({
            url: comicbooks_fetchers_data.ajax_url,
            method: 'POST',
            data: {
                action: 'load_issues',
                nonce: comicbooks_fetchers_data.nonce,
                title_id: titleId,
                page: page,
                search: search || ''
            },
            timeout: 30000,
            success: response => {
                if (response.success && response.data) {
                    setCachedData(cacheKey, {
                        issues: response.data.issues,
                        total_issues: response.data.total_issues,
                        total_pages: response.data.total_pages,
                        current_page: response.data.current_page,
                        per_page: response.data.per_page
                    });
                    console.log(`Preloaded issues for page ${page}`);
                }
            },
            error: (xhr, status, error) => {
                console.error(`Preload failed for page ${page}:`, status, error);
            }
        });
    }
    
    function renderIssuesPagination(page, totalPages, titleId, search = '', perPage) {
        console.log(`renderIssuesPagination: page=${page}, totalPages=${totalPages}, titleId=${titleId}, search='${search}', perPage=${perPage}`);
        let html = '<div class="pagination-wrapper">';
        html += `<p>Page ${page} of ${totalPages}</p>`;
        if (page > 1) {
            html += `<button type="button" class="page-btn" data-page="${page - 1}" data-title-id="${titleId}" data-search="${search}">Previous</button>`;
        }
        for (let i = Math.max(1, page - 2); i <= Math.min(totalPages, page + 2); i++) {
            html += `<button type="button" class="page-btn${i === page ? 'active' : ''}" data-page="${i}" data-title-id="${titleId}" data-search="${search}">${i}</button>`;
        }
        if (page < totalPages) {
            html += `<button type="button" class="page-btn" data-page="${page + 1}" data-title-id="${titleId}" data-search="${search}">Next</button>`;
        }
        html += '</div>';
        return html;
    }

    // ===================================================================
    // Update URLs
    // ===================================================================
    function updateIssuesUrl(titleId, page, search) {
        const url = new URL(window.location);
        url.searchParams.set('title_id', titleId);
        url.searchParams.set('issue_page', page);
        if (search) {
            url.searchParams.set('search', search);
        } else {
            url.searchParams.delete('search');
        }
        console.log('updateIssuesUrl:', url.toString());
        history.pushState({ title_id: titleId, page, search }, '', url);
    }

    function updatePublisherUrl(letter, page, search) {
        const url = new URL(window.location);
        url.searchParams.set('letter', letter);
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
    if ($('#issues-list .issues-list').children().length > 0) {
        console.log('Issues list already populated, hiding spinner');
           $('#issues-list .issues-list').addClass('loaded');
    } else {
        console.log('Issues list empty, keeping spinner visible');
        // Trigger fetchIssues if title_id is present
        const urlParams = new URLSearchParams(window.location.search);
        const titleId = urlParams.get('title_id');
        const page = parseInt(urlParams.get('issue_page')) || 1;
        const search = urlParams.get('search') || '';
        if (titleId) {
            fetchIssues(titleId, page, search);
        } else {     
            $('#issues-list').html('<p>No series selected.</p>');
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

    $('#issue-search').on('input', debounce(function() {
        const searchQuery = $(this).val().trim();
        currentSearch = searchQuery;
        currentPage = 1;
        if (comicbooks_fetchers_data && comicbooks_fetchers_data.title_id) {
            fetchIssues(comicbooks_fetchers_data.title_id, 1, searchQuery);
        }
    }, 500));

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

    $(document).on('click', '.publisher-item', debounce(function() {
        const publisherId = $(this).data('publisher-id'); 
        $('#publisher-select').val(publisherId).trigger('change');  
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

    $(document).on('click', '.page-btn', function(e) {
        e.preventDefault();
          
        // ADD THESE LINES
        console.clear(); // Clear noise
        console.log('PAGE BTN CLICKED → START DEBUG TRACE');
        console.log('→ Button data:', {
            page: $(this).attr('data-page'),
            letter: $(this).attr('data-letter') || 'all',
            'data-letter exists?': $(this).attr('data-letter') !== undefined
        });
        console.log('→ Current state before click:', {
            currentPage,
            currentLetter,
            currentPublisherId,
            currentSearch
        });
        console.log('→ URL at click:', window.location.href);
        console.trace();

        const page = parseInt($(this).attr('data-page'));
        const letter = $(this).attr('data-letter') || currentLetter;   

        const titleId = $(this).data('title-id');
        const search = $(this).data('search') || currentSearch;    
        const isIssuesPage = window.location.pathname.includes('/comic-books/issues/');
    
        currentPage = page;
        currentLetter = letter;
    
        if (isIssuesPage && titleId) {
            console.log("if clicked page button for page ",page);
            console.log("if clicked page button for title ",titleId);
            updateIssuesUrl(titleId, page, search);
            fetchIssues(titleId, page, search);
            $('html, body').animate({ scrollTop: $('#issues-list').offset().top }, 100);
        } else {
            currentPage = page;
            updatePublisherUrl(letter, page, currentSearch);
            if (currentPublisherId) {
                fetchBooks(currentPublisherId, page, currentSearch, letter);
            } else {
                fetchPublishers(currentSearch, page, letter);
            }
            $('html, body').animate({ scrollTop: $('#book-container').offset().top }, 100);
        }
    });
    

    $(document).on('click', '.comic-title', debounce(function(e) {
        e.preventDefault();
        const seriesId = $(this).data('series-id');
        if (seriesId) {
            const url = new URL(window.location.origin + '/comic-books/issues/');
            url.searchParams.set('title_id', seriesId);
            url.searchParams.set('issue_page', 1);
    
            // Show loading state immediately
            $('#book-container').html(`
                <div id="loading-spinner" class="spinner-overlay" aria-busy="true" aria-label="Loading comic issues">
                    <div class="spinner"></div>
                    <p>Loading series...</p>
                </div>
            `);
    
            window.location.href = url.toString();
        }
    }, 300));

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
            data: btn.data()
        }, function(response) {
            if (response.success) {
                if (isRemove) {
                    btn.text('Add to My Collection')
                       .removeClass('in-collection')
                       .css({'background-color': '', 'color': ''})
                       .data('action', 'add')
                       .removeData('post-id');
                } else {
                    btn.text('Remove from Collection')
                       .addClass('in-collection')
                       .css({'background-color': 'red', 'color': 'white'})
                       .data('action', 'remove')
                       .data('post-id', response.data.post_id);
                }
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

    $(document).on('click', '.add-to-wishlist', async function(e) {
        e.preventDefault();
        const btn = $(this);
        const originalText = btn.text();
        btn.text('Adding...').prop('disabled', true);

        $.post(comicbooks_fetchers_data.ajax_url, {
            action: 'add_to_wishlist',
            security: comicbooks_fetchers_data.nonce,
            data: btn.data()
        }, function(response) {
            if (response.success) {
                btn.text('Added to Wishlist');
            } else {
                btn.text('Error Adding');
                console.error('Wishlist error:', response.data);
                setTimeout(() => btn.text(originalText), 2000);
            }
        }).fail(function(jqXHR, textStatus, errorThrown) {
            console.error('Wishlist AJAX failed:', textStatus, errorThrown);
            btn.text('Error Adding');
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
        const publisherId = params.get('publisher_id') || null;
        const titleId = params.get('title_id') || null;
    
        // DO NOT run if we're already on the correct page
        if (currentPage === page && 
            currentLetter === letter && 
            currentSearch === search && 
            !!currentPublisherId === !!publisherId) {
            console.log('popstate: NO CHANGE — skipping fetch');
            return;
        }
    
        console.log('popstate: State changed — fetching new data');
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

    // ===================================================================
    // INITIAL LOAD
    // ===================================================================

    console.log('%c INITIAL LOAD DEBUG START ', 'background:black;color:cyan;font-size:16px');

    const urlParams   = new URLSearchParams(window.location.search);
    const urlLetter   = urlParams.get('letter') || 'all';
    const urlPage     = parseInt(urlParams.get('page')) || 1;
    const urlSearch   = urlParams.get('search') || '';
    const urlPubId    = urlParams.get('publisher_id') || null;

    console.log('URL PARAMS:', { urlLetter, urlPage, urlSearch, urlPubId });
    
    currentLetter = urlLetter;
    currentPage = urlPage;
    currentSearch = urlSearch;
    currentPublisherId = urlPubId;

    // ALWAYS render the items – even on page 1
    // This is the ONLY way to guarantee #book-container is never empty
    renderItems(
        comicbooks_fetchers_data?.items || [],
        comicbooks_fetchers_data?.type || 'publishers',
        urlPage,
        comicbooks_fetchers_data?.total || 0,
        urlSearch,
        urlLetter,
        comicbooks_fetchers_data?.per_page || 10
    );

    // Enhance UI
    updateActiveLetter(urlLetter);
    lazyLoadImages();

    // Preload next page
    const total = comicbooks_fetchers_data?.total || 0;
    const perPage = comicbooks_fetchers_data?.per_page || 10;
    if (urlPage < Math.ceil(total / perPage) && comicbooks_fetchers_data?.preload_enabled) {
        preloadData(
            currentPublisherId ? 'books' : 'publishers',
            currentPublisherId || null,
            urlPage + 1,
            currentSearch,
            currentLetter
        );
    }

    // Check if server already rendered issues
    const titleId = urlParams.get('title_id');
    const issuePage = parseInt(urlParams.get('issue_page')) || 1;
    const issueSearch = urlParams.get('search') || '';
    
    // Only run on issues page
    if (titleId && window.location.pathname.includes('/comic-books/issues/')) {
        console.log('Issues page detected – initializing');
        $('#issue-search').val(issueSearch);
        
        // If server already rendered, just clean up
        if ($('#issues-list .issues-list').children().length > 0) {     
            $('#issues-list').addClass('loaded');
            updateIssuesUrl(titleId, issuePage, issueSearch);
            lazyLoadImages();
        } else {
            fetchIssues(titleId, issuePage, issueSearch);
        }
    }
    
    // After initial render, warm cache if empty
    if (currentPublisherId && comicbooks_fetchers_data.total === 0) {
        console.log('Warming cache for publisher:', currentPublisherId);
        $.post(comicbooks_fetchers_data.ajax_url, {
            action: 'warm_series_cache',
            publisher_id: currentPublisherId,
            nonce: comicbooks_fetchers_data.nonce
        });
    }     
});