<?php
defined('ABSPATH') || exit;
$summary = $overview['summary'];
$value = static function ($key, $fallback = '') use ($input) { return tcs_inventory_text($input[$key] ?? $fallback); };
$view = $value('collection_view', 'shelf') === 'inventory' ? 'inventory' : 'shelf';
$archive_url = get_post_type_archive_link('collection');
?>
<div class="tci-shell" data-view="<?php echo esc_attr($view); ?>">
    <header class="tci-heading">
        <div><p class="tci-intro">Enjoy the covers. Keep the details in order.</p></div>
        <a class="tci-button tci-button-primary" href="<?php echo esc_url(home_url('/comic-catalog/')); ?>"><span aria-hidden="true">＋</span> Add comics</a>
    </header>

    <dl class="tci-stats" aria-label="Your entire collection">
        <div><dt>Collection entries</dt><dd data-stat="entries"><?php echo (int) $summary['entries']; ?></dd></div>
        <div><dt>Total copies</dt><dd data-stat="copies"><?php echo (int) $summary['copies']; ?></dd></div>
        <div><dt>Series collected</dt><dd data-stat="series"><?php echo (int) $summary['series']; ?></dd></div>
        <div class="tci-stats-note"><span aria-hidden="true">✦</span><p>A place for every issue.<br><strong>Make it your own.</strong></p></div>
    </dl>

    <form id="tci-filters" class="tci-filters" method="get" action="<?php echo esc_url($archive_url); ?>" role="search" aria-label="Search and filter your collection">
        <div class="tci-search"><label for="tci-search">Search series</label><input id="tci-search" type="search" name="collection_search" placeholder="Find a title in your collection…" value="<?php echo esc_attr($value('collection_search')); ?>"></div>
        <div><label for="tci-publisher">Publisher</label><select id="tci-publisher" name="collection_publisher"><option value="">All publishers</option><?php foreach ($overview['publishers'] as $publisher) : ?><option value="<?php echo (int) $publisher['id']; ?>" <?php selected($value('collection_publisher'), (string) $publisher['id']); ?>><?php echo esc_html($publisher['name']); ?></option><?php endforeach; ?></select></div>
        <div><label for="tci-series">Series</label><select id="tci-series" name="collection_series"><option value="">All series</option><?php foreach ($overview['series'] as $series) : ?><option value="<?php echo (int) $series['id']; ?>" <?php selected($value('collection_series'), (string) $series['id']); ?>><?php echo esc_html($series['name']); ?></option><?php endforeach; ?></select></div>
        <div><label for="tci-sort">Sort by</label><select id="tci-sort" name="collection_sort"><?php foreach (['recent' => 'Recently added', 'title' => 'Series A–Z', 'issue' => 'Series + issue number', 'oldest' => 'Oldest added'] as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($value('collection_sort', 'recent'), $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></div>
        <input type="hidden" name="collection_page" value="<?php echo (int) $results['page']; ?>">
        <input type="hidden" name="collection_view" value="<?php echo esc_attr($view); ?>">
        <button class="tci-button tci-filter-submit" type="submit">Apply filters</button>
        <div class="tci-filter-extras"><label class="tci-check"><input type="checkbox" name="collection_duplicates" value="1" <?php checked($value('collection_duplicates'), '1'); ?>> Multiple copies only</label><a href="<?php echo esc_url($archive_url); ?>" id="tci-reset">Clear filters</a></div>
    </form>

    <noscript><p class="tci-feedback">Enable JavaScript to edit inventory, switch views, or use bulk actions. Search and filters still work with Apply filters.</p></noscript>
    <section class="tci-library" aria-labelledby="tci-results-heading">
        <header class="tci-results-header">
            <div><p class="tci-kicker">THE GOOD STUFF</p><h2 id="tci-results-heading">Your comic shelf <span id="tci-total"><?php echo (int) $results['total']; ?> entries</span></h2></div>
            <div class="tci-view-switch" role="group" aria-label="Collection view"><button type="button" data-view="shelf" aria-pressed="<?php echo $view === 'shelf' ? 'true' : 'false'; ?>"><span aria-hidden="true">▦</span> Cover shelf</button><button type="button" data-view="inventory" aria-pressed="<?php echo $view === 'inventory' ? 'true' : 'false'; ?>"><span aria-hidden="true">☷</span> Inventory</button></div>
        </header>
        <div class="tci-selection-toolbar"><label class="tci-check"><input id="tci-select-all" type="checkbox"> Select this page</label><p id="tci-selection-count">0 selected</p><button type="button" class="tci-button" id="tci-bulk-location" disabled>Set location</button><button type="button" class="tci-text-button tci-danger" id="tci-bulk-trash" disabled>Move to trash</button></div>
        <p class="tci-feedback" id="tci-feedback" role="status" aria-live="polite" aria-atomic="true"></p>
        <div class="tci-undo" id="tci-undo" hidden><span>Entries moved to trash.</span><button type="button" class="tci-text-button" id="tci-undo-button">Undo</button></div>
        <div id="tci-results" aria-busy="false"><?php echo $results['html']; // All record fields escaped by tcs_inventory_items_html(). ?></div>
        <nav id="tci-pagination" class="tci-pagination" aria-label="Collection pages">
            <?php for ($page_no = max(1, $results['page'] - 2); $page_no <= min($results['pages'], $results['page'] + 2); $page_no++) : ?><a href="<?php echo esc_url(add_query_arg(array_merge(array_map('tcs_inventory_text', $input), ['collection_page' => $page_no]), $archive_url)); ?>" data-page="<?php echo (int) $page_no; ?>" <?php if ($page_no === $results['page']) echo 'aria-current="page"'; ?>><?php echo (int) $page_no; ?></a><?php endfor; ?>
        </nav>
    </section>
    <footer class="tci-footnote">Your saved collection only. Quantities describe copies; each entry shares one condition, price, and location.</footer>

    <dialog id="tci-editor" class="tci-dialog" aria-labelledby="tci-editor-title">
        <form id="tci-editor-form">
            <header><div><p class="tci-kicker">INVENTORY DETAILS</p><h2 id="tci-editor-title">Edit comic</h2></div><button type="button" class="tci-close" data-close aria-label="Close editor">×</button></header>
            <input type="hidden" name="post_id"><input type="hidden" name="version">
            <div class="tci-editor-grid">
                <div><label for="tci-qty">Quantity</label><input id="tci-qty" name="qty" type="number" min="1" max="9999" step="1" required></div>
                <div><label for="tci-price">Price</label><input id="tci-price" name="price" type="text" inputmode="decimal" pattern="[0-9]{1,7}(\.[0-9]{1,2})?" aria-describedby="tci-price-help"><small id="tci-price-help">Your recorded price; no market estimate.</small></div>
                <div class="tci-wide"><label for="tci-condition">Condition / grade</label><input id="tci-condition" name="condition" maxlength="120" list="tci-grades" placeholder="e.g. 9.4 (NEAR MINT)"><datalist id="tci-grades"><option value="10.0 (GEM MINT)"><option value="9.8 (NEAR MINT / MINT)"><option value="9.4 (NEAR MINT)"><option value="9.0 (VERY FINE / NEAR MINT)"><option value="8.0 (VERY FINE)"><option value="6.0 (FINE)"><option value="4.0 (VERY GOOD)"><option value="2.0 (GOOD)"><option value="Ungraded"></datalist></div>
                <div class="tci-wide"><label for="tci-location">Storage location</label><input id="tci-location" name="storage_location" maxlength="120" placeholder="e.g. Short box 02 · DC"></div>
                <div class="tci-wide"><label for="tci-notes">Notes</label><textarea id="tci-notes" name="notes" maxlength="10000" rows="4" placeholder="Variant cover, signature, provenance, or a reminder…"></textarea></div>
            </div>
            <p id="tci-editor-error" class="tci-form-error" role="alert"></p>
            <footer><button type="button" class="tci-text-button tci-danger" id="tci-trash-one">Move to trash</button><div><button type="button" class="tci-button" data-close>Cancel</button><button type="submit" class="tci-button tci-button-primary">Save changes</button></div></footer>
        </form>
    </dialog>
    <dialog id="tci-action-dialog" class="tci-dialog tci-small-dialog" aria-labelledby="tci-action-title">
        <form id="tci-action-form"><header><h2 id="tci-action-title">Update selected comics</h2><button type="button" class="tci-close" data-close aria-label="Close action">×</button></header><p id="tci-action-description"></p><div id="tci-action-location-wrap"><label for="tci-action-location">Storage location</label><input id="tci-action-location" maxlength="120" placeholder="e.g. Short box 02 · DC"></div><p id="tci-action-error" class="tci-form-error" role="alert"></p><footer><button type="button" class="tci-button" data-close>Cancel</button><button type="submit" class="tci-button tci-button-primary" id="tci-action-submit">Confirm</button></footer></form>
    </dialog>
</div>
