(() => {
    'use strict';

    const panels = new WeakMap();

    const delay = (ms) =>
        new Promise((resolve) => setTimeout(resolve, ms));

    window.tcsStartPremiumActivation = (panel) => {
        panel.hidden = false;

        if (panels.has(panel)) {
            return panels.get(panel)();
        }

        const status = panel.querySelector('[data-activation-status]');
        const error = panel.querySelector('[data-activation-error]');
        const retry = panel.querySelector('[data-activation-retry]');

        let busy = false;
        let finished = false;

        const check = async () => {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 8000);

            try {
                const body = new FormData();

                body.set('action', 'tcs_premium_activation_status');
                body.set('security', panel.dataset.nonce);

                const response = await fetch(panel.dataset.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    body,
                    signal: controller.signal,
                });

                const payload = await response.json();

                if (response.status === 401 || response.status === 403) {
                    const failure = new Error(
                        payload.data?.message ||
                        'Refresh this page and sign in again.'
                    );

                    failure.stop = true;
                    throw failure;
                }

                if (
                    !response.ok ||
                    payload.success !== true ||
                    typeof payload.data?.premium !== 'boolean'
                ) {
                    throw new Error(
                        'Membership status could not be checked.'
                    );
                }

                return payload.data.premium;
            } finally {
                clearTimeout(timer);
            }
        };

        const run = async () => {
            if (busy || finished) {
                return;
            }

            busy = true;
            retry.hidden = true;
            error.textContent = '';
            status.textContent = 'Activating your membership…';

            const deadline = Date.now() + 45000;
            let checked = false;

            try {
                while (Date.now() < deadline) {
                    try {
                        const premium = await check();

                        checked = true;

                        if (premium) {
                            finished = true;

                            status.textContent =
                                'Premium is active. Opening My Collection…';

                            window.location.assign(panel.dataset.returnUrl);
                            return;
                        }
                    } catch (failure) {
                        if (failure.stop) {
                            throw failure;
                        }
                    }

                    if (Date.now() < deadline) {
                        await delay(1500);
                    }
                }

                status.textContent = checked
                    ? 'Your membership is not active yet.'
                    : 'Membership status is temporarily unavailable.';

                error.textContent =
                    'If you completed checkout, do not pay again. ' +
                    'Check activation again shortly. ' +
                    'If it remains pending, contact support.';
            } catch (failure) {
                status.textContent = '';
                error.textContent = failure.message;
            } finally {
                busy = false;

                if (!finished) {
                    retry.hidden = false;
                }
            }
        };

        panels.set(panel, run);
        retry.addEventListener('click', run);

        return run();
    };

    document
        .querySelectorAll('.tcs-premium-activation:not([hidden])')
        .forEach((panel) => window.tcsStartPremiumActivation(panel));
})();