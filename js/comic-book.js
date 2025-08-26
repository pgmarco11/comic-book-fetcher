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
        let html = `<div class="${isPublisher ? 'publishers' : 'book'}-wrapper">`;

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
        $('#book-container').html(html);
        lazyLoadImages();
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

    // Render pagination for issues
    function renderIssuesPagination(page, totalPages, titleId, search = '', perPage) {
        if (totalPages <= 1) return '';
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

    // Lazy load images
    function lazyLoadImages() {
        const images = document.querySelectorAll('img[loading="lazy"]');
        console.log(`Found ${images.length} images to lazy load.`);

        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries, obs) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        const seriesId = img.closest('.comic-title')?.dataset.seriesId;
                        console.log('Observed image entering viewport:', { img, seriesId });

                        if (seriesId && img.dataset.loaded !== 'true') {
                            console.log('Lazy loading image for series ID:', seriesId);
                            $.ajax({
                                url: comicbooks_fetchers_data.ajax_url,
                                method: 'POST',
                                data: {
                                    action: 'load_series_image',
                                    series_id: seriesId,
                                    nonce: comicbooks_fetchers_data.nonce
                                },
                                success: response => {
                                    console.log('load_series_image response:', response);
                                    if (response.success && response.data.image) {
                                        img.src = response.data.image;
                                        img.dataset.loaded = 'true';
                                    } else {
                                        console.warn(`Image not returned for series ID ${seriesId}`);
                                    }
                                },
                                error: () => {
                                    console.warn('Failed to load series image for series_id:', seriesId);
                                }
                            });
                        }
                        obs.unobserve(img);
                    }
                });
            });

            Array.from(images).forEach(img => observer.observe(img));
        } else {
            console.log('IntersectionObserver not supported. Falling back to eager loading.');
            Array.from(images).forEach(img => {
                const seriesId = img.closest('.comic-title')?.dataset.seriesId;
                if (seriesId && !img.src) {
                    console.log(`Eager loading image for series ID ${seriesId}...`);
                    $.ajax({
                        url: comicbooks_fetchers_data.ajax_url,
                        method: 'POST',
                        data: {
                            action: 'load_series_image',
                            series_id: seriesId,
                            nonce: comicbooks_fetchers_data.nonce
                        },
                        success: response => {
                            console.log('load_series_image response:', response);
                            if (response.success && response.data.image) {
                                img.src = response.data.image;
                                console.log(`Image loaded and set for series ID ${seriesId}`);
                            } else {
                                console.warn(`No image returned for series ID ${seriesId}`, response);
                            }
                        },
                        error: (xhr, status, error) => {
                            console.error(`AJAX error for series ID ${seriesId}:`, status, error);
                        }
                    });
                }
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
        $(container).html('<p>Loading...</p>');

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
    }, 300);

    // Fetch publishers
    function fetchPublishers(name = '', page = 1, letter = 'all') {
        console.log('fetchPublishers called:', { name, page, letter });
        const cacheKey = `metron_publishers_${name}_${page}_${letter}`;
        const cached = getCachedData(cacheKey);

        if (cached) {
            console.log('cached.publishers: ', cached.publishers);
            allPublishers = cached.publishers || [];
            const total = cached.total || allPublishers.length;
            console.log("cached allPublishers: ", allPublishers);
            renderItems(allPublishers, 'publishers', page, total, name, letter, 10);
            showLetterButtons(true);
            updateActiveLetter(letter);
            return;
        }

        debouncedAjaxAction('load_publishers', { name, page, letter }, response => {
            console.log('response.data.publishers: ', response.data.publishers);
            allPublishers = response.data.publishers || [];
            const total = response.data.total || 0;
            const maxPages = response.data.max_pages || Math.ceil(total / 10);
            setCachedData(cacheKey, { publishers: allPublishers, total, maxPages });
            console.log("deb allPublishers: ", allPublishers);
            renderItems(allPublishers, 'publishers', page, total, name, letter, 10);
            showLetterButtons(true);
            updateActiveLetter(letter);
        }, error => {
            console.error('fetchPublishers error:', error);
            $('#book-container').html('<p>Error loading publishers for page ' + page + '. Please try a different page or refresh.</p>');
        });
    }

    // Fetch books
    function fetchBooks(publisherId, page = 1, name = '', letter = 'all') {
        if (!publisherId && !name) {
            $('#book-container').html('<p>Please select a publisher or enter a search term.</p>');
            showLetterButtons(false);
            updateActiveLetter(letter);
            return;
        }

        console.log("Fetching books for publisher:", publisherId, "letter:", letter);

        const cacheKey = `metron_books_${publisherId}_${page}_${name}_${letter}`;
        const cached = getCachedData(cacheKey);

        if (cached) {
            allSeries = cached.series || [];
            const perPage = cached.perPage || 10;
            renderItems(allSeries, 'books', page, cached.total || allSeries.length, name, letter, perPage);
            showLetterButtons(true);
            updateActiveLetter(letter);
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
        }, (xhr, status, error) => {
            console.warn('Retrying fetchBooks due to error:', status, error);
            setTimeout(() => fetchBooks(publisherId, page, name, letter), 2000);
        });
    }

    // Fetch issues (updated for issues page)
    function fetchIssues(titleId, page = 1, search = '') {
        console.log(`fetchIssues: titleId=${titleId}, page=${page}, search='${search}'`);
        if (!titleId) {
            console.error('fetchIssues: No titleId provided');
            $('#issues-list').html('<p>No series selected.</p>');
            $('#loading-spinner').hide();
            return;
        }
        $('#loading-spinner').show();
        $('#issues-list').empty();
        $('#pagination-wrapper').empty();
        const cacheKey = `metron_issues_${titleId}_${page}_${search}`;
        const cached = getCachedData(cacheKey);
        if (cached) {
            const perPage = 10;
            const totalIssues = cached.total_issues;
            const totalPages = Math.ceil(totalIssues / perPage);
            $('#issues-list').html(cached.issues);
            $('#pagination-wrapper').html(renderIssuesPagination(cached.current_page, totalPages, titleId, search, perPage));
            $('#loading-spinner').hide();
            updateIssuesUrl(titleId, cached.current_page, search);
            lazyLoadImages();
            return;
        }
        debouncedAjaxAction('load_issues', {
            title_id: titleId,
            page: page,
            search: search || ''
        }, response => {
            console.log('fetchIssues: AJAX response:', response);
            if (response.success) {
                const perPage = 10;
                const totalIssues = response.data.total_issues;
                const totalPages = Math.ceil(totalIssues / perPage);
                const currentPage = response.data.current_page || page;
                setCachedData(cacheKey, {
                    issues: response.data.issues,
                    total_issues: totalIssues,
                    total_pages: totalPages,
                    current_page: currentPage,
                    per_page: perPage
                });
                $('#issues-list').html(response.data.issues);
                $('#pagination-wrapper').html(renderIssuesPagination(currentPage, totalPages, titleId, search, perPage));
                updateIssuesUrl(titleId, currentPage, search);
                lazyLoadImages();
            } else {
                console.error('fetchIssues: AJAX error:', response.data.message || 'Failed to load issues');
                $('#issues-list').html('<p>' + (response.data.message || 'Failed to load issues.') + '</p>');
                $('#pagination-wrapper').empty();
            }
            $('#loading-spinner').hide();
        }, (xhr, status, error) => {
            console.error('fetchIssues: AJAX failure:', status, error, xhr.responseText);
            $('#issues-list').html('<p>Error loading issues. Please try again.</p>');
            $('#pagination-wrapper').empty();
            $('#loading-spinner').hide();
        });
    }
    
    function renderIssuesPagination(page, totalPages, titleId, search = '', perPage) {
        console.log(`renderIssuesPagination: page=${page}, totalPages=${totalPages}, titleId=${titleId}, search='${search}', perPage=${perPage}`);
        if (totalPages <= 1) {
            console.warn('renderIssuesPagination: No pagination rendered because totalPages <= 1');
            return '<p>Debug: No pagination needed (totalPages=' + totalPages + ')</p>';
        }
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

    /* Spinner */
    // Show spinner by default
    $('#loading-spinner').removeClass('hidden').show();

    // Check if content is already loaded
    if ($('#body-content').length) {
        console.log('Content already loaded, hiding spinner');
        $('#loading-spinner').addClass('hidden').hide();
    }
    
    // Listen for AJAX completion (if comic-book.js uses AJAX)
    $(document).ajaxComplete(function(event, xhr, settings) {
        console.log('AJAX completed:', settings.url);
        if (settings.url.includes('admin-ajax.php') && $('#body-content').length) {
            console.log('Content loaded via AJAX, hiding spinner');
            $('#loading-spinner').addClass('hidden').hide();
        }
    });
    
    // Fallback: Hide spinner after a timeout if content doesn't load
    setTimeout(function() {
        if ($('#body-content').length) {
            console.log('Fallback: Hiding spinner after timeout');
            $('#loading-spinner').addClass('hidden').hide();
        }
    }, 5000); // Adjust timeout as needed

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
    }, 300));

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
        $('#book-container').html('<p>Loading...</p>');
        showLetterButtons(true);
        updateActiveLetter('all');

        if (publisherId) {
            const newUrl = `${baseUrl}?publisher_id=${publisherId}&letter=all`;
            $('.publisher-info').css('display', 'flex');
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
        console.log("currentPublisherId is ", currentPublisherId);
        console.log("letter is ", letter);

        currentLetter = letter;
        currentPage = 1;
        updateActiveLetter(letter);
        if (currentPublisherId) {
            fetchBooks(currentPublisherId, 1, currentSearch, letter);
        } else {
            fetchPublishers(currentSearch, 1, letter);
        }
    }, 300));

    // Pagination button handler
    $(document).on('click', '.page-btn', debounce(function() {
        const page = $(this).data('page');
        const letter = $(this).data('letter') || currentLetter;
        const isIssuesPage = window.location.pathname.includes('/comic-books/issues/');
    
        if (isIssuesPage) {
            const params = new URLSearchParams(window.location.search);
            const titleId = params.get('title_id') || '';
            const search = params.get('search') || '';
            
            updateIssuesUrl(titleId, page, search);
            fetchIssues(titleId, page, search);
            
            const container = $('#issues-list');
            if (container.length) {
                $('html, body').animate({ scrollTop: container.offset().top }, 100);
            }
        } else {
            const params = new URLSearchParams(window.location.search);
            params.set('page', page);
            params.set('letter', letter || 'all');
            history.pushState({ page, letter }, '', `${window.location.pathname}?${params.toString()}`);
            if (currentPublisherId) {
                fetchBooks(currentPublisherId, page, currentSearch, letter);
            } else {
                fetchPublishers(currentSearch, page, letter);
            }
            const container = $('#book-container');
            if (container.length) {
                $('html, body').animate({ scrollTop: container.offset().top }, 100);
            }
        }
    }, 300));

    $(document).on('click', '.comic-title', debounce(function(e) {
        e.preventDefault();
        const seriesId = $(this).data('series-id');
        if (seriesId) {
            const url = new URL(window.location.origin + '/comic-books/issues/');
            url.searchParams.set('title_id', seriesId);
            url.searchParams.set('issue_page', 1);
            window.location.href = url.toString();
        }
    }, 300));

    $(document).on('click', '.add-to-collection', debounce(async function(e) {
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
    }, 300));

    $(document).on('click', '.add-to-wishlist', debounce(function(e) {
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
    }, 300));

    // Popstate handler
    $(window).on('popstate', function(event) {
        const state = event.originalEvent.state || {};
        const params = new URLSearchParams(window.location.search);
        const page = parseInt(params.get('page') || params.get('issue_page')) || state.page || 1;
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
    const initialTitleId = urlParams.get('title_id');
    const initialLetter = urlParams.get('letter') || 'all';
    const initialPage = parseInt(urlParams.get('page') || urlParams.get('issue_page')) || 1;
    const initialSearch = urlParams.get('search') || '';

    currentLetter = initialLetter;
    currentPage = initialPage;
    currentSearch = initialSearch;

    if (initialTitleId) {
        $('#issue-search').val(initialSearch);
        fetchIssues(initialTitleId, initialPage, initialSearch);       
    } else if (initialPublisherId) {
        currentPublisherId = initialPublisherId;
        $('#comic-search').attr('placeholder', 'Search titles...');
        showLetterButtons(true);
        updateActiveLetter(currentLetter);
        $('.publisher-info').css('display', 'flex');

        if (typeof comicbooks_initial_data !== 'undefined' && comicbooks_initial_data.items) {
            console.log("Rendering books from localized data:", comicbooks_initial_data);
            renderItems(comicbooks_initial_data.items, 'book', comicbooks_initial_data.total, comicbooks_initial_data.page);
        } else {
            $('#book-container').html('<p>Loading...</p>');
            fetchBooks(initialPublisherId, initialPage, initialSearch, currentLetter);
        }
    } else {
        showLetterButtons(true);
        updateActiveLetter(currentLetter);

        if (typeof comicbooks_initial_data !== 'undefined' && comicbooks_initial_data.items) {
            console.log("Rendering publishers from localized data:", comicbooks_initial_data);
            renderItems(comicbooks_initial_data.items, 'publishers', comicbooks_initial_data.total, comicbooks_initial_data.page);
        } else {
            fetchPublishers(initialSearch, initialPage, currentLetter);
        }
    }
   
});