<?php
defined('ABSPATH') || exit;

/** Checks backup metadata, taxonomy references and source owners. */
class TCS_Collection_Backup_Validator {
    public function validate_meta_rows(
        array $rows,
        string $context
    ): int {
        if (!array_is_list($rows)) {
            throw new RuntimeException(
                $context . ': metadata must be a list.'
            );
        }

        foreach ($rows as $index => $row) {
            $location = $context . ', metadata row ' . ($index + 1);

            if (
                !is_array($row) ||
                !is_string($row['key_b64'] ?? null) ||
                !is_string($row['value_b64'] ?? null)
            ) {
                throw new RuntimeException(
                    $location . ': the metadata structure is invalid.'
                );
            }

            $key = base64_decode($row['key_b64'], true);
            $value = base64_decode($row['value_b64'], true);

            if (
                $key === false ||
                $key === '' ||
                strpos($key, "\0") !== false ||
                $value === false
            ) {
                throw new RuntimeException(
                    $location . ': the metadata key or encoding is invalid.'
                );
            }
        }

        return count($rows);
    }

    public function validate_metadata(
        array $backup
    ): array {
        $counts = [
            'comic_rows' => 0,
            'term_rows' => 0,
        ];

        foreach ($backup['records'] as $index => $record) {
            if (
                !is_array($record) ||
                !is_array($record['meta'] ?? null)
            ) {
                throw new RuntimeException(
                    'Comic ' . ($index + 1) . ': metadata is missing.'
                );
            }

            $counts['comic_rows'] +=
                $this->validate_meta_rows(
                    $record['meta'],
                    'Comic ' . ($index + 1)
                );
        }

        foreach ($backup['term_definitions'] as $index => $term) {
            if (
                !is_array($term) ||
                !is_array($term['meta'] ?? null)
            ) {
                throw new RuntimeException(
                    'Term ' . ($index + 1) . ': metadata is missing.'
                );
            }

            $counts['term_rows'] +=
                $this->validate_meta_rows(
                    $term['meta'],
                    'Term ' . ($index + 1)
                );
        }

        return $counts;
    }

    public function validate_taxonomies(
        array $backup
    ): int {
        $allowed = get_object_taxonomies('collection', 'names');
        $definitions = [];

        foreach ($backup['term_definitions'] as $index => $term) {
            $context = 'Term definition ' . ($index + 1);

            if (
                !is_array($term) ||
                !is_string($term['ref'] ?? null) ||
                !is_string($term['taxonomy'] ?? null) ||
                !in_array($term['taxonomy'], $allowed, true) ||
                !is_string($term['name'] ?? null) ||
                trim($term['name']) === '' ||
                !is_string($term['slug'] ?? null) ||
                trim($term['slug']) === '' ||
                !is_string($term['description'] ?? null) ||
                !array_key_exists('parent_ref', $term)
            ) {
                throw new RuntimeException(
                    $context . ': invalid definition or unavailable taxonomy.'
                );
            }

            $prefix = $term['taxonomy'] . ':';
            $source_id = substr($term['ref'], strlen($prefix));

            if (
                !str_starts_with($term['ref'], $prefix) ||
                !preg_match('/^[1-9][0-9]*$/D', $source_id) ||
                isset($definitions[$term['ref']]) ||
                (
                    $term['parent_ref'] !== null &&
                    !is_string($term['parent_ref'])
                )
            ) {
                throw new RuntimeException(
                    $context . ': invalid or duplicate reference.'
                );
            }

            $definitions[$term['ref']] = $term;
        }

        foreach ($definitions as $reference => $term) {
            $seen = [$reference => true];
            $parent = $term['parent_ref'];

            while ($parent !== null) {
                if (
                    !isset($definitions[$parent]) ||
                    isset($seen[$parent]) ||
                    $definitions[$parent]['taxonomy'] !== $term['taxonomy']
                ) {
                    throw new RuntimeException(
                        'Broken parent relationship or cycle at '
                        . $reference . '.'
                    );
                }

                $seen[$parent] = true;
                $parent = $definitions[$parent]['parent_ref'];
            }
        }

        $assignment_count = 0;

        foreach ($backup['records'] as $index => $record) {
            if (!is_array($record['terms'] ?? null)) {
                throw new RuntimeException(
                    'Comic ' . ($index + 1)
                    . ': taxonomy assignments are missing.'
                );
            }

            foreach ($record['terms'] as $taxonomy => $references) {
                if (
                    !in_array($taxonomy, $allowed, true) ||
                    !is_array($references) ||
                    !array_is_list($references)
                ) {
                    throw new RuntimeException(
                        'Comic ' . ($index + 1)
                        . ': invalid taxonomy assignment list.'
                    );
                }

                $seen = [];

                foreach ($references as $reference) {
                    if (
                        !is_string($reference) ||
                        !isset($definitions[$reference]) ||
                        $definitions[$reference]['taxonomy'] !== $taxonomy ||
                        isset($seen[$reference])
                    ) {
                        throw new RuntimeException(
                            'Comic ' . ($index + 1)
                            . ': missing, mismatched, or repeated term reference.'
                        );
                    }

                    $seen[$reference] = true;
                    $assignment_count++;
                }
            }
        }

        return $assignment_count;
    }

