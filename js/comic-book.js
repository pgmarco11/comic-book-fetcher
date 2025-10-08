jQuery(document).ready(function($) {
    const CACHE_TTL = 24 * 60 * 60 * 1000; // 24 hours
    let allPublishers = [];
    let allSeries = [];
    let currentPublisherId = null;
    let currentLetter = 'all';
    let currentPage = 1;
    let currentSearch = '';
    let isAjaxPending = false;
    

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

    // Cache handling
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

        // Show spinner function
    function showSpinner() {
        $('#loading-spinner').show();
        $('#book-container .publishers-wrapper, #book-container .book-wrapper, #book-container .pagination-wrapper').hide();
    }
    
    // Hide spinner function
    function hideSpinner() {
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
        const isPublisher = type === 'publishers';
        const container = $('#book-container');
    
        // Show spinner before rendering
        container.html(`
            <div id="loading-spinner" aria-busy="true" aria-label="Loading items">
                <div class="spinner"></div>
                <p>Loading ${isPublisher ? 'publishers' : 'series'}...</p>
            </div>
        `);
    
        // Small delay to ensure spinner is visible before rendering items
        setTimeout(() => {
            let html = `<div id="items-wrapper"><div class="${isPublisher ? 'publishers' : 'book'}-wrapper">`;
    
            if (!items.length) {
                html += `<p>No ${isPublisher ? 'publishers' : 'series'} found${search ? ` for "${search}"` : letter && letter !== 'all' ? ` starting with "${letter}"` : isPublisher ? '' : ' for this publisher'}. </p>`;
            } else {
                html += `<p>Showing ${items.length} of ${total} ${isPublisher ? 'publishers' : 'series'}${search ? ` for "${search}"` : letter && letter !== 'all' ? ` starting with "${letter}"` : ''}</p>`;
                items.forEach(item => {
                    html += isPublisher ? `
                        <div class="publisher-item" data-publisher-id="${item.id}">
                            <div class="publisher-image">
                                <img src="${item.image || comicbooks_fetchers_data.placeholder}" alt="${item.name}" loading="lazy">
                            </div>
                            <div class="publisher-info">
                                <h3>${item.name}</h3>
                                <p><strong>Founded:</strong> ${item.founded || 'N/A'}</p>
                                <p>${item.desc || 'No description available.'}</p>
                            </div>
                        </div>
                    ` : `
                        <div class="comic-title" data-series-id="${item.series_id}">
                            <div class="comic-image">
                                <img src="${item.first_issue_image || comicbooks_fetchers_data.placeholder}" alt="${item.name}" loading="lazy">
                            </div>
                            <div class="comic-info">
                                <div class="comic-title-name">${item.name}</div>
                                <div class="comic-title-meta">
                                    <p>Vol. <span>${item.volume}</span></p>
                                    <p>Issues: <span>${item.issue_count}</span></p>
                                    <p>Started: <span>${item.year_began}</span></p>
                                </div>
                            </div>
                        </div>
                    `;
                });
            }
            html += '</div>';
            html += renderPagination(page, Math.ceil(total / perPage), letter);
            html += '</div>'; // Close items-wrapper
    
            // Replace spinner with rendered items
            container.html(html);
    
            // Lazy load images if you have this function
            lazyLoadImages();
        }, 50); // 50ms delay to let spinner render (optional but improves UX)
    }

    function renderPagination(page, totalPages, letter = '') {
        if (totalPages <= 1) return '';
        let html = '<div class="pagination-wrapper">';
        html += `<p>Page ${page} of ${totalPages}</p>`;
        if (page > 1) {
            html += `<button type="button" class="page-btn" data-page="${page - 1}" data-letter="${letter}">Previous</button>`;
        }
        for (let i = Math.max(1, page - 2); i <= Math.min(totalPages, page + 2); i++) {
            html += `<button type="button" class="page-btn ${i === page ? 'active' : ''}" data-page="${i}" data-letter="${letter}">${i}</button>`;
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
            html += `<a href="${new URLSearchParams({title_id: titleId, issue_page: i, search}).toString()}" class="page-btn ${i === page ? 'active' : ''}" data-page="${i}">${i}</a>`;
        }
        if (page < totalPages) {
            html += `<a href="${new URLSearchParams({title_id: titleId, issue_page: page + 1, search}).toString()}" class="page-btn" data-page="${page + 1}">Next</a>`;
        }
        html += '</div>';
        return html;
    }

    // Lazy load images
    function lazyLoadImages() {
        // Series images
        const seriesImages = document.querySelectorAll('.comic-title img[loading="lazy"]:not([data-loaded="true"])');
        const seriesIds = [...new Set(Array.from(seriesImages)
            .map(img => img.closest('.comic-title')?.dataset.seriesId))
        ].filter(id => id);
    
        // Publisher images
        const publisherImages = document.querySelectorAll('.publisher-item img[loading="lazy"]:not([data-loaded="true"]), .publisher-info img[loading="lazy"]:not([data-loaded="true"])');
        const publisherIds = [...new Set(Array.from(publisherImages)
            .map(img => img.closest('.publisher-item')?.dataset.publisherId || img.closest('.publisher-info')?.dataset.publisherId))
        ].filter(id => id);
    
        // Fetch series images
        if (seriesIds.length) {
            $.ajax({
                url: comicbooks_fetchers_data.ajax_url,
                method: 'POST',
                data: {
                    action: 'load_series_images_batch',
                    series_ids: seriesIds,
                    nonce: comicbooks_fetchers_data.nonce
                },
                success: response => {
                    if (response.success && response.data.images) {
                        seriesImages.forEach(img => {
                            const seriesId = img.closest('.comic-title')?.dataset.seriesId;
                            if (response.data.images[seriesId]) {
                                img.src = response.data.images[seriesId];
                                img.dataset.loaded = 'true';
                            }
                        });
                    }
                },
                error: () => console.warn('Failed to load series images')
            });
        }
    
        // Fetch publisher images
        if (publisherIds.length) {
            $.ajax({
                url: comicbooks_fetchers_data.ajax_url,
                method: 'POST',
                data: {
                    action: 'load_publisher_images_batch',
                    publisher_ids: publisherIds,
                    nonce: comicbooks_fetchers_data.nonce
                },
                success: response => {
                    if (response.success && response.data.images) {
                        publisherImages.forEach(img => {
                            const publisherId = img.closest('.publisher-item')?.dataset.publisherId || img.closest('.publisher-info')?.dataset.publisherId;
                            if (response.data.images[publisherId]) {
                                img.src = response.data.images[publisherId];
                                img.dataset.loaded = 'true';
                            }
                        });
                    }
                },
                error: () => console.warn('Failed to load publisher images')
            });
        }
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
        // Show spinner instead of plain text
        $('#loading-spinner').removeClass('hidden').show();
        $(container).empty(); // Clear container

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
                $('#loading-spinner').addClass('hidden').hide();
            }
        });
    }, 300);

    // Fetch publishers
    function fetchPublishers(name = '', page, letter = 'all') {
        console.log('fetchPublishers called:', { name, page, letter });
        const cacheKey = `metron_publishers_${name}_${page}_${letter}`;
        const cached = getCachedData(cacheKey);

        // Show spinner before checking cache or making AJAX call
        showSpinner();

        if (cached) {
            console.log('cached.publishers: ', cached.publishers);
            allPublishers = cached.publishers || [];
            const total = cached.total || allPublishers.length;
            console.log("cached allPublishers: ", allPublishers);
            renderItems(allPublishers, 'publishers', page, total, name, letter, 10);
            showLetterButtons(true);
            updateActiveLetter(letter);
            hideSpinner(); // Hide spinner after rendering cached data
            return;
        }

        debouncedAjaxAction('load_publishers', { name, page, letter }, response => {
            console.log('response.data.publishers: ', response.data.publishers);
            allPublishers = response.data.publishers || [];
            const total = response.data.total || 0;
            const maxPages = response.data.max_pages || Math.ceil(total / 10);
            setCachedData(cacheKey, { publishers: allPublishers, total, maxPages });        
            renderItems(allPublishers, 'publishers', page, total, name, letter, 10);
            showLetterButtons(true);
            updateActiveLetter(letter);
            hideSpinner(); // Hide spinner after rendering
        }, error => {
            console.error('fetchPublishers error:', error);
            $('#book-container').html('<p>Error loading publishers for page ' + page + '. Please try a different page or refresh.</p>');
            hideSpinner(); // Hide spinner after rendering
        });
    }

    // Fetch books
    function fetchBooks(publisherId, page = 1, name = '', letter = 'all') {
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

        // Show spinner before checking cache or making AJAX call
        showSpinner();

        if (cached) {
            allSeries = cached.series || [];
            const perPage = cached.perPage || 10;
            renderItems(allSeries, 'books', page, cached.total || allSeries.length, name, letter, perPage);
            showLetterButtons(true);
            updateActiveLetter(letter);
            hideSpinner(); // Hide spinner after rendering cached data
            return;
        }

        debouncedAjaxAction('load_book', { publisher_id: publisherId || '', page, per_page: 10, name, letter }, response => {
            allSeries = response.data.series || [];
            const perPage = response.data.per_page || 10;
            const total = response.data.total || 0;

            if (!allSeries.length) {
                $('#book-container').html(`<p>No series found for ${name ? 'search "' + name + '"' : letter === 'all' ? 'this publisher' : 'letter "' + letter + '"'}. </p>`);
            } else {
                setCachedData(cacheKey, { series: allSeries, total, perPage });
                renderItems(allSeries, 'books', page, total, name, letter, perPage);
            }
            showLetterButtons(true);
            updateActiveLetter(letter);
            hideSpinner(); // Hide spinner after rendering cached data
        }, (xhr, status, error) => {
            console.warn('Retrying fetchBooks due to error:', status, error);
            setTimeout(() => fetchBooks(publisherId, page, name, letter), 2000);
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
            $('#issues-list').html('<p>No series selected.</p>');
            $('#loading-spinner').addClass('hidden').hide();
            $('#pagination-wrapper').empty();
            return;
        }
        const cacheKey = `metron_issues_${titleId}_${page}_${search}`;
        const cached = getCachedData(cacheKey);
        if (cached) {
            const perPage = cached.per_page || 10;
            const totalIssues = cached.total_issues || 0;
            const totalPages = Math.ceil(totalIssues / perPage);
            $('#issues-list').html(cached.issues); // Remove extra <ul> wrapping
            $('#pagination-wrapper').html(renderIssuesPagination(cached.current_page, totalPages, titleId, search, perPage));
            $('#issues-list .issues-list').addClass('loaded');
            updateIssuesUrl(titleId, cached.current_page, search);
            lazyLoadImages();
            if (cached.current_page < totalPages) {
                preloadIssues(titleId, cached.current_page + 1, search);
            }
            return;
        }
    
        $('#issues-list').html(`
             <div id="loading-spinner" style="display:none;" aria-busy="true" aria-label="Loading content">
                        <div class="spinner"></div>
                        <p>Loading issues...</p>
            </div>
        `);
        $('#loading-spinner').removeClass('hidden').show();
    
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
                console.log('fetchIssues: AJAX response:', response);
                if (response.success && response.data) {
                    const perPage = response.data.per_page || 10;
                    const totalIssues = response.data.total_issues || 0;
                    const totalPages = response.data.total_pages || Math.ceil(totalIssues / perPage);
                    const currentPage = response.data.current_page || page;
                    const issuesHtml = response.data.issues || '<p>No issues found.</p>';
    
                    $('#issues-list').html(issuesHtml); // Update to avoid extra <ul>
                    $('#pagination-wrapper').html(renderIssuesPagination(currentPage, totalPages, titleId, search, perPage));
    
                    setCachedData(cacheKey, {
                        issues: issuesHtml,
                        total_issues: totalIssues,
                        total_pages: totalPages,
                        current_page: currentPage,
                        per_page: perPage
                    });
    
                    $('#issues-list .issues-list').addClass('loaded');
                    updateIssuesUrl(titleId, currentPage, search);
                    lazyLoadImages();
    
                    if (currentPage < totalPages && comicbooks_fetchers_data.preload_enabled) {
                        preloadIssues(titleId, currentPage + 1, search);
                    }
                } else {
                    console.error('fetchIssues: AJAX error:', response.data?.message || 'Failed to load issues');
                    $('#issues-list').html('<p>' + (response.data?.message || 'Failed to load issues. Please try again.') + '</p>');
                    $('#pagination-wrapper').empty();
                }
                $('#loading-spinner').addClass('hidden').hide();
            },
            error: (xhr, status, error) => {
                console.error('fetchIssues: AJAX failure:', status, error, xhr.responseText);
                if (xhr.status === 429 && retries > 0) {
                    console.log(`Retrying fetchIssues, retries left: ${retries - 1}`);
                    setTimeout(() => fetchIssues(titleId, page, search, retries - 1), 2000);
                } else {
                    $('#issues-list').html('<p>Error loading issues. Please try again later.</p>');
                    $('#pagination-wrapper').empty();
                    $('#loading-spinner').addClass('hidden').hide();
                }
            }
        });
    }

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
            html += `<button type="button" class="page-btn" data-page="${page - 1}">Previous</button>`;
        }
        for (let i = Math.max(1, page - 2); i <= Math.min(totalPages, page + 2); i++) {
            html += `<button type="button" class="page-btn ${i === page ? 'active' : ''}" data-page="${i}">${i}</button>`;
        }
        if (page < totalPages) {
            html += `<button type="button" class="page-btn" data-page="${page + 1}">Next</button>`;
        }
        html += '</div>';
        return html;
    }
    // Update URL for issues page
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
        $('#loading-spinner').addClass('hidden').hide();
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
            $('#loading-spinner').addClass('hidden').hide();
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
        currentLetter = $(this).data('letter') || 'all';

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
        const letter = $(this).data('letter') || 'all';
        currentLetter = letter;
        currentPage = 1;
        updateActiveLetter(letter);
    
        const container = $('#book-container');
    
        // Show spinner immediately
        showSpinner();
    
        if (currentPublisherId) {
            fetchBooks(currentPublisherId, 1, currentSearch, letter);
        } else {
            fetchPublishers(currentSearch, 1, letter);
        }
    }, 300));

    $(document).on('click', '.page-btn', function(e) {
        e.preventDefault();
        const page = $(this).data('page');
        const letter = $(this).data('letter') || currentLetter;
        const isIssuesPage = window.location.pathname.includes('/comic-books/issues/');
        
        showSpinner();
        
        if (isIssuesPage) {
            const params = new URLSearchParams(window.location.search);
            const titleId = params.get('title_id') || '';
            const search = params.get('search') || '';
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
                <div id="loading-spinner" aria-busy="true" aria-label="Loading comic issues">
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
        const state = event.originalEvent.state || {};
        const params = new URLSearchParams(window.location.search);
        const page = parseInt(params.get('page')) || state.page || 1;
        const letter = params.get('letter') || state.letter || 'all';
        const publisherId = params.get('publisher_id') || state.publisher_id || null;
        const titleId = params.get('title_id') || state.title_id || null;
        const search = params.get('search') || state.search || '';

        console.log('popstate triggered:', { page, letter, publisherId, titleId, search });

        currentPage = page;
        currentLetter = letter;
        currentPublisherId = publisherId;
        currentSearch = search;

        if (titleId) {
            $('#issue-search').val(search);
            fetchIssues(titleId, page, search);
        } else if (publisherId) {
            fetchBooks(publisherId, page, search, letter);
            updateActiveLetter(letter);
        } else {
            fetchPublishers(search, page, letter);
            updateActiveLetter(letter);
        }
    });

    // Initialize
    const urlParams = new URLSearchParams(window.location.search);
    const initialPublisherId = urlParams.get('publisher_id');
    const initialTitleId = urlParams.get('title_id') || comicbooks_fetchers_data?.title_id;
    const initialLetter = urlParams.get('letter') || 'all';
    const initialPage = parseInt(urlParams.get('page') || urlParams.get('issue_page')) || 1;
    const initialSearch = urlParams.get('search') || '';

    currentLetter = initialLetter;
    currentPage = initialPage;
    currentSearch = initialSearch;

    if (initialTitleId && window.location.pathname.includes('/comic-books/issues/')) {
        $('#issue-search').val(initialSearch);
        fetchIssues(initialTitleId, initialPage, initialSearch);
    } else if (initialPublisherId) {
        currentPublisherId = initialPublisherId;
        $('#comic-search').attr('placeholder', 'Search titles...');
        showLetterButtons(true);
        updateActiveLetter(currentLetter);
        $('.publisher-info').css('display', 'block');
        if (typeof comicbooks_fetchers_data !== 'undefined' && comicbooks_fetchers_data.items) {
            renderItems(comicbooks_fetchers_data.items, 'book', comicbooks_fetchers_data.page, comicbooks_fetchers_data.total);
        } else {
            fetchBooks(initialPublisherId, initialPage, initialSearch, currentLetter);
        }
    } else {
        showLetterButtons(true);
        updateActiveLetter(currentLetter);
        if (typeof comicbooks_fetchers_data !== 'undefined' && comicbooks_fetchers_data.items) {
            renderItems(comicbooks_fetchers_data.items, 'publishers', comicbooks_fetchers_data.page, comicbooks_fetchers_data.total);
        } else {
            fetchPublishers(initialSearch, initialPage, currentLetter);
        }
    }
   
});