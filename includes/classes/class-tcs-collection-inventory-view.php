<?php
defined('ABSPATH') || exit;

/** Owns collection inventory view behavior; legacy function entry points delegate here. */
class TCS_Collection_Inventory_View {
    private $repository;

    public function __construct(TCS_Collection_Inventory_Repository $repository) {
        $this->repository = $repository;
    }

    public function items_html(array $records): string {
        ob_start();
        if (!$records) {
            echo '<div class="tci-empty"><span aria-hidden="true">✦</span><h3>No issues here yet</h3><p>Try another filter, or add a comic from the catalog.</p><a class="tci-button tci-button-primary" href="' . esc_url(home_url('/comic-catalog/')) . '">Explore the comic catalog</a></div>';
        } else {
            echo '<ul class="tci-items" aria-label="Collection issues">';
            foreach ($records as $record) {
                $id = $record['id'];
                $name = $record['title'] . ($record['issue'] !== '' ? ' #' . $record['issue'] : '');
                ?>
            <li class="tci-item" data-record="<?php echo esc_attr(wp_json_encode($record)); ?>">
                <article aria-labelledby="tci-title-<?php echo (int) $id; ?>">
                    <label class="tci-select"><input type="checkbox" name="inventory_selected[]" value="<?php echo (int) $id; ?>"><span class="tci-sr-only">Select <?php echo esc_html($name); ?></span></label>
                    <a class="tci-cover" href="<?php echo esc_url($record['catalog_url']); ?>" aria-label="View <?php echo esc_attr($name); ?> in the catalog">
                        <?php if ($record['cover']) : ?><img src="<?php echo esc_url($record['cover']); ?>" alt="" loading="lazy" decoding="async" width="260" height="400"><?php else : ?><span class="tci-cover-placeholder"><span><?php echo esc_html($record['publisher'] ?: 'COLLECTION'); ?></span><strong><?php echo esc_html($record['title']); ?></strong><b>#<?php echo esc_html($record['issue'] ?: '—'); ?></b></span><?php endif; ?>
                        <?php if ($record['qty'] > 1) : ?><span class="tci-copy-badge"><?php echo (int) $record['qty']; ?> copies</span><?php endif; ?>
                    </a>
                    <div class="tci-item-info">
                        <p class="tci-publisher"><?php echo esc_html($record['publisher'] ?: 'Publisher not set'); ?></p>
                        <h3 id="tci-title-<?php echo (int) $id; ?>"><a href="<?php echo esc_url($record['catalog_url']); ?>"><?php echo esc_html($record['title']); ?> <span>#<?php echo esc_html($record['issue'] ?: '—'); ?></span></a></h3>
                        <p class="tci-volume">
                            <?php
                            if ($record['volume'] !== '') {
                                    echo 'Volume ' .
                                        esc_html(
                                            $record['volume']
                                        );
                                } else {
                                    echo 'Volume —';
                                }
                                if ($record['year'] !== '') {
                                    echo ' · ' .
                                        esc_html(
                                            $record['year']
                                        );
                                }
                                ?>
                        </p>
                    </div>
                    <dl class="tci-item-facts">
                        <div><dt>Quantity</dt><dd><?php echo (int) $record['qty']; ?></dd></div>
                        <div><dt>Condition</dt><dd><?php echo esc_html($record['condition'] ?: 'Not set'); ?></dd></div>
                        <div><dt>Price</dt><dd><?php echo $record['price'] === '' ? 'Not set' : esc_html($record['price']); ?></dd></div>
                        <div><dt>Location</dt><dd><?php echo esc_html($record['storage_location'] ?: 'Not filed'); ?></dd></div>
                    </dl>
                    <button type="button" class="tci-edit tci-button" aria-label="Edit <?php echo esc_attr($name); ?>">Edit inventory <span aria-hidden="true">↗</span></button>
                </article>
            </li>
            <?php
        }
            echo '</ul>';
        }
        return (string) ob_get_clean();
    }

