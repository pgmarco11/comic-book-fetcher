(() => {
    'use strict';
    const root = document.querySelector('.tcs-billing');
    if (!root) return;

    const message = root.querySelector('.tcs-billing-message');
    const cardForm = root.querySelector('.tcs-billing-card-form');
    const detailsForm = root.querySelector('.tcs-billing-details-form');
    const openButton = root.querySelector('[data-change-method]');
    let stripe;
    let elements;
    let payment;
    let setupId = '';
    let confirmed = false;
    let busy = false;
    let fieldsReady = false;

    async function request(operation, values = {}) {
        const body = new FormData();
        body.set('action', 'tcs_embedded_billing');
        body.set('operation', operation);
        body.set('subscription_id', root.dataset.subscription);
        body.set('nonce', root.dataset.nonce);
        Object.entries(values).forEach(([key, value]) => body.set(key, value));
        const response = await fetch(root.dataset.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            body,
        });
        let payload;
        try {
            payload = await response.json();
        } catch {
            throw new Error('The server could not complete this update. Please try again.');
        }
        if (!response.ok || !payload.success) {
            throw new Error(payload.data?.message || 'The update could not complete.');
        }
        if (payload.data.superseded) {
            throw new Error(payload.data.message);
        }
        return payload.data;
    }

    function setBusy(value) {
        busy = value;
        root.setAttribute('aria-busy', String(value));
        root.querySelectorAll('button').forEach((button) => {
            button.disabled = value;
        });
        if (cardForm && !value) {
            cardForm.querySelector('button[type="submit"]').disabled = !elements || !fieldsReady;
        }
    }

    if (cardForm) {
        openButton.addEventListener('click', async () => {
            if (busy) return;
            cardForm.hidden = false;
            if (elements) {
                root.querySelector('.tcs-billing-consent input').focus();
                return;
            }
            const error = cardForm.querySelector('.tcs-billing-error');
            error.textContent = '';
            message.textContent = 'Loading secure card fields…';
            setBusy(true);
            try {
                if (typeof window.Stripe !== 'function') {
                    throw new Error('The secure card form could not load. Refresh to try again.');
                }
                const data = await request('setup');
                setupId = data.setupId;
                confirmed = false;
                stripe = window.Stripe(root.dataset.publishableKey);
                elements = stripe.elements({
                    clientSecret: data.clientSecret,
                    appearance: {
                        theme: 'stripe',
                        variables: {
                            colorPrimary: '#0054a6',
                            colorText: '#002147',
                            borderRadius: '6px',
                        },
                    },
                });
                payment = elements.create('payment', { layout: 'tabs' });
                payment.on('ready', () => {
                    fieldsReady = true;
                    if (!busy) cardForm.querySelector('button[type="submit"]').disabled = false;
                });
                payment.on('loaderror', () => {
                    fieldsReady = false;
                    error.textContent =
                        'The secure card fields could not load. Reload the page to try again.';
                    cardForm.querySelector('button[type="submit"]').disabled = true;
                });
                payment.mount(root.querySelector('[data-payment-element]'));
            } catch (failure) {
                error.textContent = failure.message;
            } finally {
                message.textContent = '';
                setBusy(false);
            }
        });

        root.querySelector('[data-close-method]').addEventListener('click', () => {
            if (busy) return;
            cardForm.hidden = true;
            openButton.focus();
        });

        cardForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (busy || !elements || !fieldsReady || !cardForm.reportValidity()) return;
            const error = cardForm.querySelector('.tcs-billing-error');
            error.textContent = '';
            message.textContent = 'Saving payment method…';
            setBusy(true);
            try {
                if (!confirmed) {
                    await request('approve', { setup_id: setupId, consent: '1' });
                    const result = await stripe.confirmSetup({
                        elements,
                        confirmParams: { return_url: root.dataset.returnUrl },
                        redirect: 'if_required',
                    });
                    if (result.error) throw new Error(result.error.message);
                    if (result.setupIntent?.status !== 'succeeded') {
                        throw new Error('Stripe has not confirmed the card yet. Please try again.');
                    }
                    confirmed = true;
                }
                await request('finish', { setup_id: setupId });
                window.location.assign(root.dataset.returnUrl);
            } catch (failure) {
                message.textContent = '';
                error.textContent = failure.message;
                setBusy(false);
            }
        });
    }

    detailsForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy || !detailsForm.reportValidity()) return;
        const error = detailsForm.querySelector('.tcs-billing-error');
        error.textContent = '';
        setBusy(true);
        try {
            await request('details', {
                name: detailsForm.elements.name.value,
                email: detailsForm.elements.email.value,
            });
            window.location.assign(root.dataset.returnUrl);
        } catch (failure) {
            error.textContent = failure.message;
            setBusy(false);
        }
    });
})();
