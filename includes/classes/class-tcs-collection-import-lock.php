<?php
defined('ABSPATH') || exit;

class TCS_Collection_Import_Lock {
    private $name;
    private $held = false;

    public function __construct(string $scope = '') {
        global $wpdb;
    
        $identity = DB_NAME . ':' . $wpdb->prefix;
    
        if ($scope !== '') {
            $identity .= ':' . $scope;
        }
    
        $this->name = 'tcs_import_' . substr(
            hash('sha256', $identity),
            0,
            40
        );
    }

    public function run(callable $work) {
        global $wpdb;

        $nested = $wpdb->get_var($wpdb->prepare(
            'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()',
            $this->name
        ));

        if ($wpdb->last_error !== '') {
            throw new RuntimeException(
                'The import lock could not be checked.'
            );
        }

        if ($this->held || (string) $nested === '1') {
            throw new RuntimeException(
                'Import locks cannot be nested.'
            );
        }

        $acquired = $wpdb->get_var($wpdb->prepare(
            'SELECT GET_LOCK(%s, 0)',
            $this->name
        ));

        if (
            $wpdb->last_error !== '' ||
            !in_array(
                (string) $acquired,
                ['0', '1'],
                true
            )
        ) {
            throw new RuntimeException(
                'The database could not acquire an import lock.'
            );
        }

        if ((string) $acquired === '0') {
            throw new RuntimeException(
                'Another collection import is running. Try again shortly.',
                409
            );
        }

        $this->held = true;
        $failure = null;
        $result = null;

        try {
            $this->assert_owned();

            $result = $work($this);

            $this->assert_owned();
        } catch (Throwable $error) {
            $failure = $error;
        } finally {
            $this->held = false;

            try {
                $released = $wpdb->get_var($wpdb->prepare(
                    'SELECT RELEASE_LOCK(%s)',
                    $this->name
                ));

                if (
                    $wpdb->last_error !== '' ||
                    (string) $released !== '1'
                ) {
                    throw new RuntimeException(
                        'The import lock could not be released.'
                    );
                }
            } catch (Throwable $release_error) {
                if ($failure === null) {
                    $failure = $release_error;
                } else {
                    error_log(
                        'Collection import lock cleanup failed: '
                        . $release_error->getMessage()
                    );
                }
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $result;
    }

    public function assert_owned(): void {
        global $wpdb;

        if (!$this->held) {
            throw new RuntimeException(
                'The import lock is not held.'
            );
        }

        $owned = $wpdb->get_var($wpdb->prepare(
            'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()',
            $this->name
        ));

        if (
            $wpdb->last_error !== '' ||
            (string) $owned !== '1'
        ) {
            throw new RuntimeException(
                'The database connection lost the import lock. Stop and retry.'
            );
        }
    }
}