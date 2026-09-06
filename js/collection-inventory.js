(() => {
    'use strict';
    const root = document.querySelector('#collection-inventory .tci-shell');
    if (!root || typeof tcsInventory === 'undefined') return;
    const $ = (selector) => root.querySelector(selector);
    const filters = $('#tci-filters');
    const results = $('#tci-results');
    const issueView = $('#tci-issue-view');
    const seriesView = $('#tci-series-view');
    const editor = $('#tci-editor');
    const editorForm = $('#tci-editor-form');
    const actionDialog = $('#tci-action-dialog');
    const actionForm = $('#tci-action-form');
    const selected = new Set();
    let readController;
    let readVersion = 0;
    let editingRecord = null;
    let editorSnapshot = '';
    let writing = false;
    let pendingAction = null;
    let searchTimer;

    function message(text, error = false) {
        $('#tci-feedback').textContent = text;
        $('#tci-feedback').dataset.error = String(error);
    }
    function busyForm(form, busy) {
        form.querySelectorAll('button').forEach(button => { button.disabled = busy; });
        form.setAttribute('aria-busy', String(busy));
    }
    async function request(action, values = {}, signal) {
        const body = values instanceof FormData ? values : new FormData();
        if (!(values instanceof FormData)) Object.entries(values).forEach(([key, value]) => {
            if (Array.isArray(value)) value.forEach(item => body.append(`${key}[]`, item));
            else body.append(key, value);
        });
        body.set('action', action);
        body.set('nonce', tcsInventory.nonce);
        const response = await fetch(tcsInventory.ajaxUrl, {method: 'POST', credentials: 'same-origin', body, signal});
        let payload;
        try { payload = await response.json(); } catch (_) { throw new Error('The server could not complete this request. Refresh and try again.'); }
        if (!response.ok || !payload.success) {
            const error = new Error(typeof payload.data === 'string' ? payload.data : payload.data?.message || 'The request could not be completed.');
            error.completed = payload.data?.completed || [];
            error.operation = payload.data?.operation;
            throw error;
        }
        return payload.data;
    }
    function updateSelection() {
        const boxes = [...results.querySelectorAll('input[name="inventory_selected[]"]')];
        $('#tci-selection-count').textContent = `${selected.size} selected`;
        $('#tci-select-all').checked = boxes.length > 0 && selected.size === boxes.length;
        $('#tci-select-all').indeterminate = selected.size > 0 && selected.size < boxes.length;
        $('#tci-bulk-location').disabled = !selected.size;
        $('#tci-bulk-trash').disabled = !selected.size;
    }
    function setView(view) {
        const allowedViews = [
            'shelf',
            'inventory',
            'series'
        ];
    
        const selectedView = allowedViews.includes(view)
            ? view
            : 'shelf';
    
        const isSeriesView =
            selectedView === 'series';
    
        root.dataset.view = selectedView;
        filters.elements.collection_view.value =
            selectedView;
    
        issueView.hidden = isSeriesView;
        seriesView.hidden = !isSeriesView;
    
        root.querySelectorAll(
            '.tci-view-switch button'
        ).forEach(button => {
            button.setAttribute(
                'aria-pressed',
                String(
                    button.dataset.view ===
                    selectedView
                )
            );
        });
    
        const title = {
            shelf: 'Your comic shelf',
            inventory: 'Your inventory',
            series: 'Browse by publisher'
        };
    
        $('#tci-view-title').textContent =
            title[selectedView];
    }
    function setUrl(push = false) {
        const url = new URL(filters.action, location.href);
        new FormData(filters).forEach((value, key) => { if (value !== '') url.searchParams.set(key, value); });
        history[push ? 'pushState' : 'replaceState']({}, '', url);
    }
    function pagination(page, pages) {
        const nav = $('#tci-pagination');
        nav.replaceChildren();
        function link(number, label) {
            const a = document.createElement('a');
            const url = new URL(location.href);
            url.searchParams.set('collection_page', number);
            a.href = url.href;
            a.dataset.page = number;
            a.textContent = label;
            if (number === page) a.setAttribute('aria-current', 'page');
            nav.append(a);
        }
        if (page > 1) link(page - 1, 'Previous');
        for (let i = Math.max(1, page - 2); i <= Math.min(pages, page + 2); i++) link(i, String(i));
        if (page < pages) link(page + 1, 'Next');
    }
    function updateFacets(selector, rows, allLabel) {
        const select = $(selector);
        const value = select.value;
        const options = [new Option(allLabel, '')];
        rows.forEach(row => options.push(new Option(row.name, row.id)));
        if (value && !rows.some(row => String(row.id) === value)) {
            options.push(new Option('Previous selection (no remaining entries)', value));
        }
        select.replaceChildren(...options);
        select.value = value;
    }
    async function refresh(push = false, announce = true) {
        const version = ++readVersion;
        readController?.abort();
        readController = new AbortController();
        results.setAttribute('aria-busy', 'true');
        seriesView.setAttribute(
            'aria-busy',
            'true'
        );
        try {
            const data = await request('tcs_inventory_list', new FormData(filters), readController.signal);
            if (version !== readVersion) return;
            results.innerHTML = data.html;
            if (
                typeof data.taxonomy_html === 'string'
            ) {
                seriesView.innerHTML =
                    data.taxonomy_html;
            }
            filters.elements.collection_page.value = data.page;
            $('#tci-total').textContent = `${data.total} ${data.total === 1 ? 'entry' : 'entries'}`;
            Object.entries(data.summary).forEach(([key, value]) => { const el = $(`[data-stat="${key}"]`); if (el) el.textContent = value; });
            updateFacets('#tci-publisher', data.publishers, 'All publishers');
            updateFacets('#tci-series', data.series, 'All series');
            selected.clear();
            updateSelection();
            setUrl(push);
            pagination(data.page, data.pages);
            if (announce) message(`${data.total} ${data.total === 1 ? 'entry' : 'entries'} found. Page ${data.page} of ${data.pages}.`);
        } catch (error) {
            if (error.name !== 'AbortError' && version === readVersion) message(error.message, true);
        } finally {
            if (version === readVersion) {
                results.setAttribute(
                    'aria-busy',
                    'false'
                );
            
                seriesView.setAttribute(
                    'aria-busy',
                    'false'
                );
            }
        }
    }
    function filterChanged() {
        filters.elements.collection_page.value = '1';
        refresh(true);
    }
    filters.addEventListener('submit', event => { event.preventDefault(); clearTimeout(searchTimer); filterChanged(); });
    filters.addEventListener('change', event => {
        if (
            event.target.name ===
            'collection_publisher'
        ) {
            /*
             * A series selected under the previous publisher
             * may not belong to the new publisher.
             */
            filters.elements.collection_series.value = '';
        }
    
        if (
            event.target.matches(
                'select,input[type=checkbox]'
            )
        ) {
            filterChanged();
        }
    });
    $('#tci-search').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(filterChanged, 350); });
    $('#tci-reset').addEventListener('click', event => {
        event.preventDefault(); clearTimeout(searchTimer);
        filters.querySelectorAll('input[type=search],select').forEach(el => { el.value = el.name === 'collection_sort' ? 'recent' : ''; });
        filters.elements.collection_duplicates.checked = false;
        filterChanged();
    });
    $('.tci-view-switch').addEventListener('click', event => { const button = event.target.closest('button[data-view]'); if (button) { setView(button.dataset.view); setUrl(); } });
    $('#tci-pagination').addEventListener('click', event => {
        const link = event.target.closest('a[data-page]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey) return;
        event.preventDefault();
        filters.elements.collection_page.value = link.dataset.page;
        refresh(true);
    });
    window.addEventListener('popstate', () => {
        const query = new URL(location.href).searchParams;
        [...filters.elements].forEach(el => {
            if (!el.name) return;
            if (el.type === 'checkbox') el.checked = query.get(el.name) === el.value;
            else el.value = query.get(el.name) || ({collection_page: '1', collection_view: 'shelf', collection_sort: 'recent'}[el.name] || '');
        });
        setView(filters.elements.collection_view.value);
        refresh();
    });
    results.addEventListener('change', event => {
        if (event.target.name !== 'inventory_selected[]') return;
        event.target.checked ? selected.add(Number(event.target.value)) : selected.delete(Number(event.target.value));
        updateSelection();
    });
    $('#tci-select-all').addEventListener('change', event => {
        selected.clear();
        results.querySelectorAll('input[name="inventory_selected[]"]').forEach(box => { box.checked = event.target.checked; if (box.checked) selected.add(Number(box.value)); });
        updateSelection();
    });
    results.addEventListener('click', event => {
        const button = event.target.closest('.tci-edit');
        if (!button) return;
        editingRecord = JSON.parse(button.closest('[data-record]').dataset.record);
        editorForm.reset();
        ['qty', 'condition', 'price', 'notes', 'storage_location', 'version'].forEach(key => { editorForm.elements[key].value = editingRecord[key] ?? ''; });
        editorForm.elements.post_id.value = editingRecord.id;
        $('#tci-editor-title').textContent = `${editingRecord.title} #${editingRecord.issue || '—'}`;
        $('#tci-editor-error').textContent = '';
        editorSnapshot = new URLSearchParams(new FormData(editorForm)).toString();
        editor.showModal();
    });
    function closeDialog(dialog) {
        if (writing) return;
        if (dialog === editor && editorSnapshot !== new URLSearchParams(new FormData(editorForm)).toString() && !window.confirm('Discard unsaved inventory changes?')) return;
        dialog.close();
    }
    [editor, actionDialog].forEach(dialog => {
        dialog.addEventListener('cancel', event => { event.preventDefault(); closeDialog(dialog); });
        dialog.addEventListener('click', event => { if (event.target.closest('[data-close]')) closeDialog(dialog); });
    });
    editorForm.addEventListener('submit', async event => {
        event.preventDefault(); if (writing) return;
        writing = true; busyForm(editorForm, true); $('#tci-editor-error').textContent = '';
        try {
            await request('tcs_inventory_save', new FormData(editorForm));
            editor.close();
            message('Inventory details saved.');
            await refresh(false, false);
        } catch (error) { $('#tci-editor-error').textContent = error.message; }
        finally { writing = false; busyForm(editorForm, false); }
    });
    function openAction(operation, ids) {
        pendingAction = {
            operation,
            ids
        };
    
        $('#tci-action-title').textContent =
            operation === 'delete'
                ? 'Remove from collection?'
                : 'Set storage location';
    
        $('#tci-action-description').textContent =
            operation === 'delete'
                ? `Permanently remove ${ids.length} ${
                    ids.length === 1
                        ? 'entry'
                        : 'entries'
                  } and all recorded copies? This cannot be undone.`
                : `Give ${ids.length} selected ${
                    ids.length === 1
                        ? 'entry'
                        : 'entries'
                  } the same box or shelf location.`;
    
        $('#tci-action-location-wrap').hidden =
            operation !== 'location';
    
        $('#tci-action-location').required =
            operation === 'location';
    
        $('#tci-action-location').value = '';
    
        $('#tci-action-error').textContent = '';
    
        $('#tci-action-submit').textContent =
            operation === 'delete'
                ? 'Remove permanently'
                : 'Save location';
    
        actionDialog.showModal();
    }
    $('#tci-bulk-location').addEventListener('click', () => openAction('location', [...selected]));

    $('#tci-bulk-trash').addEventListener(
        'click',
        () => openAction('delete', [...selected])
    );

    $('#tci-trash-one').addEventListener(
        'click',
        () => openAction(
            'delete',
            [editingRecord.id]
        )
    );

    actionForm.addEventListener('submit', async event => {
        event.preventDefault(); if (writing) return;
        writing = true; busyForm(actionForm, true);
        try {
            const data = await request(
                'tcs_inventory_bulk',
                {
                    operation: pendingAction.operation,
                    post_ids: pendingAction.ids,
                    storage_location:
                        $('#tci-action-location').value
                }
            );
            
            actionDialog.close();
            
            if (editor.open) {
                editor.close();
            }
            
            message(
                data.operation === 'delete'
                    ? 'Selected entries removed from your collection.'
                    : 'Storage location updated.'
            );
            
            await refresh(false, false);
        } catch (error) {

            $('#tci-action-error').textContent =
            error.message;
        
            if (error.completed.length) {
        
                pendingAction.ids =
                    pendingAction.ids.filter(
                        id =>
                            !error.completed.includes(id)
                    );
        
                await refresh(false, false);
            }
            
        } finally { writing = false; busyForm(actionForm, false); }
    });
    setView(root.dataset.view);
    updateSelection();
    // Existing PHP markup is ready to use. No duplicate initial data request.
})();
