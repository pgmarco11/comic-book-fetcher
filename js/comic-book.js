/**
 * comic-book.js
 * Handles issue loading, pagination, search for Comic Book Issues page
 * Uses jQuery + AJAX + wp_localize_script
 */
window.DEBUG = true;

jQuery(document).ready(function($){    
    if (window.location.pathname.includes('/issues/')) {  
        setTimeout(() => {
            const $list = $('#issues-list');             
            const hasContent = $list.find('li.issue-item, .no-results, ul.issues-list').length > 0 || $list.text().trim().length > 50;
    
            console.log('Initial server-render check:', {
                hasContent,
                classes: $list.attr('class'),
                spinnerDisplay: $('#loading-spinner').css('display')
            });
    
            if ($list.hasClass('server-rendered') && hasContent) {
                console.log('Server content detected — hiding spinner immediately');
                $list.addClass('loaded');
                $('#loading-spinner').addClass('hidden').css({ display: 'none', opacity: 0 });
                lazyLoadImages();
        
                // Render pagination if missing
                const total = parseInt($list.attr('data-total')) || 0;
                const page = parseInt($list.attr('data-page')) || 1;
                const search = $('#issue-search').val() || '';
                const titleId = new URLSearchParams(location.search).get('title_id');
                if (total > 0 && titleId) {
                    renderIssuePagination(titleId, page, search, total);
                }
            } else {
                console.log('No reliable server content — falling back to AJAX fetch');
                $list.removeClass('server-rendered loaded');
                const urlParams = new URLSearchParams(window.location.search);
                const titleId = parseInt(urlParams.get('title_id'));
                const page = parseInt(urlParams.get('page')) || 1;
                const search = urlParams.get('search') || '';
                if (titleId) {
                    showSpinner();
                    fetchIssues(titleId, page, search);
                }
            }
        }, 300);  // 300ms - adjust to 1000 if still failing
    
    }
      
    let currentPublisherId = null;
    let currentLetter = 'all';
    let currentPage = 1;
    let currentSearch = '';
    
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

    function clearCachedData(key) {
        try {
            localStorage.removeItem(key);
            console.log('Cache cleared:', key);
        } catch (e) {
            console.warn('Failed to clear cache:', key, e);
        }
    }

    // ===================================================================
    // Spinner
    // ===================================================================    
    function showSpinner() {
        $('#loading-spinner')
            .removeClass('hidden')
            .addClass('visible')
            .css({ opacity: 0 })
            .animate({ opacity: 1 }, 350);    
    }
    function hideSpinner() {
        $('#loading-spinner').animate({ opacity: 0 }, 350, function() {
            $(this)
                .removeClass('visible')
                .addClass('hidden')
                .css('display', 'none');
        });    
    }

    // UI updates
    function updateActiveLetter(letter) {
   
        if (window.location.pathname.includes('/issues/') || window.location.pathname.includes('/issue/')) {
            console.log("[updateActiveLetter] Skipped - issue or issues page");
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
        console.log('updateActiveLetter updating URL to:', newUrl);
        history.pushState({ page: parseInt(params.get('page')) || 1, letter }, '', newUrl);
    }

    function showLetterButtons(show) {
        $('#letter-buttons').toggle(show);
    }

    function renderItems(items, type, page, total, search = '', letter = '', perPage) {
        const isPublisher = type === 'publishers';
        const container   = $('#book-container');  

        const urlParams             = new URLSearchParams(window.location.search);
        const urlPage = urlParams.get('page') || '1';         
        const requestedPage = Number(page);      
        const pageSize    = Number(perPage) || items.length;
        const currentPage = parseInt(urlPage, 10);

        const startIndex = total === 0
            ? 0
            : (currentPage - 1) * pageSize + 1;

        const endIndex = Math.min(
            startIndex + items.length - 1,
            total
        );
            
        let html = `<div id="items-wrapper"><div class="${isPublisher ? 'publishers' : 'book'}-wrapper">`;
    
        if (!items || items.length === 0) {
            html += `<p>No ${isPublisher ? 'publishers' : 'series'} found.</p>`;
        } else {
            html += `<p>Showing ${startIndex}–${endIndex} of ${total} ${isPublisher ? 'publishers' : 'series'}${search ? ` for "${search}"` : letter && letter !== 'all' ? ` starting with "${letter}"` : ''}</p>`;
    
            items.forEach(item => {
                if (isPublisher) {
                    html += `
                    <div class="publisher-item" data-publisher-id="${item.id}">
                        <a href="/comic-catalog/?publisher_id=${item.id}&letter=all&page=1">
                            <div class="publisher-image">
                                    <img src="${item.image || comicbooks_fetchers_data.placeholder}" 
                                     alt="${item.name}" 
                                     loading="lazy">
                            </div>
                            <div class="publisher-info">
                                    <h3>${item.name}</h3>
                                    <p><strong>Founded:</strong> ${item.founded || 'N/A'}</p>
                                    <p>${item.desc || 'No description available.'}</p>
                            </div>
                        </a>
                    </div>`;
                } else {
                    html += `
                    <div class="comic-title" data-series-id="${item.series_id}">
                            <a href="/comic-catalog/issues/?title_id=${item.series_id}&page=1">
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
                            </a>
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
    }

    // ===================================================================
    // Render Pagination
    // ===================================================================
    function renderPagination(page, totalPages, letter = 'all') {
        if (totalPages <= 1) return '';
    
        // Use current browser URL as base (safest way to preserve publisher_id, search, etc.)
        const currentUrl = new URL(window.location.href);
        const params = currentUrl.searchParams;
    
        let html = '<div class="pagination-wrapper">';
        html += `<p>Page ${page} of ${totalPages}</p>`;
    
        // Helper: create link for any target page
        function getPageLink(targetPage) {
            const newParams = new URLSearchParams(params); // copy current params
            newParams.set('page', targetPage);
            newParams.set('letter', letter);
        
            // If you want to force-remove letter when it's 'all', keep the line above    
            return `${currentUrl.pathname}?${newParams.toString()}`;
        }
    
        if (page > 1) {
            html += `
                <a href="${getPageLink(page - 1)}"
                   class="page-btn"
                   data-page="${page - 1}"
                   data-letter="${letter}">
                    Previous
                </a>`;
        }
    
        const start = Math.max(1, page - 2);
        const end   = Math.min(totalPages, page + 2);
    
        for (let i = start; i <= end; i++) {
            const isActive = i === page;
            const href = isActive ? '#' : getPageLink(i);
    
            html += `
                <a href="${href}"
                   class="page-btn${isActive ? ' active' : ''}"
                   data-page="${i}"
                   data-letter="${letter}">
                    ${i}
                </a>`;
        }
    
        if (page < totalPages) {
            html += `
                <a href="${getPageLink(page + 1)}"
                   class="page-btn"
                   data-page="${page + 1}"
                   data-letter="${letter}">
                    Next
                </a>`;
        }
    
        html += '</div>';
        return html;
    }

    let lazyLoadObserver = null;
    const BATCH_SIZE = 3; // Load 3 images at a time
    const seriesBatch = new Set();
    const publisherBatch = new Set();
    let batchTimeout = null;
    
    function processBatch() {
        if (batchTimeout) return;
    
        batchTimeout = setTimeout(() => {
            const seriesIds = Array.from(seriesBatch).slice(0, BATCH_SIZE);
            const publisherIds = Array.from(publisherBatch).slice(0, BATCH_SIZE);
    
            // Remove processed IDs
            seriesIds.forEach(id => seriesBatch.delete(id));
            publisherIds.forEach(id => publisherBatch.delete(id));
    
            // Load series images (optional AJAX)
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
    
            // Load publisher images (keep this, optimized)
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
                                img.removeAttribute('loading'); // Let it render immediately
                                img.dataset.loaded = 'true';
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
        }, 50); // Shorter delay for faster batching
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
            rootMargin: '400px 0px 400px 0px', // Start loading 400px before viewport
            threshold: 0.01
        });
    
        images.forEach(img => lazyLoadObserver.observe(img));
    } 
    
    // Initialize
    document.addEventListener('DOMContentLoaded', lazyLoadImages);

    // Fetch publishers
    function fetchPublishers(name = '', page, letter = 'all', retries = 3) {
        showSpinner();    
        const cacheKey = `metron_publishers_${name}_${page}_${letter}`;
        const cached = getCachedData(cacheKey);  

        if (cached) {   
            allPublishers = cached.publishers || [];
            const total = cached.total || 0;  // ← MUST come from server, not guessed
            const perPage = 10;
        
            renderItems(allPublishers, 'publishers', page, total, name, letter, perPage);
            showLetterButtons(true);
            updateActiveLetter(letter);
            hideSpinner();        
   
            return;
        }
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
            success: function(response) {           
        
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
                
                setCachedData(cacheKey, { 
                    publishers, 
                    total, 
                    maxPages: Math.ceil(total / 10) 
                });
        
                renderItems(publishers, 'publishers', page, total, name, letter, 10);
                showLetterButtons(true);
                updateActiveLetter(letter);
                hideSpinner();        
       
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
        showSpinner();  
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
        if (cached) {
            allSeries = cached.series || [];
            const perPage = cached.perPage || cached.per_page || 10;
            const total = cached.total || 0;          
            renderItems(allSeries, 'books', page, total, name, letter, perPage);
            showLetterButtons(true);
            updateActiveLetter(letter);
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
        if (!totalIssues || totalIssues < 1) {
            $('#pagination-wrapper').empty();
            return;
        }
    
        const perPage = 10;
        const totalPages = Math.ceil(totalIssues / perPage);
    
        let html = '<div class="pagination-wrapper">';
        html += `<p>Page ${page} of ${totalPages}</p>`;
    
        const searchParam = search ? `&search=${encodeURIComponent(search)}` : '';
    
        if (page > 1) {
            const prev = page - 1;
            html += `
                <a href="?title_id=${titleId}&page=${prev}${searchParam}"
                   class="page-btn"
                   data-page="${prev}"
                   data-title-id="${titleId}"
                   data-search="${search}">
                    Previous
                </a>`;
        }
    
        const start = Math.max(1, page - 2);
        const end = Math.min(totalPages, page + 2);
    
        for (let i = start; i <= end; i++) {
            const active = i === page ? ' active' : '';
            const href = i === page ? '' : `?title_id=${titleId}&page=${i}${searchParam}`;
    
            html += `
                <a href="${href}"
                   class="page-btn${active}"
                   data-page="${i}"
                   data-title-id="${titleId}"
                   data-search="${search}">
                    ${i}
                </a>`;
        }
    
        if (page < totalPages) {
            const next = page + 1;
            html += `
                <a href="?title_id=${titleId}&page=${next}${searchParam}"
                   class="page-btn"
                   data-page="${next}"
                   data-title-id="${titleId}"
                   data-search="${search}">
                    Next
                </a>`;
        }
    
        html += '</div>';
    
        $('#pagination-wrapper').html(html);
    
        // Still update URL for clean browser history
        updateIssuesUrl(titleId, page, search);
    }

    function fetchIssues(titleId = null, page = null, search = '', retries = 3) {
        console.log(`fetchIssues: titleId=${titleId}, page=${page}, search='${search}'`);
        showSpinner();
        // Resolve params from URL if needed
        if (titleId === null || page === null) {
            const url = new URL(window.location.href);
            const urlTitleId = url.searchParams.get('title_id');
            const urlPage = url.searchParams.get('page');

            titleId = titleId ?? (urlTitleId ? parseInt(urlTitleId, 10) : null);
            page = page ?? (urlPage ? parseInt(urlPage, 10) : 1);

            if (!titleId || isNaN(titleId)) {
                $('#issues-list').html('<p>No series selected.</p>').addClass('loaded');
                $('#pagination-wrapper').empty();
                hideSpinner();
                return;
            }

            if (!page || isNaN(page) || page < 1) {
                page = 1;
            }
        }

        const cacheKey = `metron:issue_list_html:${titleId}:${page}:${search}`;
        const cached = getCachedData(cacheKey);    

        if (cached) {
            if (!cached.html || cached.html.length === 0) {
                console.warn('Invalid cached issues HTML — refetching', cacheKey);
                clearCachedData(cacheKey); 
            } else {
                $('#issues-list')
                    .removeClass('server-rendered')
                    .html(cached.html)
                    .attr('data-total', cached.total)
                    .attr('data-page', page)
                    .addClass('loaded')
                    .css('opacity', '1');
        
                renderIssuePagination(titleId, page, search, cached.total);
        
                lazyLoadImages();
                hideSpinner();  
        
                return;
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
                console.log('AJAX load_issues RESPONSE RECEIVED:', response);
            
                if (!response || response.success !== true) {
                    console.error('AJAX failed - no success flag', response);
                    $('#issues-list').html('<p class="no-results">AJAX load failed (no success)</p>').addClass('loaded');
                    hideSpinner();
                    return;
                }
            
                const data = response.data || {};
                const html = data.issues || '';
            
                console.log('Extracted HTML length:', html.length);
                console.log('Page:', data.current_page, 'Total issues:', data.total_issues);
            
                if (typeof html !== 'string' || html.trim() === '') {
                    console.error('No valid HTML in response - empty or wrong format', data);
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
            
                renderIssuePagination(titleId, currentPageFromResponse, search, data.total_issues || 0);
            
                if (html.length > 0) {
                    setCachedData(cacheKey, {
                        html,
                        total: data.total_issues || 0,
                        total_pages: totalPagesFromResponse
                    });
                }
            
                lazyLoadImages();        
                hideSpinner();  // always call at the end
            },

            error(xhr, status) {
                hideSpinner();

                if ((xhr.status === 429 || status === 'timeout') && retries > 0) {
                    setTimeout(
                        () => fetchIssues(titleId, page, search, retries - 1),
                        2000
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
            console.log("[updateIssuesUrl] Skipped — only on issues page");
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
    if ($('#issues-list .issues-list').children().length > 0) {
        console.log('Issues list already populated, hiding spinner');
           $('#issues-list .issues-list').addClass('loaded');
    } else {
        console.log('Issues list empty, keeping spinner visible');
        // Trigger fetchIssues if title_id is present
        const urlParams = new URLSearchParams(window.location.search);
        const titleId = urlParams.get('title_id');
        const page = parseInt(urlParams.get('page')) || 1;
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
            eturn;
        }  
        if (e.ctrlKey || e.metaKey || e.which === 2) {
            // Let browser open in new tab / normal navigation
            return;
        }

        e.preventDefault(); 
        showSpinner();

        const $btn = $(this);
    
        const page      = Number($link.data('page'));
        const titleId   = Number($btn.data('title-id')) || null;
        const letter    = $btn.data('letter') ?? currentLetter;
        const search    = $btn.data('search') ?? currentSearch;
    
        const isIssuesPage = window.location.pathname.includes('/comic-catalog/issues/');
        const hasTitleId   = !!titleId;
        const hasPublisher = !!currentPublisherId;
    
        if (!page || isNaN(page)) {
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

    // INITIAL LOAD
    // ==============================================================
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

    if (!window.location.pathname.includes('/issues/')) {
        renderItems(
            comicbooks_fetchers_data?.items || [],
            comicbooks_fetchers_data?.type || 'publishers',
            urlPage,
            comicbooks_fetchers_data?.total || 0,
            urlSearch,
            urlLetter,
            comicbooks_fetchers_data?.per_page || 10
        );
        if (urlLetter) updateActiveLetter(urlLetter);
    }

    if(urlLetter){
        updateActiveLetter(urlLetter);
    }
    
    lazyLoadImages();

    // Check if server already rendered issues
    const titleId = urlParams.get('title_id');
    const issuePage = parseInt(urlParams.get('page')) || 1;
    const issueSearch = urlParams.get('search') || '';
    
    // Only run on issues page
    if (titleId && window.location.pathname.includes('/comic-catalog/issues/')) {
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
    if (currentPublisherId) {
        console.log('Scheduling background cache warm for publisher:', currentPublisherId);
        $.post(comicbooks_fetchers_data.ajax_url, {
            action: 'schedule_warm_series_cache', // new action
            publisher_id: currentPublisherId,
            nonce: comicbooks_fetchers_data.nonce
        });
    }
});