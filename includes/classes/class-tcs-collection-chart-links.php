<?php
defined('ABSPATH') || exit;

class TCS_Collection_Chart_Links {

    private const TYPES = [
        'publisher',
        'comic_series',
        'comic_genre',
        'creators',
        'era',
    ];

    public static function urls(array $counts, string $type): array {
        if (!in_array($type, self::TYPES, true)) {
            return [];
        }

        $links = [];

        foreach ($counts as $label => $count) {
            if ($label === 'Unassigned or ambiguous') {
                continue;
            }

            $links[$label] = add_query_arg([
                'collection_view' => 'inventory',
                'collection_page' => 1,
                'collection_chart_type' => $type,
                'collection_chart_label' => rawurlencode((string) $label),
            ], tcs_inventory_url());
        }

        return $links;
    }

    public static function matching_ids(array $input): ?array {
        global $wpdb;

        $type = tcs_inventory_text(
            $input['collection_chart_type'] ?? ''
        );

        $label = tcs_inventory_text(
            $input['collection_chart_label'] ?? ''
        );

        if ($type === '' && $label === '') {
            return null;
        }

        $user_id = get_current_user_id();

        if (
            $user_id <= 0
            || $label === ''
            || !in_array($type, self::TYPES, true)
        ) {
            return [];
        }

        if (
            in_array(
                $type,
                ['publisher', 'comic_series', 'comic_genre'],
                true
            )
        ) {
            $sql = "SELECT DISTINCT p.ID
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->term_relationships} tr
                    ON tr.object_id = p.ID
                INNER JOIN {$wpdb->term_taxonomy} tt
                    ON tt.term_taxonomy_id = tr.term_taxonomy_id
                INNER JOIN {$wpdb->terms} t
                    ON t.term_id = tt.term_id
                WHERE p.post_type = 'collection'
                    AND p.post_status = 'publish'
                    AND p.post_author = %d
                    AND tt.taxonomy = %s
                    AND t.name = %s";

            $args = [$user_id, $type, $label];

            /*
             * Publisher and series charts count entries with exactly
             * one assigned term. Match that same rule here.
             */
            if ($type !== 'comic_genre') {
                $sql .= " AND (
                    SELECT COUNT(*)
                    FROM {$wpdb->term_relationships} tr2
                    INNER JOIN {$wpdb->term_taxonomy} tt2
                        ON tt2.term_taxonomy_id = tr2.term_taxonomy_id
                    WHERE tr2.object_id = p.ID
                        AND tt2.taxonomy = %s
                ) = 1";

                $args[] = $type;
            }

            return array_map(
                'intval',
                $wpdb->get_col(
                    $wpdb->prepare($sql, ...$args)
                ) ?: []
            );
        }

        // Read only the metadata required for this filter.
        $keys = $type === 'creators'
            ? ['creators']
            : ['year', 'date_published'];

        $slots = implode(
            ', ',
            array_fill(0, count($keys), '%s')
        );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, pm.meta_key, pm.meta_value
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm
                    ON pm.post_id = p.ID
                    AND pm.meta_key IN ({$slots})
                 WHERE p.post_type = 'collection'
                    AND p.post_status = 'publish'
                    AND p.post_author = %d
                 ORDER BY p.ID, pm.meta_id",
                ...array_merge($keys, [$user_id])
            ),
            ARRAY_A
        ) ?: [];

        $values = [];

        foreach ($rows as $row) {
            $id = (int) $row['ID'];
            $values[$id] = $values[$id] ?? [];

            if (
                $row['meta_key'] !== null
                && !array_key_exists(
                    $row['meta_key'],
                    $values[$id]
                )
            ) {
                $values[$id][$row['meta_key']] =
                    $row['meta_value'];
            }
        }

        $ids = [];

        foreach ($values as $id => $meta) {
            if ($type === 'creators') {
                $lines = preg_split(
                    '/\R/u',
                    (string) ($meta['creators'] ?? '')
                ) ?: [];

                foreach ($lines as $line) {
                    $name = trim(
                        explode(' – ', $line, 2)[0]
                    );

                    if (
                        $name !== ''
                        && self::name_key($name)
                            === self::name_key($label)
                    ) {
                        $ids[] = $id;
                        break;
                    }
                }
            } else {
                $year = trim(
                    (string) ($meta['year'] ?? '')
                );

                if (!preg_match('/^[1-9]\d{3}$/D', $year)) {
                    preg_match(
                        '/^([1-9]\d{3})(?:-|$)/',
                        trim(
                            (string) ($meta['date_published'] ?? '')
                        ),
                        $match
                    );

                    $year = $match[1] ?? '';
                }

                $era = $year === ''
                    ? 'Unknown year'
                    : get_comic_era((int) $year);

                if ($era === $label) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    public static function index_ids(array $input): ?array {
        $ids = self::matching_ids($input);
    
        // An invalid or empty chart selection must stay empty.
        if ($ids === []) {
            return [];
        }
    
        $search = tcs_inventory_text(
            $input['collection_search'] ?? ''
        );
    
        if ($search === '') {
            return $ids;
        }
    
        $user_id = get_current_user_id();
    
        if ($user_id <= 0) {
            return [];
        }
    
        $args = [
            'post_type'              => 'collection',
            'post_status'            => 'publish',
            'author'                 => $user_id,
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            's'                      => $search,
            'search_columns'         => ['post_title'],
        ];
    
        if ($ids !== null) {
            $args['post__in'] = $ids;
        }
    
        $query = new WP_Query($args);
    
        return array_map('intval', $query->posts);
    }

    private static function name_key(string $name): string {
        return function_exists('mb_strtolower')
            ? mb_strtolower($name, 'UTF-8')
            : strtolower($name);
    }
}