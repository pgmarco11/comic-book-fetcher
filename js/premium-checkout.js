(() => {
    'use strict';

    async function mountCheckout(form) {
        const fields = form.querySelector('.tcs-premium-payment__fields');
        const status = form.querySelector('.tcs-premium-payment__status');
        const error = form.querySelector('.tcs-premium-payment__error');
        const submit = form.querySelector('button[type="submit"]');
        const total = form.querySelector('.tcs-premium-payment__total');
        const price = form.closest('.tcs-premium-checkout')
            ?.querySelector('.tcs-premium-recurring-price');
        let actions;
        let submitting = false;
        let fieldsReady = false;
        let confirmed = false;

        function updateSession(session) {

            if (confirmed) {
                submit.disabled = true;
                return;
            }

            submit.disabled = !fieldsReady || submitting || !session.canConfirm;

            const dueToday = session.total?.total?.amount;
            if (dueToday) {
                total.querySelector('span').textContent = dueToday;
                total.hidden = false;
            }

            const amount = session.lineItems?.[0]?.unitAmount?.amount;
            const { interval, intervalCount } = session.recurring ?? {};
            if (price && amount && interval && intervalCount) {
                const period = intervalCount === 1 ? interval : `${intervalCount} ${interval}s`;
                price.textContent = `${amount} / ${period}`;
            }
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (!actions || submit.disabled || submitting || confirmed) {
                return;
            }

            submitting = true;
            submit.disabled = true;
            error.textContent = '';
            status.textContent = 'Processing your payment…';

            try {
                const result = await actions.confirm({
                    redirect: 'if_required',
                });

                if (result.type === 'error') {
                    error.textContent =
                        result.error.message ||
                        'Check your payment details and try again.';
                } else {
                    confirmed = true;

                    fields.hidden = true;
                    submit.hidden = true;
                    total.hidden = true;
                    status.textContent = '';

                    const options = form.previousElementSibling;

                    if (
                        options?.matches(
                            'nav[aria-label="Premium billing period"]'
                        )
                    ) {
                        options.hidden = true;
                    }

                    // A refresh should resume activation checking.
                    try {
                        window.history.replaceState(
                            null,
                            '',
                            form.dataset.activationUrl
                        );
                    } catch {}

                    const panel = form.querySelector(
                        '.tcs-premium-activation'
                    );

                    if (
                        typeof window.tcsStartPremiumActivation === 'function'
                    ) {
                        window.tcsStartPremiumActivation(panel);
                    } else {
                        window.location.assign(form.dataset.activationUrl);
                    }

                    return;
                }
            } catch {
                error.textContent =
                    'Payment could not be confirmed. Please try again.';
            }

            submitting = false;
            status.textContent = '';
            updateSession(actions.getSession());
        });

        try {
            if (typeof window.Stripe !== 'function') {
                throw new Error('The payment form could not load. Refresh the page to try again.');
            }

            const response = await fetch(form.getAttribute('action'), {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                body: new FormData(form),
            });
            const payload = await response.json();

            if (!response.ok || !payload.success || !payload.data?.clientSecret) {
                throw new Error(payload.data?.message || 'Checkout could not start. Refresh the page to try again.');
            }

            const stripe = window.Stripe(form.dataset.publishableKey);
            const checkout = stripe.initCheckoutElementsSdk({
                clientSecret: payload.data.clientSecret,
                elementsOptions: {
                    appearance: {
                        theme: 'stripe',
                        variables: {
                            colorPrimary: '#0059b3',
                            colorText: '#002044',
                            borderRadius: '6px',
                        },
                    },
                },
            });

            checkout.on('change', updateSession);
            const result = await checkout.loadActions();
            if (result.type !== 'success') {
                throw new Error('Stripe could not initialize checkout. Refresh the page to try again.');
            }

            actions = result.actions;
            const payment = checkout.createPaymentElement({ layout: 'accordion' });

            payment.on('ready', () => {
                fieldsReady = true;
                status.textContent = '';
                updateSession(actions.getSession());
            });
            payment.on('loaderror', () => {
                fieldsReady = false;
                submit.disabled = true;
                status.textContent = '';
                error.textContent = 'Payment fields could not load. Refresh the page to try again.';
            });

            updateSession(actions.getSession());
            payment.mount(fields);
        } catch (failure) {
            status.textContent = '';
            error.textContent = failure instanceof SyntaxError
                ? 'Checkout could not start. Sign in again or refresh the page.'
                : failure.message || 'Checkout could not start. Refresh the page to try again.';
        }
    }

    document.querySelectorAll('.tcs-premium-payment').forEach(mountCheckout);
})();
