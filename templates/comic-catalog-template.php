<?php
/**
 * comic-catalog-template.php
 * Used by ComicRenderer::render_template()
 * 100% identical to renderItems() output, except the empty‑state text.
 */

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

            <?php if ( empty( $items ) ) : ?>

                <?php
                $is_publisher = $type === 'publishers';
                $what         = $is_publisher ? 'publishers' : 'series';

                // Build a human-readable description of the active filter
                if ( ! empty( $search ) ) {
                    $filter_desc = 'matching "' . esc_html( $search ) . '"';
                } elseif ( ! empty( $letter ) && $letter !== 'all' ) {
                    $filter_desc = 'starting with "' . esc_html( strtoupper( $letter ) ) . '"';
                } else {
                    $filter_desc = '';
                }

                // Build a reset link that makes sense
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
                $showing = count( $items );
                $of      = $total;
                $what    = $is_publisher ? 'publishers' : 'series';
                $extra   = '';
                if ( $search ) {
                    $extra = ' for "' . esc_html( $search ) . '"';
                } elseif ( $letter && $letter !== 'all' ) {
                    $extra = ' starting with "' . esc_html( $letter ) . '"';
                }
                ?>
                <p>Showing <?php echo $showing; ?> of <?php echo $of; ?> <?php echo $what . $extra; ?></p>

                <?php foreach ( $items as $item ) :                     
                     echo print_r($item, true);
                    if ( $is_publisher ) : ?>
                        <div class="publisher-item" data-publisher-id="<?php echo esc_attr( $item['id'] ); ?>">
                            <a href="/comic-catalog/?publisher_id=<?php echo esc_attr($item['id']); ?>&letter=all&page=1">
                                <div class="publisher-image">
                                    <img  src="<?php echo esc_url(!empty($item['image']) ? $item['image'] : PUBLISHER_PLACEHOLDER_IMAGE_URL); ?>"
                                        alt="<?php echo esc_attr( $item['name'] ); ?>"
                                        loading="lazy">
                                </div>
                                <div class="publisher-info">                          
                                    <h3><?php echo esc_html( $item['name'] ); ?></h3>
                                    <p><strong>Founded:</strong> <?php echo esc_html( $item['founded'] ?? 'N/A' ); ?></p>
                                    <p><?php echo wp_kses_post( $item['desc'] ?? 'No description available.' ); ?></p>
                                </div>
                            </a>
                        </div>
                    <?php else : ?>
                        <div class="comic-title" data-series-id="<?php echo esc_attr($item['series_id']); ?>">                     
                            <a href="/comic-catalog/issues/?title_id=<?php echo esc_attr($item['series_id']); ?>&page=1">
                                <div class="comic-image">
                                <img 
                                    src="<?php echo esc_url( COMICBOOKS_PLUGIN_URL . 'images/placeholder.png' ); ?>"
                                    data-series-id="<?php echo esc_attr($item['series_id']); ?>"
                                    alt="<?php echo esc_attr($item['name']); ?>"
                                    loading="lazy"
                                    class="lazy-placeholder"
                                    width="220"
                                    height="330">
                                </div>
                                <div class="comic-info">
                                    <div class="comic-title-name"><?php echo esc_html($item['name']); ?></div>
                                    <div class="comic-title-meta">
                                        <p>Vol. <span><?php echo esc_html($item['volume'] ?? '1'); ?></span></p>
                                        <p>Issues: <span><?php echo esc_html($item['issue_count'] ?? 0); ?></span></p>
                                        <p>Started: <span><?php echo esc_html($item['year_began'] ?? 'N/A'); ?></span></p>
                                    </div>
                                </div>
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>    

    <?php if ($total_pages > 1): ?>
        <div class="pagination-wrapper">
            <p>Page <?php echo $page; ?> of <?php echo $total_pages; ?></p>

            <?php if ($page > 1): ?>
                <a href="<?php echo esc_url(add_query_arg('page', $page - 1)); ?>"
                    class="page-btn"
                    data-page="<?php echo $page - 1; ?>"
                    data-letter="<?php echo esc_attr($letter); ?>">
                        Previous
                    </a>
            <?php endif; ?>

            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                <a href="<?php echo esc_url(add_query_arg('page', $i)); ?>"
                    class="page-btn <?php echo $i === $page ? 'active' : ''; ?>"
                    data-page="<?php echo $i; ?>"
                    data-letter="<?php echo esc_attr($letter); ?>">
                        <?php echo $i; ?>
                    </a>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
                <a href="<?php echo esc_url(add_query_arg('page', $page + 1)); ?>" class="page-btn">
                    Next
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>