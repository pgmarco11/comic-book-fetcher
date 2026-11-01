(() => {
    'use strict';

    const root = document.getElementById(
        'tcs-import-controls'
    );

    if (!root) return;

    const node = (name) =>
        root.querySelector(`[data-${name}]`);

    const start = node('start');
    const cancel = node('cancel');

    const mapping = document.getElementById(
        'tcs-collection-owner-mapping'
    );

    const upload = document.querySelector(
        'input[name="action"][value="tcs_collection_import_preview"]'
    )?.closest('form');

    let progress = JSON.parse(root.dataset.summary);
    let busy = false;
    let cancelRequested = false;
    let fatal = false;
    let errorMessage = '';
    let mappingDirty = false;

    const active = () =>
        ['ready', 'running', 'paused'].includes(
            progress.state
        );

    const sleep = (ms) =>
        new Promise((resolve) => setTimeout(resolve, ms));

    function draw() {
        const completed =
            progress.created + progress.skipped;

        const total =
            progress.total_terms + progress.total_records;

        node('terms').textContent =
            `${progress.term_cursor} / ${progress.total_terms}`;

        node('completed').textContent =
            `${completed} / ${progress.total_records}`;

        ['created', 'skipped', 'failed'].forEach((key) => {
            node(key).textContent = progress[key];
        });

        node('progress').value =
            progress.state === 'complete'
                ? 100
                : total
                    ? Math.min(
                        100,
                        100 * (
                            progress.term_cursor + completed
                        ) / total
                    )
                    : 0;

        let label = 'Ready to import.';

        if (active()) {
            label = busy
                ? (
                    progress.phase === 'terms'
                        ? 'Preparing publishers, genres and series…'
                        : 'Importing comics…'
                )
                : 'Import saved. Choose Continue to resume.';
        }

        if (progress.state === 'paused') {
            label =
                'Import paused. Review the message before retrying.';
        }

        if (progress.state === 'complete') {
            label = 'Import complete.';
        }

        if (progress.state === 'cancelled') {
            label =
                'Import cancelled. Records already created have been kept.';
        }

        if (cancelRequested) {
            label = 'Stopping after the current request…';
        }

        node('status').textContent = label;

        node('error').textContent = mappingDirty
            ? 'Save the destination mapping before starting the import.'
            : errorMessage || progress.message || '';

        node('error').hidden =
            !node('error').textContent;

        start.hidden =
            ['complete', 'cancelled'].includes(
                progress.state
            );

        start.disabled =
            busy || fatal || mappingDirty;

        start.textContent =
            progress.state === 'mapped'
                ? 'Start import'
                : progress.state === 'paused'
                    ? 'Retry import'
                    : 'Continue import';

        cancel.hidden = !active();
        cancel.disabled = cancelRequested || fatal;

        if (mapping) {
            mapping.hidden =
                progress.state !== 'mapped' || busy;
        }

        if (upload) {
            upload.querySelectorAll(
                'input[type="file"], input[type="submit"], button'
            ).forEach((input) => {
                input.disabled = active() || busy;
            });
        }
    }

    async function send(command) {
        for (let attempt = 0; attempt < 5; attempt++) {
            let response;

            try {
                response = await fetch(root.dataset.url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'Content-Type':
                            'application/x-www-form-urlencoded;charset=UTF-8',
                    },
                    body: new URLSearchParams({
                        action:
                            'tcs_collection_import_request',
                        job_token: root.dataset.token,
                        nonce: root.dataset.nonce,
                        command,
                    }),
                });
            } catch {
                throw new Error(
                    'Connection lost. Reload or continue from saved progress.'
                );
            }

            if (
                response.status === 409 &&
                attempt < 4
            ) {
                node('status').textContent =
                    'Waiting for another import request…';

                await sleep(1000 * (attempt + 1));
                continue;
            }

            let body;

            try {
                body = await response.json();
            } catch {
                throw new Error(
                    'The server returned an unexpected response. Reload this page before continuing.'
                );
            }

            if (
                !response.ok ||
                !body ||
                body.success !== true ||
                !body.data
            ) {
                const error = new Error(
                    body?.data?.message ||
                    'The import request failed. Reload this page before continuing.'
                );

                error.status = response.status;
                throw error;
            }

            return body.data;
        }
    }

    async function drive(command) {
        if (busy) return;

        busy = true;
        cancelRequested = command === 'cancel';
        errorMessage = '';

        draw();

        try {
            while (true) {
                const next = await send(command);

                if (next.revision >= progress.revision) {
                    progress = next;
                }

                draw();

                if (
                    !['ready', 'running'].includes(
                        progress.state
                    ) &&
                    !(cancelRequested && active())
                ) {
                    break;
                }

                await sleep(250);

                command = cancelRequested
                    ? 'cancel'
                    : 'batch';
            }
        } catch (error) {
            errorMessage = cancelRequested
                ? 'Cancellation was not confirmed. '
                    + error.message
                : error.message;

            fatal = [401, 403].includes(error.status);
        } finally {
            busy = false;
            cancelRequested = false;

            draw();
        }
    }

    start.addEventListener('click', () => {
        if (
            !busy &&
            !fatal &&
            !mappingDirty &&
            !start.hidden
        ) {
            drive(
                progress.state === 'mapped'
                    ? 'start'
                    : 'resume'
            );
        }
    });

    cancel.addEventListener('click', () => {
        if (fatal || !active()) return;

        if (busy) {
            cancelRequested = true;
            draw();
        } else {
            drive('cancel');
        }
    });

    if (mapping) {
        mapping.addEventListener('change', () => {
            mappingDirty = true;
            draw();
        });
    }

    draw();
})();