<?php
defined('ABSPATH') || exit;

class TCS_Collection_Import_Controls {
    private $service;

    public function __construct(
        TCS_Collection_Transfer_Service $service
    ) {
        $this->service = $service;
    }

    public function enqueue(string $hook): void {
        if ($hook !== 'tools_page_tcs-collection-backup') {
            return;
        }

        wp_enqueue_script(
            'tcs-collection-transfer',
            COMICBOOKS_PLUGIN_URL . 'js/collection-transfer.js',
            [],
            filemtime(
                COMICBOOKS_PLUGIN_DIR . 'js/collection-transfer.js'
            ),
            true
        );
    }

    public function render(): void {
        if (!is_user_logged_in() || !current_user_can('read')) {
            return;
        }

        $user_id = get_current_user_id();

        $progress = get_option(
            'tcs_collection_transfer_progress_' . $user_id,
            false
        );

        $pending = get_option(
            'tcs_collection_transfer_pending_' . $user_id,
            false
        );

        $token = '';
        $summary = null;

        if (
            is_array($progress) &&
            is_string($progress['token'] ?? null)
        ) {
            try {
                $saved = $this->service->read_import_progress(
                    $progress['token']
                );

                if (
                    in_array(
                        $saved['state'],
                        ['ready', 'running', 'paused'],
                        true
                    )
                ) {
                    $token = $saved['token'];
                    $summary = $this->summary($saved);
                }
            } catch (RuntimeException $error) {
                $saved = null;
            }
        }

        if (
            $summary === null &&
            is_array($pending) &&
            is_string($pending['token'] ?? null)
        ) {
            try {
                $summary = $this->status($pending['token']);
                $token = $pending['token'];
            } catch (RuntimeException $error) {
                return;
            }
        }

        if (
            $summary === null &&
            isset($saved) &&
            is_array($saved)
        ) {
            $token = $saved['token'];
            $summary = $this->summary($saved);
        }

        if (
            $summary === null ||
            $summary['state'] === 'cancelled'
        ) {
            return;
        }

        if (
            $summary['state'] === 'complete' &&
            get_user_meta(
                $user_id,
                '_tcs_collection_import_dismissed',
                true
            ) === $token
        ) {
            return;
        }
        ?>
        <style>
            #tcs-import-controls [hidden],
            #tcs-collection-owner-mapping[hidden] {
                display: none !important;
            }
        </style>
        <section
            id="tcs-import-controls"
            data-url="<?php
                echo esc_url(admin_url('admin-ajax.php'));
            ?>"
            data-token="<?php echo esc_attr($token); ?>"
            data-nonce="<?php
                echo esc_attr(wp_create_nonce(
                    'tcs_collection_import_' . $token
                ));
            ?>"
            data-summary="<?php
                echo esc_attr(wp_json_encode($summary));
            ?>"
        >
            <h2>Restore collection backup</h2>

            <p data-import-instructions>
                Start will import into the saved destination
                accounts. Existing matching comics are skipped.
            </p>

            <p data-import-instructions>
                Keep this page open while importing.
                If you close it, return here and choose Continue.
            </p>

            <p data-import-instructions>
                Cancel stops further work and keeps records
                already created.
            </p>

            <p
                data-status
                role="status"
                aria-live="polite"
            >
                Import controls are loading.
            </p>

            <progress
                data-progress
                max="100"
                value="0"
                aria-label="Import progress"
                style="width:100%;max-width:600px"
            ></progress>

            <p data-import-instructions>
                Preparation: <span data-terms>0</span>
            </p>

            <p>
                Comics completed: <span data-completed>0</span>
            </p>

            <p>
                Created: <strong data-created>0</strong>
                &nbsp;
                Skipped: <strong data-skipped>0</strong>
                &nbsp;
                Failed: <strong data-failed>0</strong>
            </p>

            <p data-error role="alert" hidden></p>

            <p>
                <button
                    type="button"
                    class="button button-primary"
                    data-start
                    disabled
                >Start import</button>

