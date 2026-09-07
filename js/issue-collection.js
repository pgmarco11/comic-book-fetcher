(() => {
    'use strict';

    const tabsRoot =
        document.querySelector(
            '#issue-collection-tabs'
        );

    if (!tabsRoot) {
        return;
    }

    const tabs = [
        ...tabsRoot.querySelectorAll(
            '[data-issue-tab]'
        )
    ];

    const form =
        document.querySelector(
            '#issue-collection-form'
        );

    const feedback =
        document.querySelector(
            '#issue-collection-feedback'
        );

    let saving = false;
    let savedSnapshot = form
        ? new URLSearchParams(
            new FormData(form)
        ).toString()
        : '';

    function setFeedback(
        message,
        isError = false
    ) {
        if (!feedback) {
            return;
        }

        feedback.textContent = message;
        feedback.dataset.error =
            String(isError);
    }

    function setActiveTab(view, updateUrl = true) {
        const selectedView =
            view === 'collection'
                ? 'collection'
                : 'details';

        tabs.forEach((tab) => {
            const isActive =
                tab.dataset.issueTab ===
                selectedView;

            tab.setAttribute(
                'aria-selected',
                String(isActive)
            );

            tab.tabIndex = isActive ? 0 : -1;

            const panelId =
                tab.getAttribute(
                    'aria-controls'
                );

            const panel =
                document.getElementById(
                    panelId
                );

            if (panel) {
                panel.hidden = !isActive;
            }
        });

        tabsRoot.dataset.activeView =
            selectedView;

        if (updateUrl) {
            const url =
                new URL(window.location.href);

            if (selectedView === 'collection') {
                url.searchParams.set(
                    'view',
                    'collection'
                );
            } else {
                url.searchParams.delete('view');
            }

            window.history.pushState(
                {
                    issueView: selectedView
                },
                '',
                url
            );
        }

        const activeTab =
            tabs.find(
                (tab) =>
                    tab.dataset.issueTab ===
                    selectedView
            );

        activeTab?.focus();
    }

    document.addEventListener('DOMContentLoaded', function () {
        const backButton = document.getElementById('back-to-collection');
    
        if (!backButton) {
            return;
        }
    
        const savedUrl = sessionStorage.getItem(
            'collectiblespot_last_collection_url'
        );
    
        if (!savedUrl) {
            return;
        }
    
        try {
            const collectionUrl = new URL(savedUrl, window.location.origin);
    
            if (collectionUrl.origin === window.location.origin) {
                backButton.href = collectionUrl.href;
            }
        } catch (error) {
            // Keep the PHP fallback URL.
        }
    });

    tabs.forEach((tab) => {
        tab.addEventListener(
            'click',
            (event) => {
                /*
                 * Preserve normal browser behavior for
                 * Ctrl/Cmd-click and opening a new tab.
                 */
                if (
                    event.ctrlKey ||
                    event.metaKey ||
                    event.shiftKey ||
                    event.altKey
                ) {
                    return;
                }

                event.preventDefault();

                setActiveTab(
                    tab.dataset.issueTab
                );
            }
        );

        tab.addEventListener(
            'keydown',
            (event) => {
                if (
                    event.key !== 'ArrowLeft' &&
                    event.key !== 'ArrowRight'
                ) {
                    return;
                }

                event.preventDefault();

                const currentIndex =
                    tabs.indexOf(tab);

                const direction =
                    event.key === 'ArrowRight'
                        ? 1
                        : -1;

                const nextIndex =
                    (
                        currentIndex +
                        direction +
                        tabs.length
                    ) % tabs.length;

                setActiveTab(
                    tabs[nextIndex]
                        .dataset.issueTab
                );
            }
        );
    });

    window.addEventListener(
        'popstate',
        () => {
            const params =
                new URLSearchParams(
                    window.location.search
                );

            setActiveTab(
                params.get('view') ===
                    'collection'
                    ? 'collection'
                    : 'details',
                false
            );
        }
    );

    async function inventoryRequest(
        action,
        values
    ) {
        if (
            typeof window.tcsInventory ===
            'undefined'
        ) {
            throw new Error(
                'The collection editor is unavailable. Refresh the page and try again.'
            );
        }

        const body =
            values instanceof FormData
                ? values
                : new FormData();

        body.set('action', action);
        body.set(
            'nonce',
            window.tcsInventory.nonce
        );

        const response = await fetch(
            window.tcsInventory.ajaxUrl,
            {
                method: 'POST',
                credentials: 'same-origin',
                body
            }
        );

        let payload;

        try {
            payload = await response.json();
        } catch (error) {
            throw new Error(
                'The server returned an invalid response.'
            );
        }

        if (
            !response.ok ||
            payload.success !== true
        ) {
            const message =
                typeof payload.data ===
                'string'
                    ? payload.data
                    : payload.data?.message;

            throw new Error(
                message ||
                'The changes could not be saved.'
            );
        }

        return payload.data;
    }

    if (form) {
        form.addEventListener(
            'submit',
            async (event) => {
                event.preventDefault();

                if (saving) {
                    return;
                }

                saving = true;
                setFeedback('');

                const submitButton =
                    form.querySelector(
                        '[type="submit"]'
                    );

                const originalText =
                    submitButton.textContent;

                submitButton.disabled = true;
                submitButton.textContent =
                    'Saving…';

                try {
                    const result =
                        await inventoryRequest(
                            'tcs_inventory_save',
                            new FormData(form)
                        );

                    if (result.record) {
                        form.elements.version.value =
                            result.record.version;

                        form.elements.qty.value =
                            result.record.qty;

                        form.elements.price.value =
                            result.record.price;

                        form.elements.condition.value =
                            result.record.condition;

                        form.elements.storage_location.value =
                            result.record
                                .storage_location;

                        form.elements.notes.value =
                            result.record.notes;
                    }

                    savedSnapshot =
                        new URLSearchParams(
                            new FormData(form)
                        ).toString();

                    setFeedback(
                        'Your collection details were saved.'
                    );
                } catch (error) {
                    setFeedback(
                        error.message,
                        true
                    );
                } finally {
                    saving = false;
                    submitButton.disabled = false;
                    submitButton.textContent =
                        originalText;
                }
            }
        );

        window.addEventListener(
            'beforeunload',
            (event) => {
                if (saving) {
                    return;
                }

                const currentSnapshot =
                    new URLSearchParams(
                        new FormData(form)
                    ).toString();

                if (
                    currentSnapshot ===
                    savedSnapshot
                ) {
                    return;
                }

                event.preventDefault();
                event.returnValue = '';
            }
        );
    }

    /*
     * comic-book.js triggers this after an issue is
     * added to or removed from the collection.
     *
     * Reloading reconstructs the correct server-side
     * form and generates a fresh version token.
     */
    if (window.jQuery) {
        window.jQuery(document).on(
            'comicbooks:collection-changed',
            () => {
                const url =
                    new URL(
                        window.location.href
                    );

                url.searchParams.set(
                    'view',
                    'collection'
                );

                window.location.assign(
                    url.toString()
                );
            }
        );
    }
})();