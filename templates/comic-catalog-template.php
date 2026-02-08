<?php
/**
 * comic-books-template.php
 * Used by ComicRenderer::render_template()
 * 100% identical to renderItems() output, except the empty‑state text.
 */

$total_pages = $total > 0 ? ceil( $total / $per_page ) : 0;
$is_publisher = $type === 'publishers';

?>

<div id="book-container">

    <!-- SPINNER -->
    <div id="loading-spinner" class="spinner-overlay" aria-live="polite">
        <div class="spinner"></div>
        <p>Loading...</p>
    </div>
            
    <div id="items-wrapper">
        <div class="<?php echo $is_publisher ? 'publishers' : 'book'; ?>-wrapper">

            <?php if ( empty( $items ) ) : ?>
                
                <p>No publishers found for page <?php echo $page; ?>. <a href="?letter=all">Go to page 1</a> or <button onclick="location.reload()">Retry</button>. Cache warming...</p>

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

                    if ( $is_publisher ) : ?>
                        <div class="publisher-item" data-publisher-id="<?php echo esc_attr( $item['id'] ); ?>">
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
                        </div>
                    <?php else : ?>
                        <div class="comic-title" data-series-id="<?php echo esc_attr( $item['series_id'] ); ?>">

                            <div class="comic-image">
                            <img 
                                src="<?php echo esc_url(PUBLISHER_PLACEHOLDER_IMAGE_URL); ?>" 
                                data-src="<?php echo esc_url(!empty($item['first_issue_image']) ? $item['first_issue_image'] : ''); ?>" 
                                alt="<?php echo esc_attr( $item['name'] ); ?>" 
                                loading="lazy"
                                class="lazy-placeholder">
                            </div>
                            <div class="comic-info">

                                <div class="comic-title-name"><?php echo esc_html( $item['name'] ); ?></div>
                                <div class="comic-title-meta">
                                    <p>Vol. <span><?php echo esc_html( $item['volume'] ?? '1' ); ?></span></p>
                                    <p>Issues: <span><?php echo esc_html( $item['issue_count'] ?? 0 ); ?></span></p>
                                    <p>Started: <span><?php echo esc_html( $item['year_began'] ?? 'N/A' ); ?></span></p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>


    </div>
    <!-- DEBUG PANEL – ONLY VISIBLE TO ADMINS -->
<?php if (current_user_can('manage_options')): ?>
<div id="debug-panel" style="position:fixed; bottom:10px; right:10px; background:#000; color:#0f0; padding:10px; font-family:monospace; font-size:12px; max-width:400px; max-height:300px; overflow-y:auto; z-index:99999; border:2px solid #0f0; border-radius:8px; opacity:0.9;">
    <strong>DEBUG LOG (admin only)</strong><br>
    <div id="debug-log"></div>
    <button type="button" onclick="document.getElementById('debug-panel').style.display='none'" style="margin-top:5px; font-size:10px;">Close</button>
</div>

<script>
function debugLog(msg) {
    if (!document.getElementById('debug-log')) return;
    const now = new Date().toLocaleTimeString();
    const line = document.createElement('div');
    line.innerHTML = `[${now}] ${msg}`;
    document.getElementById('debug-log').prepend(line);
    console.log('DEBUG:', msg);
}
</script>
<?php endif; ?>
</div>