    public function validate_owners(
        array $backup
    ): array {
        $source_id = $backup['source_id'] ?? null;

        if (
            !is_string($source_id) ||
            !preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',
                $source_id
            ) ||
            !in_array(
                $backup['scope'] ?? null,
                ['single_user', 'all_users'],
                true
            )
        ) {
            throw new RuntimeException(
                'The backup source identifier or scope is invalid.'
            );
        }

        $owners = [];

        foreach ($backup['owners'] as $index => $owner) {
            if (
                !is_array($owner) ||
                !is_int($owner['source_user_id'] ?? null) ||
                $owner['source_user_id'] < 1 ||
                isset($owners[$owner['source_user_id']]) ||
                !array_key_exists('user_login', $owner) ||
                (
                    $owner['user_login'] !== null &&
                    !is_string($owner['user_login'])
                )
            ) {
                throw new RuntimeException(
                    'Owner reference ' . ($index + 1)
                    . ' is invalid or duplicated.'
                );
            }

            $owners[$owner['source_user_id']] = [
                'source_user_id' => $owner['source_user_id'],
                'user_login' => $owner['user_login'],
                'record_count' => 0,
            ];
        }

        if (
            $backup['scope'] === 'single_user' &&
            count($owners) > 1
        ) {
            throw new RuntimeException(
                'A single-user backup contains multiple owners.'
            );
        }

        $record_ids = [];

        foreach ($backup['records'] as $index => $record) {
            if (
                !is_array($record) ||
                !is_int($record['source_post_id'] ?? null) ||
                $record['source_post_id'] < 1 ||
                isset($record_ids[$record['source_post_id']]) ||
                !is_int($record['source_owner_id'] ?? null) ||
                !isset($owners[$record['source_owner_id']])
            ) {
                throw new RuntimeException(
                    'Comic ' . ($index + 1)
                    . ': invalid record ID, duplicate ID, or unknown owner.'
                );
            }

            $record_ids[$record['source_post_id']] = true;

            $owners[$record['source_owner_id']]['record_count']++;
        }

        return $owners;
    }
    
    public function validate_post_fields(array $backup): int {
        $string_fields = [
            'post_title', 'post_content', 'post_excerpt',
            'post_status', 'post_name', 'post_date',
            'post_date_gmt', 'post_modified', 'post_modified_gmt',
            'post_password', 'comment_status', 'ping_status',
        ];
    
        $allowed_fields = array_merge(
            $string_fields,
            ['post_parent', 'menu_order']
        );
    
        $parents = [];
    
        foreach ($backup['records'] as $index => $record) {
            $context = 'Comic ' . ($index + 1);
            $post = $record['post'] ?? null;
    
            if (
                !is_array($post) ||
                array_diff(array_keys($post), $allowed_fields)
            ) {
                throw new RuntimeException(
                    $context . ': invalid or unexpected post fields.'
                );
            }
    
            foreach ($string_fields as $field) {
                if (
                    !is_string($post[$field] ?? null) ||
                    strpos($post[$field], "\0") !== false
                ) {
                    throw new RuntimeException(
                        $context . ': invalid ' . $field . '.'
                    );
                }
            }
    
            if (
                !is_int($post['post_parent'] ?? null) ||
                $post['post_parent'] < 0 ||
                !is_int($post['menu_order'] ?? null) ||
                $post['menu_order'] < -2147483648 ||
                $post['menu_order'] > 2147483647
            ) {
                throw new RuntimeException(
                    $context . ': invalid parent or menu order.'
                );
            }
    
            if (
                !in_array(
                    $post['post_status'],
                    array_values(get_post_stati()),
                    true
                ) ||
                !in_array(
                    $post['comment_status'],
                    ['open', 'closed'],
                    true
                ) ||
                !in_array(
                    $post['ping_status'],
                    ['open', 'closed'],
                    true
                )
            ) {
                throw new RuntimeException(
                    $context . ': invalid post status or discussion settings.'
                );
            }
    
            foreach ([
                'post_date',
                'post_date_gmt',
                'post_modified',
                'post_modified_gmt',
            ] as $field) {
                $value = $post[$field];
    
                if ($value === '0000-00-00 00:00:00') {
                    continue;
                }
    
                $date = DateTimeImmutable::createFromFormat(
                    '!Y-m-d H:i:s',
                    $value,
                    new DateTimeZone('UTC')
                );
    
                if (
                    !$date ||
                    $date->format('Y-m-d H:i:s') !== $value
                ) {
                    throw new RuntimeException(
                        $context . ': invalid ' . $field . '.'
                    );
                }
            }
    
            $parents[$record['source_post_id']] =
                $post['post_parent'];
        }
    
        foreach ($parents as $source_id => $parent_id) {
            $seen = [$source_id => true];
    
            while ($parent_id !== 0) {
                if (
                    !array_key_exists($parent_id, $parents) ||
                    isset($seen[$parent_id])
                ) {
                    throw new RuntimeException(
                        'Missing parent or parent cycle at comic '
                        . $source_id . '.'
                    );
                }
    
                $seen[$parent_id] = true;
                $parent_id = $parents[$parent_id];
            }
        }
    
        return count($parents);
    }
}
