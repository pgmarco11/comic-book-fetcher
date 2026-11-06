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

    public function era_breakdown_html(int $user_id): string {
            
        $colors = [
            'Platinum'     => '#56B4E9',
            'Golden'       => '#E69F00',
            'Silver'       => '#009E73',
            'Bronze'       => '#D55E00',
            'Modern'       => '#0072B2',
            'Post-Modern'  => '#CC79A7',
            'Unknown year' => '#8A8A8A',
        ];

        $era_ranges = [
            'Platinum'    => 'Before 1938',
            'Golden'      => '1938–1956',
            'Silver'      => '1957–1970',
            'Bronze'      => '1971–1985',
            'Modern'      => '1986–2011',
            'Post-Modern' => '2012 onward',
        ];

        return $this->pie_breakdown_html(
            $this->repository->era_counts($user_id),
            $colors,
            $era_ranges
        );

    }

    private function pie_breakdown_html(
            array $counts,
            array $colors,
            array $era_ranges = []
        ): string {

        $total = array_sum($counts);
    
        if ($total === 0) {
            return '<p>Add comics to see your collection by era.</p>';
        }
        
        $stops = [];
        $legend = '';
        $running = 0;
    
        foreach ($colors as $era => $color) {
            $count = $counts[$era] ?? 0;
    
            if ($count === 0) {
                continue;
            }
    
            $start = 100 * $running / $total;
            $running += $count;
            $end = 100 * $running / $total;
            $stops[] = sprintf('%s %.6F%% %.6F%%', $color, $start, $end);
    
            $percentage = number_format_i18n(100 * $count / $total, 1);

            $range = $era_ranges[$era] ?? '';

            $legend .= '<li><span class="tci-era-swatch" aria-hidden="true" style="background-color: '
                . esc_attr($color) . '"></span> ' . esc_html($era)
                . ' <strong>' . esc_html(number_format_i18n($count)) . '</strong>'
                . ' <span>(' . esc_html($percentage) . '%)</span>';
            
            if ($range !== '') {
                $legend .= '<small class="tci-era-range">' . esc_html($range) . '</small>';
            }
            
            $legend .= '</li>';
        }
    
        return '<div class="tci-era-layout"><div class="tci-era-pie" aria-hidden="true" style="--tci-era-fill: '
            . esc_attr(implode(', ', $stops)) . '"></div><ul class="tci-era-legend">'
            . $legend . '</ul></div>';
    }

    private function taxonomy_breakdown_html(int $user_id, string $taxonomy): string {
        $counts = $taxonomy === 'comic_genre'
            ? $this->repository->genre_assignment_counts($user_id)
            : $this->repository->taxonomy_entry_counts($user_id, $taxonomy);

        if ($taxonomy === 'comic_genre' && !$counts) {
            return '<p>No genres have been assigned to your collection entries yet.</p>';
        }
        $total = array_sum($counts);
        $minimum_share = 0.017; // 1.7% of the chart total.
        
        $visible = in_array($taxonomy, ['comic_series', 'comic_genre'], true)
            ? array_filter(
                $counts,
                static fn ($count) => $total > 0 && $count / $total >= $minimum_share
            )
            : array_slice($counts, 0, 6, true);
        
        $remaining = $total - array_sum($visible);
        
        if ($remaining > 0) {
            $other_label = $taxonomy === 'comic_genre'
                ? 'Other genre assignments'
                : 'Other entries';
        
            $visible[$other_label] = ($visible[$other_label] ?? 0) + $remaining;
        }
    
        $palette = [
            '#0072B2', '#E69F00', '#009E73', '#D55E00',
            '#CC79A7', '#56B4E9', '#8A8A8A',
        ];
    
        $colors = [];
    
        foreach (array_keys($visible) as $index => $label) {
            $colors[$label] = $palette[$index % count($palette)];
        }
    
        return $this->pie_breakdown_html($visible, $colors);
    }

    public function render_app(): void {
        $inventory_url = tcs_inventory_url();

        if (!is_user_logged_in()) {
            echo '<div class="tci-empty"><h1>My collection</h1><p>Sign in to view and manage your comics.</p><a class="tci-button tci-button-primary" href="' . esc_url(wp_login_url($inventory_url)) . '">Sign in</a></div>';
            return;
        }
        $input = wp_unslash($_GET);
        $show_charts = ($input['collection_view'] ?? '') === 'charts';
        $charts_url = add_query_arg('collection_view', 'charts', $inventory_url);

        ?>
            <nav class="tci-section-nav" aria-label="My Collection sections">
                <a href="<?php echo esc_url($inventory_url); ?>"<?php if (!$show_charts) echo ' aria-current="page"'; ?>>Collection</a>
                <a href="<?php echo esc_url($charts_url); ?>"<?php if ($show_charts) echo ' aria-current="page"'; ?>>Charts</a>
            </nav>
            <?php

            if ($show_charts) {
                    ?>
                        <section class="tci-era-breakdown" aria-labelledby="tci-era-heading">
                            <h2 id="tci-era-heading">Collection by era</h2>
                            <p>Based on publication years across your entire collection. Each entry counts once.</p>
                            <?php echo $this->era_breakdown_html(get_current_user_id()); ?>
                        </section>

                    <?php foreach ([
                            'publisher'    => 'Collection by publisher',
                            'comic_series' => 'Most collected series',
                            'comic_genre' => 'Most collected genres',
                        ] as $taxonomy => $heading) : ?>
                            <section class="tci-era-breakdown"
                                    aria-labelledby="tci-chart-<?php echo esc_attr($taxonomy); ?>">
                                <h2 id="tci-chart-<?php echo esc_attr($taxonomy); ?>">
                                    <?php echo esc_html($heading); ?>
                                </h2>                                                       
                                <?php if ($taxonomy === 'comic_genre') : ?>
                                    <p>Percentages show the share of all genre assignments.
                                    A comic can contribute to more than one genre.</p>
                                <?php else : ?>
                                    <p>Each collection entry counts once.</p>
                                <?php endif; ?>
                                <?php echo $this->taxonomy_breakdown_html(get_current_user_id(), $taxonomy); ?>
                            </section>
                    <?php endforeach; ?>

                <?php
                return;
            }

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