                <button
                    type="button"
                    class="button"
                    data-cancel
                    hidden
                    disabled
                >Cancel import</button>

                <button
                    type="button"
                    class="button button-primary"
                    data-done
                    hidden
                    disabled
                >Done</button>
            </p>

            <noscript>
                <p>
                    Enable JavaScript to use the import controls.
                </p>
            </noscript>
        </section>
        <?php
    }

    public function handle_request(): void {
        if (!is_user_logged_in() || !current_user_can('read')) {
            wp_send_json_error(
                ['message' => 'Sign in to manage your import.'],
                403
            );
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_send_json_error(
                ['message' => 'Use a POST request.'],
                405
            );
        }

        $token = isset($_POST['job_token'])
            && is_string($_POST['job_token'])
            ? wp_unslash($_POST['job_token'])
            : '';

        $command = isset($_POST['command'])
            && is_string($_POST['command'])
            ? wp_unslash($_POST['command'])
            : '';

        if (!preg_match('/^[a-f0-9]{32}$/D', $token)) {
            wp_send_json_error(
                ['message' => 'The import token is invalid.'],
                400
            );
        }

        if (
            !is_string($_POST['nonce'] ?? null) ||
            !check_ajax_referer(
                'tcs_collection_import_' . $token,
                'nonce',
                false
            )
        ) {
            wp_send_json_error(
                [
                    'message' =>
                        'Your security check expired. Reload this page.',
                ],
                403
            );
        }

        try {
            switch ($command) {
                case 'start':
                    $result = $this->service->begin_import_job(
                        $token
                    );
                    break;

                case 'batch':
                    $result = $this->service->process_import_batch(
                        $token
                    );
                    break;

                case 'resume':
                    $result = $this->service->resume_import_job(
                        $token
                    );
                    break;

                case 'cancel':
                    $result = $this->service->cancel_import_job(
                        $token
                    );
                    break;

                case 'status':
                    $data = $this->status($token);
                    break;
                
                case 'done':
                    $result = $this->service->dismiss_import_job(
                        $token
                );
                break;

                default:
                    throw new RuntimeException(
                        'The import command is invalid.'
                    );
            }

            if (isset($result)) {
                $data = $this->summary($result);
            }
        } catch (RuntimeException $error) {
            wp_send_json_error(
                [
                    'message' => sanitize_text_field(
                        $error->getMessage()
                    ),
                ],
                $error->getCode() === 409 ? 409 : 400
            );
        } catch (Throwable $error) {
            error_log(
                'Collection import request failed: '
                . $error->getMessage()
            );

            wp_send_json_error(
                [
                    'message' =>
                        'The import request failed. Reload and continue from saved progress.',
                ],
                500
            );
        }

        wp_send_json_success($data);
    }

    private function status(string $token): array {
        try {
            return $this->summary(
                $this->service->read_import_progress($token)
            );
        } catch (RuntimeException $error) {
            $job = $this->service->read_pending($token);

            if (
                !is_array($job['owner_map'] ?? null) ||
                !in_array(
                    $job['state'] ?? '',
                    ['mapped', 'running'],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Save the destination mapping first.'
                );
            }

            return $this->summary([
                'state' => 'mapped',
                'phase' => 'terms',
                'revision' => 0,
                'term_cursor' => 0,
                'record_cursor' => 0,
                'results' => [],
                'last_error' => '',
                'total_terms' => count(
                    $job['backup']['term_definitions']
                ),
                'total_records' => count(
                    $job['backup']['records']
                ),
            ]);
        }
    }

    private function summary(array $progress): array {
        $counts = [
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($progress['results'] as $result) {
            if (isset($counts[$result['status']])) {
                $counts[$result['status']]++;
            }
        }

        return array_merge($counts, [
            'state' => $progress['state'],
            'phase' => $progress['phase'],
            'revision' => $progress['revision'],
            'term_cursor' => $progress['term_cursor'],
            'total_terms' => $progress['total_terms'],
            'total_records' => $progress['total_records'],
            'message' => $progress['last_error'],
        ]);
    }
}