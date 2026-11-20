<?php
defined('ABSPATH') || exit(); ?>
<div class="tcs-billing" data-ajax-url="<?php echo esc_url(
    admin_url('admin-ajax.php'),
); ?>" data-nonce="<?php echo esc_attr(
    wp_create_nonce('tcs_embedded_billing'),
); ?>" data-subscription="<?php echo esc_attr($id); ?>" data-publishable-key="<?php echo esc_attr(
    $publishable_key,
); ?>" data-return-url="<?php echo esc_url($billing_url); ?>">
    <p><a href="<?php echo esc_url(
        home_url('/manage-membership/'),
    ); ?>">‹ Back to Manage Membership</a></p>
    <?php if (count($subscriptions) > 1): ?>
        <nav aria-label="Billing subscriptions" class="tcs-billing-switch">
            <?php
            $number = 0;
            foreach ($subscriptions as $other_id => $other):
                $number++; ?>
                <a href="<?php echo esc_url(
                    add_query_arg(
                        'subscription_id',
                        $other_id,
                        home_url('/manage-membership/billing/'),
                    ),
                ); ?>" <?php if ($other_id === $id) {
    echo 'aria-current="page"';
} ?>><?php echo esc_html(
    'Subscription ' . $number . ' · ' . ucwords(str_replace('_', ' ', $other->status)),
); ?></a>
            <?php
            endforeach;
            ?>
        </nav>
    <?php endif; ?>
    <section class="tcs-billing-summary" aria-label="Membership billing summary">
        <h2>Premium subscription</h2>
        <p><?php echo esc_html(ucwords(str_replace('_', ' ', $subscription->status))); ?></p>
        <?php foreach ($subscription->items->data as $item): ?>
            <p class="tcs-billing-price"><?php echo esc_html(
                self::money((int) $item->price->unit_amount, $item->price->currency) .
                    ' / ' .
                    (($item->price->recurring->interval_count ?? 1) > 1
                        ? $item->price->recurring->interval_count . ' '
                        : '') .
                    $item->price->recurring->interval,
            ); ?></p>
            <?php if ($can_change && !empty($item->current_period_end)): ?>
                <p><?php echo esc_html(
                    ($subscription->cancel_at_period_end
                        ? 'Current period ends '
                        : ($subscription->status === 'active'
                            ? 'Next renewal '
                            : 'Current billing period ends ')) .
                        wp_date(get_option('date_format'), (int) $item->current_period_end),
                ); ?></p>
            <?php endif; ?>
        <?php endforeach; ?>
    </section>
    <p class="tcs-billing-message" role="status" aria-live="polite"></p>
    <section class="tcs-billing-section" aria-labelledby="tcs-billing-method-heading">
        <h3 id="tcs-billing-method-heading">Payment method</h3>
        <div class="tcs-billing-method">
            <div><strong><?php echo esc_html($method_label); ?></strong><p><?php echo esc_html(
    $method_expiry,
); ?></p></div>
            <?php if (
                $can_change
            ): ?><button type="button" class="tcs-billing-button" data-change-method>Change payment method</button><?php endif; ?>
        </div>
        <?php if ($can_change): ?>
            <form class="tcs-billing-card-form" hidden>
                <h4>New payment method</h4>
                <p>Add a card for future payments for this membership.</p>
                <div data-payment-element></div>
                <label class="tcs-billing-consent"><input type="checkbox" name="consent" value="1" required> Use this card for future payments for this membership.</label>
                <p class="tcs-billing-error" role="alert"></p>
                <div class="tcs-billing-actions"><button type="submit" class="tcs-billing-button" disabled>Save payment method</button><button type="button" class="tcs-billing-link" data-close-method>Cancel</button></div>
                <p class="tcs-billing-secure">Payment details secured by Stripe</p>
            </form>
        <?php endif; ?>
    </section>
    <section class="tcs-billing-section" aria-labelledby="tcs-billing-details-heading">
        <h3 id="tcs-billing-details-heading">Billing details</h3>
        <dl class="tcs-billing-contact"><div><dt>Billing name</dt><dd><?php echo esc_html(
            $customer->name ?: 'Not set',
        ); ?></dd></div><div><dt>Billing email</dt><dd><?php echo esc_html(
    $customer->email ?: 'Not set',
); ?></dd></div></dl>
        <details class="tcs-billing-edit"><summary>Edit details</summary>
            <form class="tcs-billing-details-form">
                <label>Billing name<input name="name" type="text" autocomplete="name" maxlength="100" required value="<?php echo esc_attr(
                    $customer->name ?? '',
                ); ?>"></label>
                <label>Billing email<input name="email" type="email" autocomplete="email" maxlength="254" required value="<?php echo esc_attr(
                    $customer->email ?? '',
                ); ?>"></label>
                <p>This is your Stripe billing email. Your WordPress sign-in email is managed separately.</p>
                <p class="tcs-billing-error" role="alert"></p>
                <button type="submit" class="tcs-billing-button">Save billing details</button>
            </form>
        </details>
    </section>
    <section class="tcs-billing-section" aria-labelledby="tcs-billing-invoices-heading">
        <h3 id="tcs-billing-invoices-heading">Invoice history</h3>
        <?php if (!$invoices->data): ?><p>No invoices to display.</p><?php else: ?>
            <table class="tcs-billing-invoices"><caption class="tcs-billing-sr-only">Invoices for the selected subscription</caption><thead><tr><th scope="col">Date</th><th scope="col">Amount</th><th scope="col">Status</th><th scope="col">Invoice</th></tr></thead><tbody>
                <?php foreach ($invoices->data as $invoice): ?>
                    <tr><td data-label="Date"><?php echo esc_html(
                        wp_date(get_option('date_format'), $invoice->created),
                    ); ?></td><td data-label="Amount"><?php echo esc_html(
    self::money((int) $invoice->total, $invoice->currency),
); ?></td><td data-label="Status"><?php echo esc_html(
    ucfirst($invoice->status),
); ?></td><td data-label="Invoice"><?php if ($invoice->invoice_pdf): ?><a href="<?php echo esc_url(
    $invoice->invoice_pdf,
); ?>" target="_blank" rel="noopener noreferrer">Download PDF<span class="tcs-billing-sr-only"> for <?php echo esc_html(
    wp_date(get_option('date_format'), $invoice->created),
); ?> (opens a new tab)</span></a><?php else: ?>PDF unavailable<?php endif; ?></td></tr>
                <?php endforeach; ?>
            </tbody></table>
            <nav class="tcs-billing-pagination" aria-label="Invoice pages">
                <?php if ($invoice_after !== ''): ?><a href="<?php echo esc_url(
    $billing_url,
); ?>">Latest invoices</a><?php endif; ?>
                <?php if ($invoices->has_more):
                    $last_invoice =
                        $invoices->data[count($invoices->data) - 1]; ?><a href="<?php echo esc_url(
    add_query_arg('invoice_after', $last_invoice->id, $billing_url),
); ?>">Older invoices</a><?php
                endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</div>
