<?php
defined('ABSPATH') || exit;

function comicbooks_issue_pagination_html(
    int $title_id,
    int $page,
    int $total_pages,
    string $search = ''
): string {
    if ($title_id < 1 || $total_pages <= 1) {
        return '';
    }

    $page = max(1, min($page, $total_pages));
    $start = max(1, min($page - 2, $total_pages - 4));
    $end = min($total_pages, $start + 4);
    $links = [];

    if ($page > 1) {
        $links[] = [1, 'First'];
        $links[] = [$page - 1, 'Previous'];
    }

    for ($number = $start; $number <= $end; $number++) {
        $links[] = [$number, (string) $number];
    }

    if ($page < $total_pages) {
        $links[] = [$page + 1, 'Next'];
        $links[] = [$total_pages, 'Last'];
    }

    $base = home_url('/comic-database/issues/');

    ob_start();
    ?>
    <p>Page <?php echo $page; ?> of <?php echo $total_pages; ?></p>

    <?php foreach ($links as [$number, $label]) :
        $args = ['title_id' => $title_id, 'page' => $number];
        if ($search !== '') {
            $args['search'] = rawurlencode($search);
        }
        $active = $number === $page;
        ?>
        <a
            class="page-btn<?php echo $active ? ' active' : ''; ?>"
            href="<?php echo esc_url(add_query_arg($args, $base)); ?>"
            data-page="<?php echo $number; ?>"
            data-title-id="<?php echo $title_id; ?>"
            data-search="<?php echo esc_attr($search); ?>"
            <?php if ($active) : ?>aria-current="page"<?php endif; ?>
        ><?php echo esc_html($label); ?></a>
    <?php endforeach; ?>

    <form class="issue-page-jump"
          data-title-id="<?php echo $title_id; ?>"
          data-total-pages="<?php echo $total_pages; ?>">
        <label for="issue-page-jump-input">Go to page</label>
        <input type="number" id="issue-page-jump-input"
               class="issue-page-jump__input" min="1"
               max="<?php echo $total_pages; ?>" inputmode="numeric" required>
        <button type="submit" class="issue-page-jump__button">Go</button>
    </form>
    <?php

    return ob_get_clean();
}