    public function taxonomy_html(
        array $publishers
    ): string {
        $inventory_url = tcs_inventory_url();

        ob_start();
        ?>

    <section
        class="tci-series-index"
        aria-labelledby="tci-series-index-title"
    >
        <h3 id="tci-series-index-title">Organized by series</h3>
        <?php if (!$publishers) : ?>

            <div class="tci-empty">
                <h3>No organized series yet</h3>
                <p>
                    Add or refresh collection issues to organize
                    them by publisher and series.
                </p>
            </div>

        <?php else : ?>

            <div class="tci-publisher-groups">

                <?php foreach ($publishers as $publisher) : ?>

                    <details class="tci-publisher-group">

                        <summary>
                            <span class="tci-publisher-name">
                                <?php echo esc_html(
                                        $publisher['name']
                                    ); ?>
                            </span>

                            <span class="tci-count">
                                <?php
                                printf(
                                        esc_html(
                                            _n(
                                                '%s issue',
                                                '%s issues',
                                                $publisher['issue_count'],
                                                'comic-book-fetcher'
                                            )
                                        ),
                                        number_format_i18n(
                                            $publisher['issue_count']
                                        )
                                    );
                                    ?>
                            </span>
                        </summary>

                        <ul class="tci-series-list">

                            <?php
                            foreach (
                                    $publisher['series']
                                    as $series
                                ) :
                                    $series_url = add_query_arg(
                                        [
                                            'collection_publisher' =>
                                                $publisher['id'],
                                            'collection_series' =>
                                                $series['id'],
                                            'collection_view' =>
                                                'inventory',
                                        ],
                                        $inventory_url
                                    );
                                    ?>

                                <li>
                                    <a href="<?php
                                        echo esc_url($series_url);
                                                                                ?>">
                                        <span>
                                            <?php
                                            echo esc_html(
                                                    $series['name']
                                                );

                                                if (
                                                    !empty(
                                                        $series['volume']
                                                    )
                                                ) {
                                                    echo ' — Vol. ' .
                                                        esc_html(
                                                            $series['volume']
                                                        );
                                                }
                                                ?>
                                        </span>

                                        <span class="tci-count">
                                            <?php
                                            printf(
                                                    esc_html(
                                                        _n(
                                                            '%s issue',
                                                            '%s issues',
                                                            $series[
                                                                'issue_count'
                                                            ],
                                                            'comic-book-fetcher'
                                                        )
                                                    ),
                                                    number_format_i18n(
                                                        $series[
                                                            'issue_count'
                                                        ]
                                                    )
                                                );
                                                ?>
                                        </span>
                                    </a>
                                </li>

                            <?php endforeach; ?>

                        </ul>
                    </details>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>
    </section>

    <?php
    return (string) ob_get_clean();
    }

    public function render_app(): void {
        $inventory_url = tcs_inventory_url();

        if (!is_user_logged_in()) {
            echo '<div class="tci-empty"><h1>My collection</h1><p>Sign in to view and manage your comics.</p><a class="tci-button tci-button-primary" href="' . esc_url(wp_login_url($inventory_url)) . '">Sign in</a></div>';
            return;
        }
        $input = wp_unslash($_GET);
        $results = $this->repository->results($input);
        $publisher_id = absint(
            tcs_inventory_text(
                $input['collection_publisher'] ?? 0
            )
        );

        $overview = $this->repository->overview(
            get_current_user_id(),
            $publisher_id
        );

        $series_term_id = absint(
            tcs_inventory_text(
                $input['collection_series'] ?? 0
            )
        );

        $taxonomy_tree = $this->repository->taxonomy_tree(
            get_current_user_id(),
            $publisher_id,
            $series_term_id
        );

        $taxonomy_html = $this->taxonomy_html(
            $taxonomy_tree
        );
        include COMICBOOKS_PLUGIN_DIR . 'templates/collection-inventory.php';
    }

}
