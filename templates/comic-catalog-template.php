<?php
/**
 * comic-catalog-template.php
 * Used by ComicRenderer::render_template()
 * 100% identical to renderItems() output, except the empty‑state text.
 */
$is_total_exact = $is_total_exact ?? true;
$scan_complete  = $scan_complete  ?? true;
$showing        = count( $items ?? [] );
$total_pages = $total > 0 ? ceil( $total / $per_page ) : 0;
$is_publisher = $type === 'publishers';

?>
<!-- SPINNER -->
<div id="loading-spinner" 
            class="spinner-overlay"                    
            aria-live="polite"                  
            aria-label="Loading content">
        <div class="spinner"></div>
        <p>Loading...</p>
</div>

<div id="book-container">            
    <div id="items-wrapper">
        <div class="<?php echo $is_publisher ? 'publishers' : 'book'; ?>-wrapper">

            <?php if ( empty( $items ) && empty( $scan_complete ) ) : ?>
                <p class="scan-in-progress">Searching — this can take a moment for large publishers…</p>
                <script>
                    window.__resumeScanOnLoad = {
                        publisherId: <?php echo (int) $selected_publisher; ?>,
                        page: <?php echo (int) $page; ?>,
                        search: <?php echo wp_json_encode( $search ); ?>,
                        letter: <?php echo wp_json_encode( $letter ); ?>
                    };
                </script>
            <?php elseif ( empty( $items ) ) : ?>

                <?php
                $is_publisher = $type === 'publishers';
                $what         = $is_publisher ? 'publishers' : 'series';

                if ( ! empty( $search ) ) {
                    $filter_desc = 'matching "' . esc_html( $search ) . '"';
                } elseif ( ! empty( $letter ) && $letter !== 'all' ) {
                    $filter_desc = 'starting with "' . esc_html( strtoupper( $letter ) ) . '"';
                } else {
                    $filter_desc = '';
                }

                if ( ! empty( $selected_publisher ) && ! $is_publisher ) {
                    $reset_url = add_query_arg([
                        'publisher_id' => $selected_publisher,
                        'letter'       => 'all',
                        'page'         => 1,
                    ]);
                    $reset_label = 'View all series for this publisher';
                } else {
                    $reset_url   = add_query_arg([ 'letter' => 'all', 'page' => 1 ]);
                    $reset_label = 'View all ' . $what;
                }
                ?>

                <p class="empty-state">
                    No <?php echo $what; ?> found<?php echo $filter_desc ? ' ' . $filter_desc : ''; ?>.
                    <a href="<?php echo esc_url( $reset_url ); ?>"><?php echo esc_html( $reset_label ); ?></a>
                </p>

            <?php else : ?>
                
                <?php             
                $of      = $total;
                $what    = $is_publisher ? 'publishers' : 'series';
                $extra   = '';
                if ( $search ) {
                    $extra = ' for "' . esc_html( $search ) . '"';
                } elseif ( $letter && $letter !== 'all' ) {
                    $extra = ' starting with "' . esc_html( $letter ) . '"';
                }
                ?>
                <p>
                    Showing <?php echo $showing; ?> of
                    <?php echo $of; ?><?php echo $is_total_exact ? '' : '+'; ?>
                    <?php echo $what . $extra; ?>
                </p>

                <?php foreach ( $items as $item ) :                        
                        if ($is_publisher) :
                            $publisher_id = absint($item['id'] ?? 0);
                            $publisher_loaded = !empty($item['publisher_loaded']);
                        ?>
                        <div
                            class="publisher-item"
                            data-publisher-id="<?php echo esc_attr($publisher_id); ?>"
                            <?php if ($publisher_loaded) : ?>
                                data-publisher-loaded="true"
                            <?php endif; ?>>

                                <a href="<?php
                                    echo esc_url(
                                        add_query_arg(
                                            [
                                                'publisher_id' => $publisher_id,
                                                'letter'       => 'all',
                                                'page'         => 1,
                                            ],
                                            home_url('/comic-catalog/')
                                        )
                                    );
                                ?>">
                                    <div class="publisher-image">
                                        <img
                                            src="<?php
                                                echo esc_url(
                                                    !empty($item['image'])
                                                        ? $item['image']
                                                        : PUBLISHER_PLACEHOLDER_IMAGE_URL
                                                );
                                            ?>"
                                            alt="<?php
                                                echo esc_attr(
                                                    $item['name'] ?? 'Publisher'
                                                );
                                            ?>"
                                            width="100"
                                            height="100"
                                            loading="lazy"
                                            decoding="async">
                                    </div>

                                    <div class="publisher-info">
                                        <h3>
                                            <?php
                                            echo esc_html(
                                                $item['name'] ?? 'Unknown publisher'
                                            );
                                            ?>
                                        </h3>

                                        <p>
                                            <strong>Founded:</strong>
                                            <span class="publisher-founded">
                                                <?php
                                                echo esc_html(
                                                    $publisher_loaded
                                                        ? ($item['founded'] ?: 'Unknown')
                                                        : 'Loading…'
                                                );
                                                ?>
                                            </span>
                                        </p>

                                        <p class="publisher-description">
                                            <?php
                                            echo esc_html(
                                                $publisher_loaded
                                                    ? ($item['desc'] ?: 'No description available.')
                                                    : 'Loading publisher information…'
                                            );
                                            ?>
                                        </p>
                                    </div>
                                </a>
                            </div>
                        <?php else :
                            /*
                            * Use Metron first. When Metron has no image, render a placeholder
                            * and let the JavaScript request the Comic Vine fallback.
                            */
                            $series_id = absint($item['series_id'] ?? 0);

                            $metron_image_url = !empty($item['image'])
                                ? (string) $item['image']
                                : '';

                            $needs_cv_fallback = empty($metron_image_url) &&
                                empty($item['image_resolved']);                            
                            
                            $display_image_url = $metron_image_url !== ''
                                ? $metron_image_url
                                : COMICBOOKS_PLUGIN_URL . 'images/placeholder.png';

                            ?>

                            <div
                                class="comic-title"
                                data-series-id="<?php echo esc_attr($series_id); ?>">

                                <a href="<?php
                                    echo esc_url(
                                        add_query_arg(
                                            [
                                                'title_id' => $series_id,
                                                'page'     => 1,
                                            ],
                                            home_url('/comic-catalog/issues/')
                                        )
                                    );
                                ?>">

                                    <div class="comic-image">
                                        <img
                                            src="<?php echo esc_url($display_image_url); ?>"
                                            alt="<?php echo esc_attr($item['name'] ?? 'Comic series'); ?>"

                                            <?php if ($needs_cv_fallback) : ?>
                                                data-series-id="<?php echo esc_attr($series_id); ?>"
                                                class="lazy-placeholder"
                                            <?php else : ?>
                                                data-loaded="true"
                                            <?php endif; ?>

                                            loading="lazy"
                                            decoding="async"
                                            width="100"
                                            height="150">
                                    </div>

                                    <div class="comic-info">
                                        <div class="comic-title-name">
                                            <?php echo esc_html($item['name'] ?? 'Unknown series'); ?>
                                        </div>

                                        <div class="comic-title-meta">
                                            <p>
                                                Vol.
                                                <span><?php echo esc_html($item['volume'] ?? '1'); ?></span>
                                            </p>

                                            <p>
                                                Issues:
                                                <span><?php echo esc_html($item['issue_count'] ?? 0); ?></span>
                                            </p>

                                            <p>
                                                Started:
                                                <span><?php echo esc_html($item['year_began'] ?? 'N/A'); ?></span>
                                            </p>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>    

    <?php if ($total_pages > 1 || (!$is_total_exact && $showing >= $per_page)): ?>
    <div class="pagination-wrapper">
        <p>
            Page <?php echo $page; ?> of
            <?php echo $total_pages; ?><?php echo $is_total_exact ? '' : '+'; ?>
        </p>

        <?php if ($page > 1): ?>
            <a href="<?php echo esc_url(add_query_arg('page', $page - 1)); ?>"
                class="page-btn" data-page="<?php echo $page - 1; ?>"
                data-letter="<?php echo esc_attr($letter); ?>">Previous</a>
        <?php endif; ?>

        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
            <a href="<?php echo esc_url(add_query_arg('page', $i)); ?>"
                class="page-btn <?php echo $i === $page ? 'active' : ''; ?>"
                data-page="<?php echo $i; ?>"
                data-letter="<?php echo esc_attr($letter); ?>"><?php echo $i; ?></a>
        <?php endfor; ?>

        <?php if ($page < $total_pages || !$is_total_exact): ?>
            <a href="<?php echo esc_url(add_query_arg('page', $page + 1)); ?>" class="page-btn">
                Next
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

</div>