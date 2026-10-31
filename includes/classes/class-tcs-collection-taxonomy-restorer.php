<?php
defined('ABSPATH') || exit;

class TCS_Collection_Taxonomy_Restorer {
    private $exporter;
    private $definitions = [];
    private $map = [];
    private $created = 0;
    private $reused = 0;

    public function __construct(TCS_Collection_Backup_Exporter $exporter) {
        $this->exporter = $exporter;
    }

    public function restore(array $backup): array {
        if (!is_user_logged_in() || !current_user_can('read')) {
            throw new RuntimeException('Sign in to restore taxonomy terms.');
        }

        $validator = new TCS_Collection_Backup_Validator();
        $validator->validate_metadata($backup);
        $validator->validate_taxonomies($backup);

        $this->definitions = array_column($backup['term_definitions'], null, 'ref');
        $this->map = [];
        $this->created = 0;
        $this->reused = 0;

        foreach (array_keys($this->definitions) as $reference) {
            $this->resolve($reference);
        }

        return [
            'term_map' => $this->map,
            'created' => $this->created,
            'reused' => $this->reused,
        ];
    }

    private function resolve(string $reference): int {
        if (isset($this->map[$reference])) {
            return $this->map[$reference];
        }

        $definition = $this->definitions[$reference];
        $taxonomy = $definition['taxonomy'];
        $parent = $definition['parent_ref'] === null
            ? 0
            : $this->resolve($definition['parent_ref']);

        $term = get_term_by('slug', $definition['slug'], $taxonomy);
        $created = false;

        if (!$term) {
            $object = get_taxonomy($taxonomy);

            if (!$object || !current_user_can($object->cap->manage_terms)) {
                throw new RuntimeException(
                    'An administrator must create the missing term: ' . $reference
                );
            }

            if ($definition['meta'] && !current_user_can('manage_options')) {
                throw new RuntimeException(
                    'An administrator must restore term metadata: ' . $reference
                );
            }

            $result = wp_insert_term(
                wp_slash($definition['name']),
                $taxonomy,
                wp_slash([
                    'slug' => $definition['slug'],
                    'description' => $definition['description'],
                    'parent' => $parent,
                ])
            );

            if (is_wp_error($result)) {
                // Another request might have created the term first.
                if ($result->get_error_code() !== 'term_exists') {
                    throw new RuntimeException($result->get_error_message());
                }
                $term_id = (int) $result->get_error_data();
            } else {
                $term_id = (int) $result['term_id'];
                $created = true;
            }

            $term = get_term($term_id, $taxonomy, OBJECT, 'raw');
        }

        try {
            if (
                !$term || is_wp_error($term) ||
                $term->taxonomy !== $taxonomy ||
                $term->slug !== $definition['slug'] ||
                $term->name !== $definition['name'] ||
                $term->description !== $definition['description'] ||
                (int) $term->parent !== $parent
            ) {
                throw new RuntimeException('Term data conflicts with the backup: ' . $reference);
            }

            $this->restore_meta((int) $term->term_id, $definition['meta'], $created);
        } catch (Throwable $error) {
            if ($created) {
                $deleted = wp_delete_term($term_id, $taxonomy);
                if (!$deleted || is_wp_error($deleted)) {
                    throw new RuntimeException(
                        'Restore failed; the new term could not be removed: ' . $reference,
                        0,
                        $error
                    );
                }
            }
            throw $error;
        }

        $term_id = (int) $term->term_id;
        $this->map[$reference] = $term_id;
        if ($created) {
            $this->created++;
        } else {
            $this->reused++;
        }
        return $term_id;
    }

    private function restore_meta(int $term_id, array $rows, bool $created): void {
        global $wpdb;

        $existing = $this->exporter->read_meta($term_id, 'term');

        if (!$created || $existing) {
            if ($this->metadata_signature($existing) !== $this->metadata_signature($rows)) {
                throw new RuntimeException('Term metadata conflicts for term ' . $term_id . '.');
            }
            return;
        }

        foreach ($rows as $row) {
            // Preserve the raw database strings; do not unserialize backup input.
            $inserted = $wpdb->insert(
                $wpdb->termmeta,
                [
                    'term_id' => $term_id,
                    'meta_key' => base64_decode($row['key_b64'], true),
                    'meta_value' => base64_decode($row['value_b64'], true),
                ],
                ['%d', '%s', '%s']
            );
            if ($inserted !== 1) {
                throw new RuntimeException('Term metadata could not be restored.');
            }
        }

        wp_cache_delete($term_id, 'term_meta');

        if ($this->metadata_signature($this->exporter->read_meta($term_id, 'term')) !== $this->metadata_signature($rows)) {
            throw new RuntimeException('Restored term metadata did not match the backup.');
        }
    }

    private function metadata_signature(array $rows): array {
        $values = array_map(static function (array $row): string {
            return base64_encode(base64_decode($row['key_b64'], true))
                . ':' . base64_encode(base64_decode($row['value_b64'], true));
        }, $rows);
        sort($values, SORT_STRING);
        return $values;
    }
}